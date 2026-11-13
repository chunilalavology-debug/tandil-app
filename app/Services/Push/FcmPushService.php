<?php

namespace App\Services\Push;

use App\Models\DevicePushToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging sender.
 *
 * Always includes both `notification` (system tray when app is background/killed)
 * and `data` (deep-link payload). High priority for Android + APNs.
 */
class FcmPushService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{sent:int, failed:int, skipped:bool, driver:string}
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): array
    {
        if (! $this->isEnabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'driver' => 'disabled'];
        }

        $tokens = DevicePushToken::query()
            ->where('user_id', $user->id)
            ->pluck('token')
            ->filter(fn ($t) => is_string($t) && $t !== '')
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'driver' => $this->driver()];
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  Collection<int, string>|array<int, string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array{sent:int, failed:int, skipped:bool, driver:string}
     */
    public function sendToTokens(Collection|array $tokens, string $title, string $body, array $data = []): array
    {
        $tokenList = collect($tokens)
            ->filter(fn ($t) => is_string($t) && trim($t) !== '')
            ->map(fn ($t) => trim($t))
            ->unique()
            ->values();

        if ($tokenList->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'driver' => $this->driver()];
        }

        $driver = $this->driver();
        $payloadData = $this->stringifyData($data);

        if ($driver === 'log') {
            Log::info('fcm.push.log', [
                'title' => $title,
                'body' => $body,
                'token_count' => $tokenList->count(),
                'data' => $payloadData,
            ]);

            DevicePushToken::query()
                ->whereIn('token', $tokenList->all())
                ->update(['last_used_at' => now()]);

            return ['sent' => $tokenList->count(), 'failed' => 0, 'skipped' => false, 'driver' => 'log'];
        }

        $sent = 0;
        $failed = 0;

        foreach ($tokenList as $token) {
            $ok = $driver === 'http_v1'
                ? $this->sendHttpV1($token, $title, $body, $payloadData)
                : $this->sendLegacy($token, $title, $body, $payloadData);

            if ($ok) {
                $sent++;
                DevicePushToken::query()->where('token', $token)->update(['last_used_at' => now()]);
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => false, 'driver' => $driver];
    }

    public function isEnabled(): bool
    {
        if (! (bool) config('services.fcm.enabled', true)) {
            return false;
        }

        try {
            return Setting::get('push_notifications_enabled', '1') === '1';
        } catch (\Throwable) {
            return true;
        }
    }

    public function driver(): string
    {
        $configured = (string) config('services.fcm.driver', 'auto');

        if (in_array($configured, ['log', 'legacy', 'http_v1'], true)) {
            return $configured;
        }

        if ($this->hasHttpV1Credentials()) {
            return 'http_v1';
        }

        if (filled(config('services.fcm.server_key'))) {
            return 'legacy';
        }

        return 'log';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value ? '1' : '0';
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = (string) ($value ?? '');
            } else {
                $out[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function sendLegacy(string $token, string $title, string $body, array $data): bool
    {
        $serverKey = (string) config('services.fcm.server_key');
        if ($serverKey === '') {
            return false;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'Authorization' => 'key='.$serverKey,
                    'Content-Type' => 'application/json',
                ])
                ->post((string) config('services.fcm.legacy_url', 'https://fcm.googleapis.com/fcm/send'), [
                    'to' => $token,
                    'priority' => 'high',
                    'content_available' => true,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => $data,
                    'android' => [
                        'priority' => 'high',
                    ],
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                        'payload' => [
                            'aps' => [
                                'content-available' => 1,
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('fcm.legacy.http_error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $json = $response->json();
            if (is_array($json) && (int) ($json['failure'] ?? 0) > 0) {
                $this->pruneInvalidTokensFromLegacy($json, $token);

                return (int) ($json['success'] ?? 0) > 0;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('fcm.legacy.exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function pruneInvalidTokensFromLegacy(array $json, string $token): void
    {
        $results = $json['results'] ?? null;
        if (! is_array($results) || $results === []) {
            return;
        }

        $error = (string) ($results[0]['error'] ?? '');
        if (in_array($error, ['NotRegistered', 'InvalidRegistration'], true)) {
            DevicePushToken::query()->where('token', $token)->delete();
        }
    }

    /**
     * @param  array<string, string>  $data
     */
    private function sendHttpV1(string $token, string $title, string $body, array $data): bool
    {
        $projectId = (string) config('services.fcm.project_id');
        $accessToken = $this->googleAccessToken();
        if ($projectId === '' || $accessToken === null) {
            return false;
        }

        $url = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            rawurlencode($projectId)
        );

        try {
            $response = Http::timeout(8)
                ->withToken($accessToken)
                ->post($url, [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $data,
                        'android' => [
                            'priority' => 'HIGH',
                            'notification' => [
                                'sound' => 'default',
                                'channel_id' => (string) config('services.fcm.android_channel_id', 'tandil_default'),
                            ],
                        ],
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10',
                                'apns-push-type' => 'alert',
                            ],
                            'payload' => [
                                'aps' => [
                                    'sound' => 'default',
                                    'content-available' => 1,
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->status() === 404 || $response->status() === 400) {
                $errorCode = (string) data_get($response->json(), 'error.status', '');
                if (in_array($errorCode, ['NOT_FOUND', 'INVALID_ARGUMENT'], true)) {
                    DevicePushToken::query()->where('token', $token)->delete();
                }
            }

            if (! $response->successful()) {
                Log::warning('fcm.http_v1.error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('fcm.http_v1.exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function hasHttpV1Credentials(): bool
    {
        return filled(config('services.fcm.project_id'))
            && filled(config('services.fcm.client_email'))
            && filled(config('services.fcm.private_key'));
    }

    private function googleAccessToken(): ?string
    {
        if (! $this->hasHttpV1Credentials()) {
            return null;
        }

        $cacheKey = 'fcm.google_access_token';
        $cached = cache()->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $now = time();
            $clientEmail = (string) config('services.fcm.client_email');
            $privateKey = str_replace('\\n', "\n", (string) config('services.fcm.private_key'));

            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '');
            $claim = $this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]) ?: '');

            $unsigned = $header.'.'.$claim;
            $signature = '';
            $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            if (! $ok) {
                return null;
            }

            $jwt = $unsigned.'.'.$this->base64UrlEncode($signature);

            $response = Http::asForm()->timeout(8)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                Log::warning('fcm.oauth.error', ['body' => $response->body()]);

                return null;
            }

            $accessToken = (string) $response->json('access_token');
            $expiresIn = max(60, (int) $response->json('expires_in', 3600) - 60);
            if ($accessToken !== '') {
                cache()->put($cacheKey, $accessToken, $expiresIn);
            }

            return $accessToken !== '' ? $accessToken : null;
        } catch (\Throwable $e) {
            Log::warning('fcm.oauth.exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
