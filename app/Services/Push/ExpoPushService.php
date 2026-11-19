<?php

namespace App\Services\Push;

use App\Models\DevicePushToken;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Expo Push API — React Native / Expo apps using ExponentPushToken[...].
 *
 * @see https://docs.expo.dev/push-notifications/sending-notifications/
 */
class ExpoPushService
{
    public const DEFAULT_URL = 'https://exp.host/--/api/v2/push/send';

  /**
   * @param  array<string, mixed>  $data
   * @return array{sent:int, failed:int, skipped:bool, driver:string}
   */
    public function sendToTokens(Collection|array $tokens, string $title, string $body, array $data = []): array
    {
        $tokenList = collect($tokens)
            ->filter(fn ($t) => is_string($t) && trim($t) !== '' && self::isExpoToken($t))
            ->map(fn ($t) => trim($t))
            ->unique()
            ->values();

        if ($tokenList->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'driver' => $this->driver()];
        }

        if (! $this->isEnabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'driver' => 'disabled'];
        }

        $driver = $this->driver();
        $payloadData = $this->stringifyData($data);

        if ($driver === 'log') {
            Log::info('expo.push.log', [
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

        $channelId = trim((string) config('services.expo.android_channel_id', ''));
        $messages = [];
        foreach ($tokenList as $token) {
            $message = [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'priority' => 'high',
                'data' => $payloadData,
            ];
            if ($channelId !== '') {
                $message['channelId'] = $channelId;
            }
            $messages[] = $message;
        }

        $sent = 0;
        $failed = 0;

        foreach (array_chunk($messages, 100) as $chunk) {
            $result = $this->postChunk($chunk);
            $sent += $result['sent'];
            $failed += $result['failed'];
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => false, 'driver' => 'http'];
    }

    public static function isExpoToken(string $token): bool
    {
        $t = trim($token);

        return str_starts_with($t, 'ExponentPushToken[')
            || str_starts_with($t, 'ExpoPushToken[');
    }

    public function isEnabled(): bool
    {
        if (! (bool) config('services.expo.enabled', true)) {
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
        $configured = (string) config('services.expo.driver', 'auto');

        if ($configured === 'log') {
            return 'log';
        }

        if ($configured === 'http') {
            return 'http';
        }

        return 'http';
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @return array{sent:int, failed:int}
     */
    private function postChunk(array $chunk): array
    {
        $url = (string) config('services.expo.url', self::DEFAULT_URL);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $accessToken = trim((string) config('services.expo.access_token', ''));
        if ($accessToken !== '') {
            $headers['Authorization'] = 'Bearer '.$accessToken;
        }

        try {
            $response = Http::timeout(12)
                ->withHeaders($headers)
                ->post($url, $chunk);

            if (! $response->successful()) {
                Log::warning('expo.push.http_failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['sent' => 0, 'failed' => count($chunk)];
            }

            $json = $response->json();
            $rows = is_array($json['data'] ?? null) ? $json['data'] : [];

            $sent = 0;
            $failed = 0;

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    $failed++;

                    continue;
                }

                $status = (string) ($row['status'] ?? '');
                $token = isset($chunk[$index]['to']) ? (string) $chunk[$index]['to'] : null;

                if ($status === 'ok') {
                    $sent++;
                    if ($token) {
                        DevicePushToken::query()->where('token', $token)->update(['last_used_at' => now()]);
                    }
                } else {
                    $failed++;
                    $detailsError = is_array($row['details'] ?? null)
                        ? (string) ($row['details']['error'] ?? '')
                        : '';
                    if ($token && in_array($detailsError, ['DeviceNotRegistered', 'InvalidCredentials'], true)) {
                        DevicePushToken::query()->where('token', $token)->delete();
                    }
                }
            }

            if ($rows === [] && count($chunk) > 0) {
                $sent = count($chunk);
                $tokens = collect($chunk)->pluck('to')->filter()->all();
                if ($tokens !== []) {
                    DevicePushToken::query()->whereIn('token', $tokens)->update(['last_used_at' => now()]);
                }
            }

            return ['sent' => $sent, 'failed' => $failed];
        } catch (\Throwable $e) {
            Log::warning('expo.push.exception', ['message' => $e->getMessage()]);

            return ['sent' => 0, 'failed' => count($chunk)];
        }
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
}