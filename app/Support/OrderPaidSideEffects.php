<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use App\Notifications\AdminNotification;
use App\Services\Shop\OrderStockService;
use Illuminate\Support\Facades\Log;

/**
 * Single entry-point after a shop order becomes paid.
 *
 * Split by line type (not by who listed the catalog row):
 * 1) Admin alert (optional)
 * 2) Service lines → supervisor Visits + supervisor/area-manager alerts
 * 3) Product lines → vendor mappings + vendor paid-order notifications
 */
final class OrderPaidSideEffects
{
    public static function run(Order $order, string $placedBy, bool $notifyAdmins = true): void
    {
        try {
            if (! $order->isShopOrder()) {
                return;
            }

            if (strtolower((string) ($order->payment_status ?? '')) !== 'paid') {
                return;
            }

            $order = $order->fresh([
                'items.product.services',
                'items.product.vendorProduct.inventory',
                'shippingAddress',
                'user',
            ]) ?? $order;

            // Sell units as soon as payment succeeds (vendor_inventory + products.stock).
            app(OrderStockService::class)->decrementForPaidOrder($order);

            $total = (float) $order->total_amount;

            if ($notifyAdmins) {
                self::notifyAdmins($order, $total, $placedBy);
            }

            // Service lines only — product lines never create Visits.
            OrderToVisitDispatcher::createVisitsForPaidOrder($order);
            $order = $order->fresh([
                'items.product.services',
                'shippingAddress',
                'user',
            ]) ?? $order;

            self::ensureProcessingStatus($order);

            // Client booking / appointment confirmation (date+slot when present).
            OrderClientLifecycleNotifier::notifyBookingCreated($order);

            if (OrderFulfillmentType::hasServiceLines($order)) {
                OrderSupervisorNotifier::notifySupervisorsForPaidOrder($order, $total, $placedBy);
            }

            OrderVendorNotifier::notifyVendorsForPaidOrder($order);
        } catch (\Throwable $e) {
            Log::warning('Order paid side-effects failed: '.$e->getMessage(), [
                'order_id' => $order->id ?? null,
            ]);
        }
    }

    private static function notifyAdmins(Order $order, float $total, string $placedBy): void
    {
        try {
            $admins = User::role('admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new AdminNotification(
                    'New Order Received',
                    "A new order #{$order->id} has been placed by {$placedBy} for AED {$total}.",
                    [
                        'type' => 'new_paid_order',
                        'order_id' => $order->id,
                        'payment_status' => $order->payment_status,
                        'action_url' => '/admin/orders/'.$order->id,
                    ]
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Admin paid-order notify failed: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);
        }
    }

    /**
     * Service jobs move to processing when paid.
     * Product-only orders stay pending until the vendor confirms (UI: Pending → Confirmed → …).
     */
    private static function ensureProcessingStatus(Order $order): void
    {
        if (OrderFulfillmentType::usesVendorProductWorkflow($order)) {
            $status = strtolower(trim((string) ($order->order_status ?? 'pending')));
            if (in_array($status, ['paid', '', 'processing'], true)) {
                $order->order_status = 'pending';
                $order->save();
            }

            return;
        }

        $status = strtolower(trim((string) ($order->order_status ?? 'pending')));
        if (in_array($status, ['pending', 'paid', ''], true)) {
            $order->order_status = 'processing';
            $order->save();
        }
    }
}
