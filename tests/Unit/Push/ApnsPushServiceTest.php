<?php

namespace Tests\Unit\Push;

use App\Models\DevicePushToken;
use App\Models\User;
use App\Notifications\AdminNotification;
use App\Services\Push\ApnsPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApnsPushServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ios_alert_is_sent_without_firebase_and_shows_when_app_is_closed(): void
    {
        $pem = $this->ecPrivateKeyPem();
        $token = str_repeat('ab', 32);

        config([
            'services.fcm.enabled' => false,
            'services.apns.enabled' => true,
            'services.apns.driver' => 'http',
            'services.apns.key_id' => 'KEYID12345',
            'services.apns.team_id' => 'TEAMID1234',
            'services.apns.bundle_id' => 'com.tandil.app',
            'services.apns.private_key' => $pem,
            'services.apns.endpoint' => 'https://api.push.apple.com',
        ]);

        Http::fake([
            'https://api.push.apple.com/*' => Http::response('', 200),
        ]);

        $user = User::factory()->create(['role' => 'client']);
        DevicePushToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'platform' => 'ios',
        ]);

        $user->notify(new AdminNotification(
            'Order Status Updated',
            'Order #9 is now Processing.',
            [
                'type' => 'order_status_changed',
                'order_id' => 9,
            ]
        ));

        Http::assertSent(function ($request) use ($token) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), '/3/device/'.$token)
                && ($body['aps']['alert']['title'] ?? null) === 'Order Status Updated'
                && ($body['aps']['alert']['body'] ?? null) === 'Order #9 is now Processing.'
                && ($body['type'] ?? null) === 'order_status_changed'
                && ($body['order_id'] ?? null) === '9'
                && $request->hasHeader('apns-push-type', 'alert')
                && $request->hasHeader('apns-priority', '10')
                && $request->hasHeader('apns-topic', 'com.tandil.app');
        });
    }

    public function test_bad_device_token_is_removed(): void
    {
        config([
            'services.apns.enabled' => true,
            'services.apns.driver' => 'http',
            'services.apns.key_id' => 'KEYID12345',
            'services.apns.team_id' => 'TEAMID1234',
            'services.apns.bundle_id' => 'com.tandil.app',
            'services.apns.private_key' => $this->ecPrivateKeyPem(),
            'services.apns.endpoint' => 'https://api.push.apple.com',
        ]);

        Http::fake([
            'https://api.push.apple.com/*' => Http::response(['reason' => 'BadDeviceToken'], 400),
        ]);

        $user = User::factory()->create();
        DevicePushToken::create([
            'user_id' => $user->id,
            'token' => str_repeat('cd', 32),
            'platform' => 'ios',
        ]);

        $result = app(ApnsPushService::class)->sendToTokens(
            [str_repeat('cd', 32)],
            'Title',
            'Body'
        );

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseCount('device_push_tokens', 0);
    }

    private function ecPrivateKeyPem(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));

        return $pem;
    }
}
