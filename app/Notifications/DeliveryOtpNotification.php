<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\VendorOrderMapping;
use App\Notifications\Concerns\SendsFcmPush;
use App\Support\NotificationAudiencePayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app delivery OTP for product orders (no external SMS). Single-use, no time expiry.
 * Also pushed via FCM so the client sees it when the app is closed.
 */
class DeliveryOtpNotification extends Notification
{
    use Queueable;
    use SendsFcmPush;

    public function __construct(
        public Order $order,
        public VendorOrderMapping $mapping,
        public string $otp,
        public bool $isResend = false
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channelsWithPush(['database']);
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->order;
        $orderNumber = $order->publicOrderNumber();
        $trackPath = '/orders/'.$order->id.'/track';
        $title = $this->isResend
            ? 'New delivery confirmation code'
            : 'Delivery confirmation code';
        $message = $this->isResend
            ? "A new OTP for order {$orderNumber} is {$this->otp}. Share it with the supplier to confirm delivery. This code can only be used once."
            : "Your order {$orderNumber} is out for delivery. Your OTP is {$this->otp}. Share it with the supplier when they arrive to confirm delivery. This code can only be used once.";

        return NotificationAudiencePayload::merge($notifiable, [
            'title' => $title,
            'message' => $message,
            'type' => 'delivery_otp',
            'delivery_channel' => 'in_app',
            'action_url' => $trackPath,
            'track_endpoint' => '/api/orders/'.$order->id.'/track',
            'order_id' => $order->id,
            'order_number' => $orderNumber,
            'otp' => $this->otp,
            'single_use' => true,
            'expires' => false,
            'vendor_order_mapping_id' => $this->mapping->id,
            'meta' => [
                'entity' => 'shop_order',
                'type' => 'delivery_otp',
                'delivery_channel' => 'in_app',
                'order_id' => $order->id,
                'order_number' => $orderNumber,
                'vendor_order_mapping_id' => $this->mapping->id,
                'otp' => $this->otp,
                'single_use' => true,
                'expires' => false,
                'action_url' => $trackPath,
                'track_endpoint' => '/api/orders/'.$order->id.'/track',
            ],
        ]);
    }
}
