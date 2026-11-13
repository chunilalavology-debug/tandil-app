<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Loyalty\LoyaltyService;
use App\Support\OrderClientLifecycleNotifier;

class OrderObserver
{
    public function __construct(
        private readonly LoyaltyService $loyalty
    ) {}

    public function created(Order $order): void
    {
        $this->loyalty->creditForPaidOrder($order);
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('payment_status')) {
            $this->loyalty->creditForPaidOrder($order);

            $previousPayment = (string) $order->getOriginal('payment_status');
            // Paid confirmations are also emitted from mark-paid / Stripe paths;
            // still notify for refunds/failures and any paid transition for reliability.
            OrderClientLifecycleNotifier::notifyPaymentUpdated($order, $previousPayment);
        }

        if ($order->wasChanged('order_status')) {
            $previousStatus = (string) ($order->getOriginal('order_status') ?? 'pending');
            OrderClientLifecycleNotifier::notifyOrderStatusChanged($order, $previousStatus);
        }
    }
}
