<?php

namespace App\Services\Push;

use App\Models\DevicePushToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Apple Push Notification service (HTTP/2).
 *
 * iOS alerts are sent here, not through Firebase. The payload always includes
 * aps.alert so the banner shows when the app is backgrounded or closed.
 */
class ApnsPushService
{
    /**
     * @param  Collection<int, string>|array<int, string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array{sent:int, failed:int, skipped:bool, driver:string}
     */
    public function sendToTokens(Collection|array $tokens, string $title, string $body, array $data = []): array
    {
        if (! $this->isEnabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'driver' => 'disabled'];
        }

        $tokenList = collect($tokens)
            ->filter(fn ($t) => is_string($t) && trim($t) !== '')
            ->map(fn ($t) => $this->normalizeToken($t))
            ->filter(fn ($t) => $t !== '')
            ->unique()
            ->values();

        if ($tokenList->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'driver' => $this->driver()];
        }

        $driver = $this->driver();
        $custom = $this->stringifyData($data);

        if ($driver === 'log') {
            Log::info('apns.push.log', [
                'title' => $title,
                'body' => $body,
                'token_count' => $tokenList->count(),
                'data' => $custom,
            ]);

            DevicePushToken::query()
                ->whereIn('token', $tokenList->all())
                ->update(['last_used_at' => now()]);

            return ['sent' => $tokenList->count(), 'failed' => 0, 'skipped' => false, 'driver' => 'log'];
        }

        $jwt = $this->bearerToken();
        if ($jwt === null) {
            Log::warning('apns.push.missing_credentials');

            return ['sent' => 0, 'failed' => $tokenList->count(), 'skipped' => false, 'driver' => 'http'];
        }

        $sent = 0;
        $failed = 0;
        $bundleId = (string) config('services.apns.bundle_id');

        foreach ($tokenList as $token) {
            $ok = $this->sendOne($token, $title, $body, $custom, $jwt, $bundleId);
            if ($ok) {
                $sent++;
                DevicePushToken::query()->where('token', $token)->update(['last_used_at' => now()]);
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => false, 'driver' => 'http'];
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.apns.enabled', true);
    }

    public function driver(): string
    {
        $configured = (string) config('services.apns.driver', 'auto');

        if (in_array($configured, ['log', 'http'], true)) {
            return $configured;
        }

        if ($this->hasCredentials()) {
            return 'http';
        }

        return 'log';
    }

    /**
     * @param  array<string, string>  $data
     */
    private function sendOne(string $token, string $title, string $body, array $data, string $jwt, string $bundleId): bool
    {
        $url = rtrim((string) config('services.apns.endpoint'), '/').'/3/device/'.$token;

        $payload = array_merge($data, [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'sound' => 'default',
                'mutable-content' => 1,
            ],
        ]);

        try {
            $response = Http::withOptions([
                'version' => 2.0,
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                ],
            ])->withHeaders([
                'authorization' => 'bearer '.$jwt,
                'apns-topic' => $bundleId,
                'apns-push-type' => 'alert',
                'apns-priority' => '10',
            ])->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 'application/json')
                ->send('POST', $url);

            if ($response->successful()) {
                return true;
            }

            $reason = (string) $response->json('reason');
            if ($response->status() === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
                DevicePushToken::query()->where('token', $token)->delete();
            }

            Log::warning('apns.push.rejected', [
                'status' => $response->status(),
                'reason' => $reason,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('apns.push.exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function bearerToken(): ?string
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $keyId = (string) config('services.apns.key_id');
        $cacheKey = 'apns.jwt.'.$keyId;
        $cached = cache()->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $pem = str_replace('\\n', "\n", (string) config('services.apns.private_key'));
        $teamId = (string) config('services.apns.team_id');
        $privateKey = openssl_pkey_get_private($pem);
        if ($privateKey === false) {
            Log::warning('apns.jwt.invalid_key');

            return null;
        }

        $header = $this->base64Url((string) json_encode(['alg' => 'ES256', 'kid' => $keyId]));
        $claims = $this->base64Url((string) json_encode(['iss' => $teamId, 'iat' => time()]));
        $unsigned = $header.'.'.$claims;
        $der = '';
        if (! openssl_sign($unsigned, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $jwt = $unsigned.'.'.$this->base64Url($this->derSignatureToRaw($der));
        cache()->put($cacheKey, $jwt, 3000);

        return $jwt;
    }

    private function hasCredentials(): bool
    {
        return filled(config('services.apns.key_id'))
            && filled(config('services.apns.team_id'))
            && filled(config('services.apns.bundle_id'))
            && filled(config('services.apns.private_key'));
    }

    private function normalizeToken(string $token): string
    {
        return strtolower(str_replace([' ', '<', '>'], '', trim($token)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || $key === '' || $key === 'aps') {
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

    private function derSignatureToRaw(string $der): string
    {
        $offset = 0;
        if ($der === '' || ord($der[$offset++]) !== 0x30) {
            return $der;
        }
        $this->readDerLength($der, $offset);

        $r = $this->readDerInteger($der, $offset);
        $s = $this->readDerInteger($der, $offset);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            .str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private function readDerInteger(string $der, int &$offset): string
    {
        if (! isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
            return '';
        }
        $offset++;
        $length = $this->readDerLength($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }

    private function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++] ?? "\x00");
        if (($length & 0x80) === 0) {
            return $length;
        }

        $byteCount = $length & 0x7F;
        $length = 0;
        for ($i = 0; $i < $byteCount; $i++) {
            $length = ($length << 8) | ord($der[$offset++] ?? "\x00");
        }

        return $length;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
