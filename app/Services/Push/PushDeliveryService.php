<?php

namespace App\Services\Push;

use App\Models\DevicePushToken;
use App\Models\Setting;
use App\Models\User;

/**
 * Routes each device to the provider that can wake it:
 * iOS → Apple Push (APNs). Android and other tokens → Firebase (when configured).
 */
class PushDeliveryService
{
    public function __construct(
        private readonly ApnsPushService $apns,
        private readonly FcmPushService $fcm
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (! $this->pushesEnabled()) {
            return;
        }

        $tokens = DevicePushToken::query()->where('user_id', $user->id)->get();
        if ($tokens->isEmpty()) {
            return;
        }

        $ios = [];
        $other = [];
        foreach ($tokens as $row) {
            if (! $row instanceof DevicePushToken || ! is_string($row->token) || $row->token === '') {
                continue;
            }
            if ($this->isIosToken($row)) {
                $ios[] = $row->token;
            } else {
                $other[] = $row->token;
            }
        }

        if ($ios !== []) {
            $this->apns->sendToTokens($ios, $title, $body, $data);
        }

        if ($other !== [] && $this->fcm->isEnabled()) {
            $this->fcm->sendToTokens($other, $title, $body, $data);
        }
    }

    private function pushesEnabled(): bool
    {
        try {
            return Setting::get('push_notifications_enabled', '1') === '1';
        } catch (\Throwable) {
            return true;
        }
    }

    private function isIosToken(DevicePushToken $row): bool
    {
        $platform = strtolower((string) $row->platform);
        if ($platform === 'ios') {
            return true;
        }
        if (in_array($platform, ['android', 'web'], true)) {
            return false;
        }

        $hex = strtolower(str_replace([' ', '<', '>'], '', $row->token));

        return (bool) preg_match('/^[a-f0-9]{64}$/', $hex);
    }
}
