<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\VendorOrderMapping;
use App\Notifications\Concerns\SendsFcmPush;
use App\Support\NotificationAudiencePayload;
use App\Support\OrderTrackingTimeline;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class VendorNewPaidOrderNotification extends Notification
{
    use Queueable;
    use SendsFcmPush;

    public function __construct(
        public Order $order,
        public VendorOrderMapping $mapping
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channelsWithPush(['database']);
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->order;
        $mapping = $this->mapping;
        $orderNumber = $order->publicOrderNumber();
        $shopStatus = OrderTrackingTimeline::normalize((string) ($order->order_status ?? 'pending'));
        $shopStatusLabel = OrderTrackingTimeline::statusLabel($shopStatus);
        $items = $this->vendorItems($order, (int) $mapping->vendor_id);
        $products = $items->map(fn (OrderItem $item) => $this->productPayload($item))->values()->all();
        $required = $this->requiredDateTime($order, $items);
        $location = $this->locationPayload($order);
        $firstProduct = $items->first()?->product?->name ?: 'Product';
        $locationText = $location['display'] !== '' ? $location['display'] : 'not provided';
        $trackUrl = '/vendor/orders/'.$order->id.'/track';

        $message = trim(preg_replace('/\s+/', ' ', sprintf(
            '%s paid for %s. Status: %s. Location: %s. Required: %s %s. Payment confirmed.',
            $orderNumber,
            $firstProduct,
            $shopStatusLabel,
            $locationText,
            $required['date'] ?: 'date TBC',
            $required['time'] ?: ''
        )) ?? '');

        return NotificationAudiencePayload::merge($notifiable, [
            'title' => 'New paid order',
            'message' => $message,
            'type' => 'vendor_new_paid_order',
            'action_url' => $trackUrl,
            'order_id' => $order->id,
            'order_number' => $orderNumber,
            'vendor_order_id' => $mapping->id,
            'vendor_amount' => $mapping->total_amount,
            'status' => $shopStatus,
            'status_label' => $shopStatusLabel,
            'current_status' => $shopStatusLabel,
            'vendor_status' => $mapping->status,
            'track_endpoint' => '/api'.$trackUrl,
            'product_ordered' => $products,
            'products' => $products,
            'customer_location' => $location,
            'location' => $location['display'],
            'required_date' => $required['date'],
            'required_time' => $required['time'],
            'required_datetime' => $required,
            'payment_confirmation' => $this->paymentPayload($order, $orderNumber),
            'order' => [
                'id' => $order->id,
                'order_number' => $orderNumber,
                'status' => $shopStatus,
                'status_label' => $shopStatusLabel,
                'payment_status' => $order->payment_status,
                'total_amount' => $order->total_amount,
                'notes' => $order->special_instructions,
                'customer_name' => $order->payerDisplayName(),
                'customer_email' => $order->payerEmail(),
                'customer_phone' => $order->payerPhone(),
            ],
            'meta' => [
                'entity' => 'vendor_order',
                'order_id' => $order->id,
                'order_number' => $orderNumber,
                'vendor_order_mapping_id' => $mapping->id,
                'vendor_id' => $mapping->vendor_id,
                'vendor_amount' => $mapping->total_amount,
                'total_amount' => (float) $mapping->total_amount,
                'status' => $shopStatus,
                'action_url' => $trackUrl,
                'track_endpoint' => '/api'.$trackUrl,
            ],
        ]);
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private function vendorItems(Order $order, int $vendorId): Collection
    {
        return $order->items->filter(
            fn (OrderItem $item) => (int) ($item->product?->vendor_id ?? 0) === $vendorId
        )->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(OrderItem $item): array
    {
        $product = $item->product;

        return [
            'order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $product?->name,
            'sku' => $product?->sku,
            'quantity' => $item->quantity,
            'unit_price' => $item->price,
            'total_price' => $item->subtotal,
            'booking_date' => $item->booking_date?->format('Y-m-d'),
            'booking_slot' => $item->booking_slot,
            'vendor_id' => $product?->vendor_id,
        ];
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return array{date: string|null, time: string|null, source: string}
     */
    private function requiredDateTime(Order $order, Collection $items): array
    {
        $itemWithDate = $items->first(
            fn (OrderItem $item) => $item->booking_date || filled($item->booking_slot)
        );

        $date = $itemWithDate?->booking_date?->format('Y-m-d')
            ?: $order->booking_date?->format('Y-m-d')
            ?: $this->estimatedArrivalDate($order);

        $time = filled($itemWithDate?->booking_slot)
            ? (string) $itemWithDate->booking_slot
            : (filled($order->booking_slot) ? (string) $order->booking_slot : null);

        $source = 'none';
        if ($itemWithDate?->booking_date || filled($itemWithDate?->booking_slot)) {
            $source = 'order_item';
        } elseif ($order->booking_date || filled($order->booking_slot)) {
            $source = 'order';
        } elseif ($this->estimatedArrivalDate($order)) {
            $source = 'estimated_arrival';
        }

        return [
            'date' => $date,
            'time' => $time,
            'source' => $source,
        ];
    }

    private function estimatedArrivalDate(Order $order): ?string
    {
        if (! $order->estimated_arrival) {
            return null;
        }

        try {
            return Carbon::parse($order->estimated_arrival)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPayload(Order $order): array
    {
        $address = $order->getShippingAddressForApi() ?? [];
        $display = trim(preg_replace('/\s+/', ' ', str_replace("\n", ', ', $order->payerAddressForDisplay())) ?? '');

        return [
            'display' => $display,
            'full_name' => $address['full_name'] ?? null,
            'street_address' => $address['street_address'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'zip_code' => $address['zip_code'] ?? null,
            'country' => $address['country'] ?? null,
            'latitude' => isset($address['latitude']) ? (float) $address['latitude'] : null,
            'longitude' => isset($address['longitude']) ? (float) $address['longitude'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Order $order, string $orderNumber): array
    {
        $paid = strtolower((string) $order->payment_status) === 'paid';

        return [
            'confirmed' => $paid,
            'status' => $order->payment_status,
            'method' => $order->payment_method,
            'method_label' => $order->paymentMethodLabel(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'reference' => $order->payment_reference,
            'amount' => $order->total_amount,
            'order_number' => $orderNumber,
        ];
    }
}
