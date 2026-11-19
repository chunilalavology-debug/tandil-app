<?php

namespace Tests\Unit\Push;

use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\Push\PushDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expo_token_on_ios_platform_uses_exp_host_not_apns(): void
    {
        config([
            'services.expo.enabled' => true,
            'services.expo.driver' => 'http',
            'services.apns.enabled' => true,
            'services.apns.driver' => 'log',
        ]);

        Http::fake([
            'https://exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        DevicePushToken::create([
            'user_id' => $admin->id,
            'token' => 'ExponentPushToken[admin-device-xyz]',
            'platform' => 'ios',
        ]);

        app(PushDeliveryService::class)->sendToUser(
            $admin,
            'New Order Received',
            'A new order was placed',
            ['type' => 'new_paid_order', 'order_id' => 42]
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host'));
    }

    public function test_expo_token_on_android_platform_uses_exp_host_not_fcm(): void
    {
        config([
            'services.expo.enabled' => true,
            'services.expo.driver' => 'http',
            'services.expo.android_channel_id' => 'tandil_default',
            'services.fcm.enabled' => true,
            'services.fcm.driver' => 'log',
        ]);

        Http::fake([
            'https://exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200),
        ]);

        $client = User::factory()->create(['role' => 'client']);
        DevicePushToken::create([
            'user_id' => $client->id,
            'token' => 'ExponentPushToken[android-device-abc]',
            'platform' => 'android',
        ]);

        app(PushDeliveryService::class)->sendToUser(
            $client,
            'Test',
            'Android Expo push',
            ['type' => 'admin_notification']
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'exp.host')) {
                return false;
            }
            $body = $request->data();
            $first = is_array($body) && isset($body[0]) ? $body[0] : $body;

            return is_array($first)
                && ($first['channelId'] ?? null) === 'tandil_default';
        });
    }
}