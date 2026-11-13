<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Models\WalletCredit;
use App\Notifications\AdminNotification;
use App\Services\ShopOrderCancellationService;
use App\Support\OrderClientReportService;
use App\Support\OrderFulfillmentType;
use App\Support\OrderPaidSideEffects;
use App\Support\OrderTrackingTimeline;
use App\Support\RefundPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $isAdminLike = $this->userIsAdminLike($user);
        $relations = ['items.product.primaryImage'];
        if ($isAdminLike) {
            $relations[] = 'user';
        }

        $query = Order::query()
            ->with($relations)
            ->latest();

        if (! $isAdminLike) {
            $userEmail = strtolower(trim((string) ($user->email ?? '')));
            $query->where(function ($q) use ($user, $userEmail) {
                $q->where('user_id', $user->id);
                if ($userEmail !== '') {
                    $q->orWhere(function ($guest) use ($userEmail) {
                        $guest->whereNull('user_id')
                            ->whereRaw('LOWER(TRIM(guest_email)) = ?', [$userEmail]);
                    });
                }
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('order_status', $request->status);
        }

        // Filter by payment status
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);
        $orders = $query->paginate($perPage);
        $orders->setCollection(
            $orders->getCollection()->map(fn (Order $order) => $this->mapOrderForListApi($order))
        );

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully',
            'data' => $orders->items(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
        ], 200);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::with([
            'items.product.category',
            'items.product.services',
            'user',
            'transactions',
            'shippingAddress',
            'vendorMappings',
        ])->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (! $this->canViewOrder($user, $order)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $fulfillmentType = OrderTrackingTimeline::fulfillmentType($order);
        $deliveryOtp = null;
        if ($fulfillmentType === OrderFulfillmentType::PRODUCT) {
            $mapping = $order->vendorMappings->sortByDesc('id')->first()
                ?? $order->vendorMappings()->latest('id')->first();
            $deliveryOtp = app(\App\Services\Vendor\VendorDeliveryOtpService::class)
                ->otpPayloadForCustomer($mapping);
        }
        $otpCode = is_array($deliveryOtp) ? ($deliveryOtp['otp'] ?? $deliveryOtp['code'] ?? null) : null;

        $data = $order->toArray();
        $data['shipping_address'] = $order->getShippingAddressForApi();
        $data['order_number'] = $order->publicOrderNumber();
        $data['fulfillment_type'] = $fulfillmentType;
        $data['uses_delivery_otp'] = $fulfillmentType === OrderFulfillmentType::PRODUCT;
        $data['delivery_otp'] = $deliveryOtp;
        $data['otp'] = $otpCode;

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully',
            'data' => $data,
            'order_number' => $order->publicOrderNumber(),
            'order_number_short' => $order->publicOrderNumberDigits(),
            'order_summary' => $this->orderSummaryForApi($order),
            'fulfillment_type' => $fulfillmentType,
            'uses_delivery_otp' => $fulfillmentType === OrderFulfillmentType::PRODUCT,
            'delivery_otp' => $deliveryOtp,
            'otp' => $otpCode,
        ], 200);
    }

    public function markPaid(Request $request, $id)
    {
        $order = Order::with('user')->find($id);
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $oldPaymentStatus = $order->payment_status;
        if ($order->user_id !== null && (int) $order->user_id !== (int) $request->user()->id
            && ! $request->user()->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $order->payment_status = 'paid';
        $order->paid_at = now();
        $order->save();
        // Client payment push/in-app notify is handled by OrderObserver → OrderClientLifecycleNotifier.

        if ($oldPaymentStatus !== 'paid') {
            OrderPaidSideEffects::run($order->fresh(['items.product.services']), 'Mark paid (API)', notifyAdmins: true);
        }

        return response()->json(['status' => true, 'data' => $order->fresh()], 200);
    }

    /**
     * Update order
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        // Check if user owns the order or is admin
        if (! $this->canViewOrder($user, $order)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $rules = [
            'order_status' => 'nullable|in:pending,processing,shipped,delivered,cancelled',
            'payment_status' => 'nullable|in:pending,paid,failed,refunded',
            'shipping_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'special_instructions' => 'nullable|string|max:2000',
        ];
        if ($user->hasRole('admin') || $user->hasRole('supervisor') || $user->hasRole('area_manager')) {
            $rules['estimated_arrival'] = 'nullable|string|max:255';
            $rules['job_duration'] = 'nullable|string|max:255';
        }

        $validated = $request->validate($rules);

        if (! $user->hasRole('admin') && ! $user->hasRole('supervisor') && ! $user->hasRole('area_manager')) {
            unset($validated['order_status'], $validated['payment_status']);
        }

        $order->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Order updated successfully',
            'data' => $order->load(['items.product', 'user']),
        ], 200);
    }

    /**
     * Cancel order
     */
    public function cancel(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        // Check if user owns the order or is admin
        if ($order->user_id !== $user->id && ! $user->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $cancellation = app(ShopOrderCancellationService::class);
        if ($cancellation->isForbidden((string) ($order->order_status ?? 'pending'))) {
            return response()->json([
                'success' => false,
                'message' => $cancellation->forbiddenMessage((string) ($order->order_status ?? 'pending')),
            ], 422);
        }

        $result = $cancellation->cancelOrder($order);
        $order->refresh();

        // 🔔 Notify admin and client about cancellation
        try {
            // Notify admins
            $admins = User::role('admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new AdminNotification(
                    'Order Cancelled',
                    "Order #{$order->id} has been cancelled by {$user->name}."
                ));
            }

            // Notify client (if different from user who cancelled)
            if ($order->user && $order->user_id !== $user->id) {
                $order->user->notify(new AdminNotification(
                    'Order Cancelled',
                    "Your order #{$order->id} has been cancelled."
                ));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send order cancellation notification: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'data' => $order->load(['items.product', 'user']),
            'refund' => $result,
        ], 200);
    }

    /**
     * Track order – timeline UI (Pending → Confirmed → Assigned → In Progress → Completed → Delivered).
     * Returns order, timeline steps with label/description/timestamp/completed, and maintenance_photos.
     */
    public function track(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::with(['items.product', 'items.product.primaryImage', 'user', 'shippingAddress', 'vendorMappings'])->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (! $this->canViewOrder($user, $order)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $timeline = OrderTrackingTimeline::forOrder($order);
        $maintenancePhotos = $this->getOrderMaintenancePhotos($order);
        $serviceReport = app(OrderClientReportService::class)->serviceReportMetaForOrder($order);
        $fulfillmentType = OrderTrackingTimeline::fulfillmentType($order);
        $display = OrderTrackingTimeline::displayStatus($order);
        $deliveryOtp = null;
        if ($fulfillmentType === OrderFulfillmentType::PRODUCT) {
            $mapping = $order->vendorMappings()->latest('id')->first();
            $deliveryOtp = app(\App\Services\Vendor\VendorDeliveryOtpService::class)
                ->otpPayloadForCustomer($mapping);
            $display = OrderTrackingTimeline::displayStatus($order, $mapping);
        }
        $otpCode = is_array($deliveryOtp) ? ($deliveryOtp['otp'] ?? $deliveryOtp['code'] ?? null) : null;

        return response()->json([
            'success' => true,
            'message' => 'Order tracking information retrieved successfully',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->publicOrderNumber(),
                'order_number_short' => $order->publicOrderNumberDigits(),
                'order' => $this->mapOrderForTrackApi($order),
                'order_summary' => $this->orderSummaryForApi($order),
                'fulfillment_type' => $fulfillmentType,
                'tracking_layout' => OrderTrackingTimeline::trackingLayout($order),
                'status' => $display['status'],
                'status_label' => $display['status_label'],
                'status_icon' => $display['status_icon'],
                'current_status' => $display['status_label'],
                'otp' => $otpCode,
                'delivery_otp' => $deliveryOtp,
                'tracking' => [
                    'fulfillment_type' => $fulfillmentType,
                    'layout' => OrderTrackingTimeline::trackingLayout($order),
                    'status' => $display['status'],
                    'status_label' => $display['status_label'],
                    'payment_status' => $order->payment_status,
                    'timeline' => $timeline,
                    'created_at' => $order->created_at?->format('c'),
                    'updated_at' => $order->updated_at?->format('c'),
                    'paid_at' => $order->paid_at?->format('c'),
                ],
                'service_report' => $serviceReport,
                'service_rating' => $this->serviceRatingMetaForOrder($order, $user),
                'maintenance_photos' => $maintenancePhotos,
                'can_cancel' => ! app(ShopOrderCancellationService::class)->isForbidden((string) ($order->order_status ?? 'pending')),
                'refund_policy' => RefundPolicy::policyForApi(),
                'wallet' => $this->walletSnapshot($order),
            ],
        ], 200);
    }

    /**
     * Client service report for a shop order (visible only after supervisor submits to client).
     */
    public function report(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::query()->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (! $this->canViewOrder($user, $order)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $reportService = app(OrderClientReportService::class);
        $report = $reportService->findReportForOrder($order);

        if (! $reportService->isReportVisibleToClient($report)) {
            return response()->json([
                'success' => false,
                'message' => 'Service report is not available yet. It will appear after your supervisor submits it.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Service report retrieved successfully.',
            'data' => $reportService->formatReportForClient($report),
        ], 200);
    }

    /**
     * Client confirms delivery after reviewing the service report (service orders).
     * Product-only orders are completed when the vendor confirms the customer OTP.
     */
    public function markDelivered(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::query()->with(['items.product.services', 'vendorMappings'])->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if ($order->user_id !== null && (int) $order->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($order->user_id === null) {
            $orderEmail = strtolower(trim((string) ($order->guest_email ?? '')));
            $userEmail = strtolower(trim((string) ($user->email ?? '')));
            if ($orderEmail === '' || $orderEmail !== $userEmail) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        if (OrderFulfillmentType::usesPlatformCheckoutWorkflow($order)) {
            return response()->json([
                'success' => false,
                'message' => 'This is a checkout-only platform order. No delivery confirmation is required from your side.',
                'data' => [
                    'order_id' => $order->id,
                    'order_status' => $order->order_status,
                    'fulfillment_type' => OrderFulfillmentType::PLATFORM,
                    'tracking' => [
                        'status' => $order->order_status,
                        'timeline' => OrderTrackingTimeline::forOrder($order),
                    ],
                ],
            ], 422);
        }

        if (OrderFulfillmentType::usesVendorProductWorkflow($order)) {
            $status = strtolower((string) ($order->order_status ?? 'pending'));
            if ($status === 'delivered') {
                return response()->json([
                    'success' => true,
                    'message' => 'Order is already marked as delivered.',
                    'data' => [
                        'order_id' => $order->id,
                        'order_status' => $order->order_status,
                        'tracking' => [
                            'status' => $order->order_status,
                            'timeline' => OrderTrackingTimeline::forOrder($order),
                        ],
                    ],
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Product orders are completed when you give the delivery OTP to the vendor. Check Track for your OTP after the order is out for delivery.',
                'data' => [
                    'order_id' => $order->id,
                    'order_status' => $order->order_status,
                    'delivery_otp' => app(\App\Services\Vendor\VendorDeliveryOtpService::class)
                        ->otpPayloadForCustomer($order->vendorMappings->sortByDesc('id')->first()),
                    'tracking' => [
                        'status' => $order->order_status,
                        'timeline' => OrderTrackingTimeline::forOrder($order),
                    ],
                ],
            ], 422);
        }

        $reportService = app(OrderClientReportService::class);
        $report = $reportService->findReportForOrder($order);
        if (! $reportService->isReportVisibleToClient($report)) {
            return response()->json([
                'success' => false,
                'message' => 'Service report must be available before marking the order as delivered.',
            ], 422);
        }

        $status = strtolower((string) ($order->order_status ?? 'pending'));
        if ($status === 'delivered') {
            return response()->json([
                'success' => true,
                'message' => 'Order is already marked as delivered.',
                'data' => [
                    'order_id' => $order->id,
                    'order_status' => $order->order_status,
                    'tracking' => [
                        'status' => $order->order_status,
                        'timeline' => OrderTrackingTimeline::forOrder($order),
                    ],
                ],
            ], 200);
        }

        if ($status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Order must be completed before it can be marked as delivered.',
            ], 422);
        }

        $order->order_status = 'delivered';
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Order marked as delivered.',
            'data' => [
                'order_id' => $order->id,
                'order_status' => $order->order_status,
                'current_status' => OrderTrackingTimeline::statusLabel($order->order_status),
                'tracking' => [
                    'status' => $order->order_status,
                    'timeline' => OrderTrackingTimeline::forOrder($order),
                ],
                'service_report' => $reportService->serviceReportMetaForOrder($order),
            ],
        ], 200);
    }

    /**
     * Cancelled orders list for app cancelled tab.
     */
    public function cancelledList(Request $request)
    {
        $user = $request->user();
        $query = Order::query()
            ->with(['items.product', 'user'])
            ->where('order_status', 'cancelled')
            ->latest();

        $roleValue = strtolower(trim((string) ($user->role ?? '')));
        $isAdminLike = in_array($roleValue, ['admin', 'supervisor', 'area_manager'], true);
        if (! $isAdminLike && method_exists($user, 'getRoleNames')) {
            try {
                $roleNames = collect($user->getRoleNames())->map(fn ($r) => strtolower((string) $r));
                $isAdminLike = $roleNames->intersect(['admin', 'supervisor', 'area_manager'])->isNotEmpty();
            } catch (\Throwable $e) {
                // keep fallback path non-fatal
            }
        }
        if (! $isAdminLike && method_exists($user, 'hasRole')) {
            $isAdminLike = $user->hasRole('admin')
                || $user->hasRole('supervisor')
                || $user->hasRole('area_manager');
        }

        if (! $isAdminLike) {
            $userEmail = strtolower(trim((string) ($user->email ?? '')));
            $query->where(function ($q) use ($user, $userEmail) {
                $q->where('user_id', $user->id);
                if ($userEmail !== '') {
                    $q->orWhere(function ($guest) use ($userEmail) {
                        $guest->whereNull('user_id')
                            ->whereRaw('LOWER(TRIM(guest_email)) = ?', [$userEmail]);
                    });
                }
            });
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Cancelled orders retrieved successfully',
            'data' => $orders->items(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ], 200);
    }

    /**
     * Dedicated cancelled-order tracking detail (for cancelled card click flow).
     */
    public function cancelTrack(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::with(['items.product', 'items.product.primaryImage', 'user', 'shippingAddress'])->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (! $this->canViewOrder($user, $order)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if (strtolower((string) ($order->order_status ?? '')) !== 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This order is not cancelled.',
            ], 422);
        }

        $timeline = OrderTrackingTimeline::forOrder($order);
        $maintenancePhotos = $this->getOrderMaintenancePhotos($order);

        return response()->json([
            'success' => true,
            'message' => 'Cancelled order tracking retrieved successfully',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->publicOrderNumber(),
                'order_number_short' => $order->publicOrderNumberDigits(),
                'order' => $this->mapOrderForTrackApi($order),
                'order_summary' => $this->orderSummaryForApi($order),
                'current_status' => OrderTrackingTimeline::statusLabel($order->order_status),
                'tracking' => [
                    'status' => $order->order_status,
                    'payment_status' => $order->payment_status,
                    'timeline' => $timeline,
                    'created_at' => $order->created_at?->format('c'),
                    'updated_at' => $order->updated_at?->format('c'),
                    'paid_at' => $order->paid_at?->format('c'),
                ],
                'maintenance_photos' => $maintenancePhotos,
                'can_cancel' => false,
                'refund_policy' => RefundPolicy::policyForApi(),
                'wallet' => $this->walletSnapshot($order),
            ],
        ], 200);
    }

    /**
     * Guest: get order details by order_number + email (no auth). For guest checkout tracking.
     */
    public function guestShow(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string|max:50',
            'email' => 'required|email',
        ]);
        $order = $this->findGuestOrder($request->input('order_number'), $request->input('email'));
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found or email does not match.'], 404);
        }
        $order->load(['items.product', 'items.product.primaryImage']);
        $data = $order->toArray();
        $data['shipping_address'] = $order->getShippingAddressForApi();
        $data['order_number'] = $order->publicOrderNumber();

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved.',
            'data' => $data,
            'order_number' => $order->publicOrderNumber(),
            'order_number_short' => $order->publicOrderNumberDigits(),
            'order_summary' => $this->orderSummaryForApi($order),
        ], 200);
    }

    /**
     * Guest: get order tracking by order_number + email (no auth). Same structure as track().
     */
    public function guestTrack(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string|max:50',
            'email' => 'required|email',
        ]);
        $order = $this->findGuestOrder($request->input('order_number'), $request->input('email'));
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found or email does not match.'], 404);
        }
        $order->load(['items.product', 'items.product.primaryImage', 'shippingAddress', 'vendorMappings']);
        $timeline = OrderTrackingTimeline::forOrder($order);
        $maintenancePhotos = $this->getOrderMaintenancePhotos($order);
        $serviceReport = app(OrderClientReportService::class)->serviceReportMetaForOrder($order);
        $fulfillmentType = OrderTrackingTimeline::fulfillmentType($order);
        $display = OrderTrackingTimeline::displayStatus($order);
        $deliveryOtp = null;
        if ($fulfillmentType === OrderFulfillmentType::PRODUCT) {
            $mapping = $order->vendorMappings()->latest('id')->first();
            $deliveryOtp = app(\App\Services\Vendor\VendorDeliveryOtpService::class)
                ->otpPayloadForCustomer($mapping);
            $display = OrderTrackingTimeline::displayStatus($order, $mapping);
        }
        $otpCode = is_array($deliveryOtp) ? ($deliveryOtp['otp'] ?? $deliveryOtp['code'] ?? null) : null;

        return response()->json([
            'success' => true,
            'message' => 'Order tracking information retrieved successfully',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->publicOrderNumber(),
                'order_number_short' => $order->publicOrderNumberDigits(),
                'order' => $this->mapOrderForTrackApi($order),
                'order_summary' => $this->orderSummaryForApi($order),
                'fulfillment_type' => $fulfillmentType,
                'tracking_layout' => OrderTrackingTimeline::trackingLayout($order),
                'status' => $display['status'],
                'status_label' => $display['status_label'],
                'status_icon' => $display['status_icon'],
                'current_status' => $display['status_label'],
                'otp' => $otpCode,
                'delivery_otp' => $deliveryOtp,
                'tracking' => [
                    'fulfillment_type' => $fulfillmentType,
                    'layout' => OrderTrackingTimeline::trackingLayout($order),
                    'status' => $display['status'],
                    'status_label' => $display['status_label'],
                    'payment_status' => $order->payment_status,
                    'timeline' => $timeline,
                    'created_at' => $order->created_at?->format('c'),
                    'updated_at' => $order->updated_at?->format('c'),
                    'paid_at' => $order->paid_at?->format('c'),
                ],
                'service_report' => $serviceReport,
                'service_rating' => $this->serviceRatingMetaForOrder($order, $request->user()),
                'maintenance_photos' => $maintenancePhotos,
                'can_cancel' => ! app(ShopOrderCancellationService::class)->isForbidden((string) ($order->order_status ?? 'pending')),
                'refund_policy' => RefundPolicy::policyForApi(),
                'wallet' => $this->walletSnapshot($order),
            ],
        ], 200);
    }

    /**
     * Resolve guest order by order_number (e.g. order_0001) and guest_email.
     */
    private function findGuestOrder(string $orderNumber, string $email): ?Order
    {
        $id = null;
        if (preg_match('/^order_(\d+)$/i', trim($orderNumber), $m)) {
            $id = (int) $m[1];
        } elseif (is_numeric(trim($orderNumber))) {
            $id = (int) $orderNumber;
        }
        if ($id === null) {
            return null;
        }

        return Order::whereNull('user_id')
            ->where('id', $id)
            ->where('guest_email', $email)
            ->first();
    }

    /**
     * One-screen order summary (date, address, payment, total, special instructions).
     */
    private function orderSummaryForApi(Order $order): array
    {
        $currency = strtoupper((string) config('shop.currency', 'AED'));

        $addr = $order->payerAddressForDisplay();
        $deliveryLine = $addr !== ''
            ? preg_replace("/\s*\n\s*/", ', ', trim($addr))
            : null;

        $order->loadMissing('items.product');
        $firstProduct = $order->items->first()?->product;

        /*
        |--------------------------------------------------------------------------
        | Timing
        |--------------------------------------------------------------------------
        */

        $estimatedArrival = $order->estimated_arrival;

        if (($estimatedArrival === null || $estimatedArrival === '') && $firstProduct) {
            $estimatedArrival = $firstProduct->estimated_arrival;
        }

        $jobDuration = $order->job_duration;

        if (($jobDuration === null || $jobDuration === '') && $firstProduct) {
            $jobDuration = $firstProduct->job_duration;
        }

        /*
        |--------------------------------------------------------------------------
        | Booking Date & Slot
        |--------------------------------------------------------------------------
        |
        | Prefer order-level booking_date / booking_slot. When those are empty
        | (cart checkout stores slots on order_items only), derive from items:
        | - 1 item → that item's slot
        | - many items with the same date/slot → that shared value
        | - mixed slots → first item (per-product truth remains on order.items[])
        |
        */

        $bookingDate = $order->booking_date
            ? \Carbon\Carbon::parse($order->booking_date)->format('Y-m-d')
            : null;

        $bookingSlot = $order->booking_slot;
        if (is_string($bookingSlot) && trim($bookingSlot) === '') {
            $bookingSlot = null;
        }

        if ($bookingDate === null || $bookingSlot === null) {
            $order->loadMissing('items');
            $itemDates = $order->items
                ->map(fn ($item) => $item->booking_date?->toDateString())
                ->filter()
                ->values();
            $itemSlots = $order->items
                ->map(fn ($item) => is_string($item->booking_slot) ? trim($item->booking_slot) : null)
                ->filter()
                ->values();

            if ($bookingDate === null && $itemDates->isNotEmpty()) {
                $uniqueDates = $itemDates->unique()->values();
                $bookingDate = $uniqueDates->count() === 1
                    ? $uniqueDates->first()
                    : $itemDates->first();
            }

            if ($bookingSlot === null && $itemSlots->isNotEmpty()) {
                $uniqueSlots = $itemSlots->unique()->values();
                $bookingSlot = $uniqueSlots->count() === 1
                    ? $uniqueSlots->first()
                    : $itemSlots->first();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Try to extract start/end time from booking_slot
        |--------------------------------------------------------------------------
        |
        | Product picker usually stores start-only ("09:00 AM"). End is resolved
        | from admin JobTimeSlot duration when needed.
        |
        */

        $bounds = \App\Support\ShopBookingSlotHelper::resolveDisplayBounds($bookingSlot);
        $slotStartTime = $bounds['start'];
        $slotEndTime = $bounds['end'];

        /*
        |--------------------------------------------------------------------------
        | Payment
        |--------------------------------------------------------------------------
        */

        $paymentLabel = match (strtolower((string) ($order->payment_method ?? ''))) {
            'stripe' => 'Credit card',
            'paypal' => 'PayPal',
            default => $order->paymentMethodLabel(),
        };

        $deliveredAt = $this->resolveDeliveredAt($order);

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return [
            'placed_at' => $order->created_at?->format('c'),

            // Booking information
            'booking_date' => $bookingDate,
            'booking_slot' => $bookingSlot,

            // Parsed slot times
            'slot_start_time' => $slotStartTime,
            'slot_end_time' => $slotEndTime,

            'delivery_address' => $deliveryLine,

            'payment_method' => $paymentLabel,
            'payment_method_code' => $order->payment_method,

            'subtotal' => (float) ($order->subtotal_amount ?? 0),
            'shipping' => (float) ($order->shipping_amount ?? 0),
            'tax' => (float) ($order->tax_amount ?? 0),
            'tax_percent' => (float) ($order->tax_percent ?? 0),
            'discount' => (float) ($order->coupon_discount_amount ?? 0),
            'instant_order_fee' => (float) ($order->instant_order_fee ?? 0),
            'instant_order_fee_label' => ((float) ($order->instant_order_fee ?? 0) > 0)
                ? 'Instant order fee'
                : null,
            'is_instant_order' => ((float) ($order->instant_order_fee ?? 0) > 0)
                || \App\Support\OrderFulfillmentType::usesVendorProductWorkflow($order)
                || \App\Support\OrderFulfillmentType::usesPlatformCheckoutWorkflow($order),
            'total' => (float) $order->total_amount,
            'currency' => $currency,

            'payment_status' => $order->payment_status,

            'refund_amount' => (float) ($order->refund_amount ?? 0),
            'refund_reason' => $order->refund_reason,
            'refunded_at' => $order->refunded_at?->format('c'),

            'special_instructions' => $order->special_instructions,

            'estimated_arrival' => $estimatedArrival,
            'job_duration' => $jobDuration,

            // Actual delivery time (vendor OTP confirm), not catalog ETA.
            'delivery_date' => $this->formatDeliveryDateDisplay($deliveredAt),
            'delivered_at' => $deliveredAt?->format('c'),
        ];
    }

    /**
     * Maintenance photos linked to this order (e.g. from visits). Placeholder: empty until order–visit link exists.
     */
    private function getOrderMaintenancePhotos(Order $order): array
    {
        $reportService = app(OrderClientReportService::class);
        $visit = $reportService->findVisitForOrder($order);
        if ($visit === null) {
            return [];
        }

        $photoService = app(\App\Services\VisitPhotoService::class);

        return $visit->photos()
            ->where('show_on_client_app', true)
            ->orderBy('id')
            ->get()
            ->map(fn ($photo) => $photoService->toApiItem($photo))
            ->values()
            ->all();
    }

    /**
     * View permission for order detail/track:
     * - admin can view all
     * - owner (order.user_id) can view
     * - guest order can be viewed by authenticated user with same email
     */
    private function canViewOrder(User $user, Order $order): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        if ($order->user_id !== null) {
            return (int) $order->user_id === (int) $user->id;
        }

        $orderEmail = strtolower(trim((string) ($order->guest_email ?? '')));
        $userEmail = strtolower(trim((string) ($user->email ?? '')));

        return $orderEmail !== '' && $orderEmail === $userEmail;
    }

    /**
     * Rate the service for a completed/delivered order.
     *
     * Body:
     *   rating (int 1-5, required)         – overall service rating
     *   review (string, optional)          – service review text
     *   product_ratings[] (optional)       – per-product ratings:
     *       [{ product_id, rating (1-5), review? }]
     *       When omitted, the service rating is applied to every product in the order.
     *
     * Stores an order-level service review (product_id = null) plus one review per product,
     * and recomputes each product's rating_average / rating_count.
     */
    public function rate(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::with('items')->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (! $this->canViewOrder($user, $order)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $status = strtolower((string) ($order->order_status ?? 'pending'));
        if (! in_array($status, ['completed', 'delivered'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You can rate the service once the order is completed.',
            ], 422);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
            'product_ratings' => 'nullable|array',
            'product_ratings.*.product_id' => 'required|integer',
            'product_ratings.*.rating' => 'required|integer|min:1|max:5',
            'product_ratings.*.review' => 'nullable|string|max:1000',
        ]);

        $orderProductIds = $order->items->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();

        // Build per-product rating map (product_id => ['rating' => int, 'review' => ?string]).
        $productRatings = [];
        if (! empty($validated['product_ratings'])) {
            foreach ($validated['product_ratings'] as $entry) {
                $pid = (int) $entry['product_id'];
                if (! $orderProductIds->contains($pid)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Product #{$pid} is not part of this order.",
                    ], 422);
                }
                $productRatings[$pid] = [
                    'rating' => (int) $entry['rating'],
                    'review' => $entry['review'] ?? null,
                ];
            }
        } else {
            // Default: apply the overall service rating/review to every product in the order.
            foreach ($orderProductIds as $pid) {
                $productRatings[$pid] = [
                    'rating' => (int) $validated['rating'],
                    'review' => $validated['review'] ?? null,
                ];
            }
        }

        \DB::transaction(function () use ($order, $user, $validated, $productRatings) {
            // Service (order-level) review — product_id null.
            Review::updateOrCreate(
                ['user_id' => $user->id, 'order_id' => $order->id, 'product_id' => null],
                ['rating' => (int) $validated['rating'], 'comment' => $validated['review'] ?? null]
            );

            foreach ($productRatings as $pid => $data) {
                Review::updateOrCreate(
                    ['user_id' => $user->id, 'order_id' => $order->id, 'product_id' => $pid],
                    ['rating' => $data['rating'], 'comment' => $data['review']]
                );
            }
        });

        // Recompute aggregate rating for each rated product.
        $productSnapshots = [];
        foreach (Product::whereIn('id', array_keys($productRatings))->get() as $product) {
            $product->recalculateRating();
            $productSnapshots[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'rating' => $productRatings[$product->id]['rating'],
                'review' => $productRatings[$product->id]['review'],
                'rating_average' => (float) $product->rating_average,
                'rating_count' => (int) $product->rating_count,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Thank you! Your rating has been submitted.',
            'data' => [
                'order_id' => $order->id,
                'service_rating' => (int) $validated['rating'],
                'service_review' => $validated['review'] ?? null,
                'product_ratings' => $productSnapshots,
            ],
        ], 200);
    }

    /**
     * Existing rating for an order (so the app can show already-submitted state).
     */
    public function rating(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::query()->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (! $this->canViewOrder($user, $order)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order rating retrieved successfully.',
            'data' => $this->serviceRatingMetaForOrder($order, $user),
        ], 200);
    }

    /**
     * Rating meta for track/rating responses: whether the client can rate and any existing rating.
     *
     * @return array<string, mixed>
     */
    private function serviceRatingMetaForOrder(Order $order, ?User $user): array
    {
        $status = strtolower((string) ($order->order_status ?? 'pending'));
        $canRate = in_array($status, ['completed', 'delivered'], true);

        $serviceReview = null;
        $productReviews = [];

        if ($user !== null) {
            $reviews = Review::query()
                ->where('order_id', $order->id)
                ->where('user_id', $user->id)
                ->get();

            $service = $reviews->firstWhere('product_id', null);
            if ($service) {
                $serviceReview = [
                    'rating' => (int) $service->rating,
                    'review' => $service->comment,
                    'submitted_at' => $service->created_at?->format('c'),
                ];
            }

            $productReviews = $reviews->whereNotNull('product_id')->map(fn (Review $r) => [
                'product_id' => (int) $r->product_id,
                'rating' => (int) $r->rating,
                'review' => $r->comment,
            ])->values()->all();
        }

        return [
            'can_rate' => $canRate,
            'has_rated' => $serviceReview !== null,
            'service_rating' => $serviceReview,
            'product_ratings' => $productReviews,
        ];
    }

    private function userIsAdminLike(User $user): bool
    {
        $roleValue = strtolower(trim((string) ($user->role ?? '')));

        return in_array($roleValue, ['admin', 'supervisor', 'area_manager'], true);
    }

    private function walletCreditsTableExists(): bool
    {
        try {
            return Schema::hasTable('wallet_credits');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function walletSnapshot(Order $order): ?array
    {
        if (! $order->user_id || ! $order->relationLoaded('user') && ! $order->user) {
            return null;
        }
        if (! $this->walletCreditsTableExists()) {
            return [
                'balance' => (float) (($order->user?->wallet_balance) ?? 0),
                'last_refund_credit' => null,
            ];
        }
        $user = $order->user;
        if (! $user) {
            return null;
        }

        try {
            $latestCredit = WalletCredit::query()
                ->where('user_id', $user->id)
                ->where('order_id', $order->id)
                ->latest('id')
                ->first();
        } catch (\Throwable $e) {
            return [
                'balance' => (float) ($user->wallet_balance ?? 0),
                'last_refund_credit' => null,
            ];
        }

        return [
            'balance' => (float) ($user->wallet_balance ?? 0),
            'last_refund_credit' => $latestCredit ? [
                'amount' => (float) $latestCredit->amount,
                'status' => $latestCredit->status,
                'expires_at' => $latestCredit->expires_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function resolveOrderTiming(Order $order): array
    {
        $firstProduct = $order->items->first()?->product;
        $estimatedArrival = $order->estimated_arrival;
        if (($estimatedArrival === null || $estimatedArrival === '') && $firstProduct) {
            $estimatedArrival = $firstProduct->estimated_arrival;
        }

        $jobDuration = $order->job_duration;
        if (($jobDuration === null || $jobDuration === '') && $firstProduct) {
            $jobDuration = $firstProduct->job_duration;
        }

        return [$estimatedArrival, $jobDuration];
    }

    private function mapOrderItemProductForApi(?OrderItem $item): ?array
    {
        $product = $item?->product;
        if (! $product) {
            return null;
        }

        $imagePath = null;
        if ($product->relationLoaded('primaryImage') && $product->primaryImage?->image_path) {
            $imagePath = $product->primaryImage->image_path;
        } elseif (! empty($product->image)) {
            $imagePath = $product->image;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'image_url' => $imagePath
                ? (\App\Models\ProductImage::buildThumbUrl($imagePath, 192)
                    ?? \App\Models\ProductImage::buildFullUrl($imagePath))
                : null,
            'job_duration' => $product->job_duration,
            'estimated_arrival' => $product->estimated_arrival,
        ];
    }

    private function mapOrderForListApi(Order $order): array
    {
        [$estimatedArrival, $jobDuration] = $this->resolveOrderTiming($order);
        $deliveredAt = $this->resolveDeliveredAt($order);

        return [
            'id' => $order->id,
            'order_number' => $order->publicOrderNumber(),
            'order_number_short' => $order->publicOrderNumberDigits(),
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'total_amount' => (float) $order->total_amount,
            'subtotal_amount' => (float) ($order->subtotal_amount ?? 0),
            'shipping_amount' => (float) ($order->shipping_amount ?? 0),
            'tax_amount' => (float) ($order->tax_amount ?? 0),
            'instant_order_fee' => (float) ($order->instant_order_fee ?? 0),
            'currency' => strtoupper((string) config('shop.currency', 'AED')),
            'special_instructions' => $order->special_instructions,
            'estimated_arrival' => $estimatedArrival,
            'job_duration' => $jobDuration,
            'delivery_date' => $this->formatDeliveryDateDisplay($deliveredAt),
            'delivered_at' => $deliveredAt?->format('c'),
            'created_at' => $order->created_at?->format('c'),
            'paid_at' => $order->paid_at?->format('c'),
            'items' => $this->mapOrderItemsForCheckoutApi($order),
        ];
    }

    private function mapOrderForTrackApi(Order $order): array
    {
        [$estimatedArrival, $jobDuration] = $this->resolveOrderTiming($order);
        $deliveredAt = $this->resolveDeliveredAt($order);

        return [
            'id' => $order->id,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'total_amount' => (float) $order->total_amount,
            'subtotal_amount' => (float) ($order->subtotal_amount ?? 0),
            'shipping_amount' => (float) ($order->shipping_amount ?? 0),
            'tax_amount' => (float) ($order->tax_amount ?? 0),
            'instant_order_fee' => (float) ($order->instant_order_fee ?? 0),
            'special_instructions' => $order->special_instructions,
            'estimated_arrival' => $estimatedArrival,
            'job_duration' => $jobDuration,
            'delivery_date' => $this->formatDeliveryDateDisplay($deliveredAt),
            'delivered_at' => $deliveredAt?->format('c'),
            'created_at' => $order->created_at?->format('c'),
            'updated_at' => $order->updated_at?->format('c'),
            'paid_at' => $order->paid_at?->format('c'),
            'shipping_address' => $order->getShippingAddressForApi(),
            'items' => $this->mapOrderItemsForCheckoutApi($order),
        ];
    }

    /**
     * Client checkout/order detail: base service + optional Trees / Palm Trees as separate lines.
     *
     * @return list<array<string, mixed>>
     */
    private function mapOrderItemsForCheckoutApi(Order $order): array
    {
        $lines = [];

        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            $productPayload = $this->mapOrderItemProductForApi($item);
            $merged = array_merge([
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => is_array($productPayload) ? (string) ($productPayload['name'] ?? 'Service') : 'Service',
                'quantity' => $item->quantity,
                'price' => (float) $item->price,
                'subtotal' => (float) $item->subtotal,
                'booking_date' => $item->booking_date?->toDateString(),
                'booking_slot' => $item->booking_slot,
                'product' => $productPayload,
            ], \App\Support\ServiceAreaPricing::orderItemApiFields($item));

            foreach (\App\Support\ServiceTreePricing::expandCheckoutDisplayLines($merged) as $displayLine) {
                if (($displayLine['is_addon'] ?? false) === true) {
                    // Addon rows: money on this row only; do not re-use combined order-item subtotal.
                    $displayLine['price'] = (float) ($displayLine['unit_price'] ?? 0);
                    $displayLine['subtotal'] = (float) ($displayLine['line_total'] ?? 0);
                    $displayLine['product'] = [
                        'id' => $displayLine['parent_product_id'] ?? $item->product_id,
                        'name' => $displayLine['name'] ?? 'Add-on',
                    ];
                    unset($displayLine['id']);
                } else {
                    // Base service row: show base amount only (add-ons are sibling lines).
                    $base = (float) ($displayLine['base_line_total'] ?? $displayLine['line_total'] ?? 0);
                    $displayLine['subtotal'] = $base;
                    $displayLine['price'] = (float) ($displayLine['unit_price'] ?? $item->price);
                }
                $lines[] = $displayLine;
            }
        }

        return $lines;
    }

    /**
     * Vendor product delivery timestamp (OTP confirm), or null if not delivered yet.
     */
    private function resolveDeliveredAt(Order $order): ?\Carbon\CarbonInterface
    {
        $order->loadMissing('vendorMappings');
        $mapping = $order->vendorMappings->sortByDesc('id')->first()
            ?? $order->vendorMappings()->latest('id')->first();

        if ($mapping?->delivery_otp_confirmed_at) {
            return $mapping->delivery_otp_confirmed_at;
        }

        // Service / platform: when status is delivered, fall back to order updated_at.
        if (strtolower((string) ($order->order_status ?? '')) === 'delivered') {
            return $order->updated_at;
        }

        return null;
    }

    private function formatDeliveryDateDisplay(?\Carbon\CarbonInterface $value): string
    {
        if ($value === null) {
            return '—';
        }

        return $value->format('j M Y').' at '.$value->format('g:i A');
    }
}
