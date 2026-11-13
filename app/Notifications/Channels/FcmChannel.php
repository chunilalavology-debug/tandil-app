<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Push\FcmPushService;
use Illuminate\Notifications\Notification;

class FcmChannel
{
    public function __construct(
        private readonly FcmPushService $fcm
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        if (! $this->fcm->isEnabled()) {
            return;
        }

        $payload = $this->resolvePayload($notifiable, $notification);
        if ($payload === null) {
            return;
        }

        $this->fcm->sendToUser(
            $notifiable,
            $payload['title'],
            $payload['body'],
            $payload['data']
        );
    }

    /**
     * @return array{title:string,body:string,data:array<string,mixed>}|null
     */
    private function resolvePayload(User $notifiable, Notification $notification): ?array
    {
        if (method_exists($notification, 'toFcm')) {
            $custom = $notification->toFcm($notifiable);
            if (is_array($custom)
                && filled($custom['title'] ?? null)
                && filled($custom['body'] ?? null)
            ) {
                return [
                    'title' => (string) $custom['title'],
                    'body' => (string) $custom['body'],
                    'data' => is_array($custom['data'] ?? null) ? $custom['data'] : [],
                ];
            }
        }

        if (! method_exists($notification, 'toArray')) {
            return null;
        }

        $array = $notification->toArray($notifiable);
        if (! is_array($array)) {
            return null;
        }

        $title = (string) ($array['title'] ?? '');
        $body = (string) ($array['message'] ?? $array['body'] ?? '');
        if ($title === '' || $body === '') {
            return null;
        }

        $meta = is_array($array['meta'] ?? null) ? $array['meta'] : [];
        $eventType = (string) ($meta['type'] ?? $array['type'] ?? 'notification');

        $data = [
            'type' => $eventType,
            'title' => $title,
            'message' => $body,
            'action_url' => (string) ($array['action_url'] ?? ($meta['action_url'] ?? '')),
            'order_id' => (string) ($array['order_id'] ?? ($meta['order_id'] ?? '')),
            'visit_id' => (string) ($array['visit_id'] ?? ($meta['visit_id'] ?? '')),
            'notification_type' => $eventType,
            'audience_role' => (string) ($array['audience_role'] ?? ''),
        ];

        foreach (['payment_status', 'order_status', 'order_status_label', 'booking_date', 'booking_slot', 'subscription_id'] as $key) {
            $value = $array[$key] ?? ($meta[$key] ?? null);
            if (is_scalar($value) && (string) $value !== '') {
                $data[$key] = (string) $value;
            }
        }

        return [
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];
    }
}
