<?php

namespace App\Support;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Validation\Rule;

final class DevicePushTokenPersistence
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function upsertForUser(User $user, array $payload): ?DevicePushToken
    {
        $token = self::resolveToken($payload);
        if ($token === null) {
            return null;
        }

        $platform = self::resolvePlatform($payload);

        DevicePushToken::query()->where('token', $token)->where('user_id', '!=', $user->id)->delete();

        return DevicePushToken::query()->updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'device_id' => self::optionalString($payload, 'device_id', 191),
                'app_version' => self::optionalString($payload, 'app_version', 64),
                'locale' => self::optionalString($payload, 'locale', 16),
                'last_used_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function resolveToken(array $payload): ?string
    {
        foreach (['token', 'expo_push_token', 'push_token', 'fcm_token', 'deviceToken', 'pushToken', 'expoPushToken'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && strlen(trim($value)) >= 20) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function resolvePlatform(array $payload): string
    {
        $raw = $payload['platform'] ?? 'unknown';
        if (! is_string($raw) || $raw === '') {
            return 'unknown';
        }

        $platform = strtolower(trim($raw));

        return in_array($platform, ['android', 'ios', 'web', 'unknown'], true) ? $platform : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function optionalString(array $payload, string $key, int $max): ?string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function optionalRegistrationRules(): array
    {
        return [
            'token' => ['nullable', 'string', 'min:20', 'max:512'],
            'expo_push_token' => ['nullable', 'string', 'min:20', 'max:512'],
            'push_token' => ['nullable', 'string', 'min:20', 'max:512'],
            'fcm_token' => ['nullable', 'string', 'min:20', 'max:512'],
            'platform' => ['nullable', 'string', Rule::in(['android', 'ios', 'web', 'unknown'])],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}