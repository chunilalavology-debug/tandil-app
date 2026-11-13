<?php

namespace Tests\Feature\Push;

use App\Enums\VendorStatus;
use App\Models\DevicePushToken;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrderMapping;
use App\Models\Visit;
use App\Notifications\AdminNotification;
use App\Notifications\VendorNewPaidOrderNotification;
use App\Services\Push\FcmPushService;
use App\Support\OrderClientLifecycleNotifier;
use App\Support\OrderPaidSideEffects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderLifecyclePushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_confirmation_notifies_client(): void
    {
        Notification::fake();

        $client = User::factory()->create(['role' => 'client']);
        $client->assignRole('client');
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'total_amount' => 120,
        ]);

        $order->payment_status = 'paid';
        $order->paid_at = now();
        $order->save();

        Notification::assertSentTo($client, AdminNotification::class, function (AdminNotification $n) use ($client) {
            $data = $n->toArray($client);

            return ($data['title'] ?? null) === 'Order Payment Confirmed'
                && (($data['meta']['type'] ?? null) === 'order_payment_confirmed');
        });
    }

    public function test_order_status_change_notifies_client(): void
    {
        Notification::fake();

        $client = User::factory()->create(['role' => 'client']);
        $client->assignRole('client');
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'payment_status' => 'paid',
            'order_status' => 'pending',
        ]);

        $order->order_status = 'processing';
        $order->save();

        Notification::assertSentTo($client, AdminNotification::class);
    }

    public function test_booking_confirmation_notifies_client_when_slot_present(): void
    {
        Notification::fake();

        $client = User::factory()->create(['role' => 'client']);
        $client->assignRole('client');
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'payment_status' => 'paid',
            'order_status' => 'processing',
            'booking_date' => '2026-11-20',
            'booking_slot' => '10:00-12:00',
        ]);

        OrderClientLifecycleNotifier::notifyBookingCreated($order);

        Notification::assertSentTo($client, AdminNotification::class, function (AdminNotification $n) use ($client) {
            $data = $n->toArray($client);

            return ($data['meta']['type'] ?? null) === 'booking_confirmed'
                || str_contains((string) ($data['title'] ?? ''), 'Booking');
        });
    }

    public function test_admin_notification_uses_fcm_channel(): void
    {
        $notification = new AdminNotification('New Order Received', 'Order #1 placed', [
            'type' => 'new_paid_order',
            'order_id' => 1,
        ]);

        $channels = $notification->via(User::factory()->make(['role' => 'admin']));
        $this->assertContains('database', $channels);
        $this->assertTrue(
            collect($channels)->contains(fn ($c) => is_string($c) && str_contains($c, 'FcmChannel'))
        );
    }

    public function test_vendor_paid_order_notification_uses_fcm_channel(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'payment_status' => 'paid',
            'order_status' => 'pending',
        ]);

        $vendorUser = User::factory()->create(['role' => 'vendor']);
        $mapping = new \App\Models\VendorOrderMapping([
            'order_id' => $order->id,
            'vendor_id' => 1,
            'total_amount' => 50,
            'status' => 'pending',
        ]);

        $notification = new VendorNewPaidOrderNotification($order, $mapping);
        $channels = $notification->via($vendorUser);
        $this->assertTrue(
            collect($channels)->contains(fn ($c) => is_string($c) && str_contains($c, 'FcmChannel'))
        );
    }

    public function test_fcm_legacy_payload_includes_notification_and_data_for_killed_app(): void
    {
        config([
            'services.fcm.enabled' => true,
            'services.fcm.driver' => 'legacy',
            'services.fcm.server_key' => 'test-server-key',
        ]);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response(['success' => 1, 'failure' => 0], 200),
        ]);

        $user = User::factory()->create(['role' => 'client']);
        DevicePushToken::create([
            'user_id' => $user->id,
            'token' => 'device-token-for-killed-app-delivery-tests',
            'platform' => 'android',
        ]);

        $result = app(FcmPushService::class)->sendToUser(
            $user,
            'Order Status Updated',
            'Order #42 is now Processing.',
            ['type' => 'order_status_changed', 'order_id' => '42']
        );

        $this->assertSame(1, $result['sent']);
        $this->assertSame('legacy', $result['driver']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://fcm.googleapis.com/fcm/send'
                && ($body['notification']['title'] ?? null) === 'Order Status Updated'
                && ($body['notification']['body'] ?? null) === 'Order #42 is now Processing.'
                && ($body['data']['type'] ?? null) === 'order_status_changed'
                && ($body['data']['order_id'] ?? null) === '42'
                && ($body['priority'] ?? null) === 'high'
                && ($body['content_available'] ?? null) === true;
        });
    }

    public function test_status_change_also_notifies_vendor_and_assigned_staff(): void
    {
        Notification::fake();

        $client = User::factory()->create(['role' => 'client']);
        $client->assignRole('client');
        $vendorUser = User::factory()->create(['role' => 'vendor']);
        $vendorUser->assignRole('vendor');
        $technician = User::factory()->create(['role' => 'technician']);
        $technician->assignRole('technician');
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $supervisor->assignRole('supervisor');

        $vendor = Vendor::create([
            'user_id' => $vendorUser->id,
            'status' => VendorStatus::Approved->value,
            'approved_at' => now(),
        ]);
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'payment_status' => 'paid',
            'order_status' => 'pending',
        ]);
        VendorOrderMapping::query()->create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'total_amount' => 40,
            'status' => 'pending',
        ]);
        Visit::withoutEvents(function () use ($order, $technician, $supervisor): void {
            Visit::factory()->create([
                'order_id' => $order->id,
                'technician_id' => $technician->id,
                'supervisor_id' => $supervisor->id,
                'status' => 'pending',
            ]);
        });

        $order->order_status = 'processing';
        $order->save();

        foreach ([$client, $vendorUser, $technician, $supervisor] as $recipient) {
            Notification::assertSentTo($recipient, AdminNotification::class, function (AdminNotification $n) use ($recipient) {
                $data = $n->toArray($recipient);

                return ($data['meta']['type'] ?? null) === 'order_status_changed';
            });
        }
    }

    public function test_fcm_channel_sends_event_type_for_background_and_killed_app(): void
    {
        config([
            'services.fcm.enabled' => true,
            'services.fcm.driver' => 'legacy',
            'services.fcm.server_key' => 'test-server-key',
        ]);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response(['success' => 1, 'failure' => 0], 200),
        ]);

        $client = User::factory()->create(['role' => 'client']);
        DevicePushToken::create([
            'user_id' => $client->id,
            'token' => 'rn-device-token-background-closed-app',
            'platform' => 'android',
        ]);

        $client->notify(new AdminNotification(
            'Order Payment Confirmed',
            'Your order #9 payment has been confirmed.',
            [
                'type' => 'order_payment_confirmed',
                'order_id' => 9,
                'payment_status' => 'paid',
            ]
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['notification']['title'] ?? null) === 'Order Payment Confirmed'
                && ($body['notification']['body'] ?? null) === 'Your order #9 payment has been confirmed.'
                && ($body['data']['type'] ?? null) === 'order_payment_confirmed'
                && ($body['data']['order_id'] ?? null) === '9'
                && ($body['data']['payment_status'] ?? null) === 'paid'
                && ($body['priority'] ?? null) === 'high';
        });
    }

    public function test_paid_side_effects_still_notify_admins(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin');
        $client = User::factory()->create(['role' => 'client']);
        $client->assignRole('client');

        $product = Product::factory()->create(['price' => 40, 'status' => 'active']);
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'package_id' => null,
            'payment_status' => 'paid',
            'order_status' => 'pending',
            'total_amount' => 40,
            'booking_date' => '2026-11-22',
            'booking_slot' => '09:00-11:00',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 40,
            'subtotal' => 40,
        ]);

        OrderPaidSideEffects::run($order->fresh(['items.product.services', 'user']), 'test', notifyAdmins: true);

        Notification::assertSentTo($admin, AdminNotification::class);
        Notification::assertSentTo($client, AdminNotification::class);
    }
}
