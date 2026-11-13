<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use App\Models\Visit;
use App\Notifications\AdminNotification;
use Illuminate\Support\Facades\Log;

/**
 * Client-facing lifecycle pushes: payment updates, bookings, order status changes.
 */
final class OrderClientLifecycleNotifier
{
    public static function notifyPaymentConfirmed(Order $order): void
    {
        $client = self::client($order);
        if (! $client) {
            return;
        }

        $fulfillment = self::fulfillment($order);
        $message = match ($fulfillment) {
            OrderFulfillmentType::PRODUCT => "Your order #{$order->id} payment is confirmed (AED {$order->total_amount}). The supplier will prepare and deliver your items.",
            OrderFulfillmentType::PLATFORM => "Your order #{$order->id} payment is confirmed (AED {$order->total_amount}). Thank you for your purchase.",
            default => "Your order #{$order->id} payment has been confirmed. Amount: AED {$order->total_amount}. Our team will assign a supervisor shortly.",
        };

        self::safeNotify($client, 'Order Payment Confirmed', $message, [
            'type' => 'order_payment_confirmed',
            'fulfillment_type' => $fulfillment,
            'order_id' => $order->id,
            'payment_status' => $order->payment_status,
            'track_endpoint' => '/api/orders/'.$order->id.'/track',
            'action_url' => '/orders/'.$order->id.'/track',
        ]);
    }

    public static function notifyPaymentUpdated(Order $order, string $previousStatus): void
    {
        $client = self::client($order);
        if (! $client) {
            return;
        }

        $status = strtolower((string) ($order->payment_status ?? ''));
        if ($status === '' || $status === strtolower($previousStatus)) {
            return;
        }

        if ($status === 'paid') {
            self::notifyPaymentConfirmed($order);

            return;
        }

        $label = ucfirst($status);
        self::safeNotify($client, 'Payment Update', "Payment status for order #{$order->id} is now {$label}.", [
            'type' => 'order_payment_updated',
            'order_id' => $order->id,
            'payment_status' => $status,
            'previous_payment_status' => $previousStatus,
            'track_endpoint' => '/api/orders/'.$order->id.'/track',
            'action_url' => '/orders/'.$order->id.'/track',
        ]);
    }

    public static function notifyBookingCreated(Order $order): void
    {
        $client = self::client($order);
        if (! $client) {
            return;
        }

        $date = $order->booking_date?->format('Y-m-d')
            ?: optional($order->items->firstWhere('booking_date', '!=', null))->booking_date?->format('Y-m-d');
        $slot = filled($order->booking_slot)
            ? (string) $order->booking_slot
            : (string) (optional($order->items->first(fn ($i) => filled($i->booking_slot)))->booking_slot ?? '');

        if ($date === null && $slot === '') {
            return;
        }

        $when = trim(($date ?: 'date TBC').($slot !== '' ? " · {$slot}" : ''));
        self::safeNotify(
            $client,
            'Booking Confirmed',
            "Your booking for order #{$order->id} is confirmed for {$when}.",
            [
                'type' => 'booking_confirmed',
                'order_id' => $order->id,
                'booking_date' => $date,
                'booking_slot' => $slot !== '' ? $slot : null,
                'track_endpoint' => '/api/orders/'.$order->id.'/track',
                'action_url' => '/orders/'.$order->id.'/track',
            ]
        );
    }

    public static function notifyOrderStatusChanged(Order $order, string $previousStatus): void
    {
        $client = self::client($order);
        if (! $client) {
            return;
        }

        $status = OrderTrackingTimeline::normalize((string) ($order->order_status ?? 'pending'));
        $previous = OrderTrackingTimeline::normalize($previousStatus);
        if ($status === $previous) {
            return;
        }

        $label = OrderTrackingTimeline::statusLabel($status);
        $meta = [
            'type' => 'order_status_changed',
            'order_id' => $order->id,
            'order_status' => $status,
            'order_status_label' => $label,
            'previous_order_status' => $previous,
            'track_endpoint' => '/api/orders/'.$order->id.'/track',
            'action_url' => '/orders/'.$order->id.'/track',
        ];

        self::safeNotify($client, 'Order Status Updated', "Order #{$order->id} is now {$label}.", $meta);
        self::notifyOperationalRoles($order, $client, $label, $meta);
    }

    /**
     * Vendors, assigned technicians, and supervisors also need status changes
     * on a device that may be closed.
     *
     * @param  array<string, mixed>  $meta
     */
    private static function notifyOperationalRoles(Order $order, User $client, string $label, array $meta): void
    {
        $seen = [$client->id => true];

        $order->loadMissing('vendorMappings.vendor.user');
        foreach ($order->vendorMappings as $mapping) {
            $vendorUser = $mapping->vendor?->user;
            if (! $vendorUser instanceof User || isset($seen[$vendorUser->id])) {
                continue;
            }
            $seen[$vendorUser->id] = true;
            $vendorMeta = $meta;
            $vendorMeta['action_url'] = '/vendor/orders/'.$order->id.'/track';
            self::safeNotify(
                $vendorUser,
                'Order Status Updated',
                "Order #{$order->id} is now {$label}.",
                $vendorMeta
            );
        }

        $visits = Visit::query()
            ->where('order_id', $order->id)
            ->get(['id', 'technician_id', 'supervisor_id']);

        foreach ($visits as $visit) {
            foreach (['technician_id', 'supervisor_id'] as $column) {
                $userId = (int) ($visit->{$column} ?? 0);
                if ($userId <= 0 || isset($seen[$userId])) {
                    continue;
                }
                $staff = User::query()->find($userId);
                if (! $staff instanceof User) {
                    continue;
                }
                $seen[$userId] = true;
                self::safeNotify(
                    $staff,
                    'Order Status Updated',
                    "Order #{$order->id} is now {$label}.",
                    $meta
                );
            }
        }
    }

    private static function client(Order $order): ?User
    {
        $order->loadMissing('user');

        return $order->user instanceof User ? $order->user : null;
    }

    private static function fulfillment(Order $order): string
    {
        $order->loadMissing(['items.product.services']);

        if (OrderFulfillmentType::usesVendorProductWorkflow($order)) {
            return OrderFulfillmentType::PRODUCT;
        }

        if (OrderFulfillmentType::usesPlatformCheckoutWorkflow($order)) {
            return OrderFulfillmentType::PLATFORM;
        }

        return OrderFulfillmentType::SERVICE;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function safeNotify(User $user, string $title, string $message, array $meta): void
    {
        try {
            $user->notify(new AdminNotification($title, $message, $meta));
        } catch (\Throwable $e) {
            Log::warning('order.client_lifecycle_notify_failed', [
                'user_id' => $user->id,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
