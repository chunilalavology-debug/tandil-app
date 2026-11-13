<?php

namespace Tests\Unit\Push;

use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\Push\FcmPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmPushServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_driver_counts_tokens_without_http(): void
    {
        config([
            'services.fcm.enabled' => true,
            'services.fcm.driver' => 'log',
        ]);

        $user = User::factory()->create();
        DevicePushToken::create([
            'user_id' => $user->id,
            'token' => 'log-driver-token-abcdefghijklmnopqrstuvwxyz',
            'platform' => 'ios',
        ]);

        Http::fake();
        $result = app(FcmPushService::class)->sendToUser($user, 'Hello', 'World', ['type' => 'test']);

        $this->assertSame('log', $result['driver']);
        $this->assertSame(1, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_skips_when_user_has_no_tokens(): void
    {
        config(['services.fcm.driver' => 'log', 'services.fcm.enabled' => true]);

        $user = User::factory()->create();
        $result = app(FcmPushService::class)->sendToUser($user, 'Hello', 'World');

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['sent']);
    }

    public function test_prunes_invalid_legacy_tokens(): void
    {
        config([
            'services.fcm.enabled' => true,
            'services.fcm.driver' => 'legacy',
            'services.fcm.server_key' => 'test-key',
        ]);

        $user = User::factory()->create();
        DevicePushToken::create([
            'user_id' => $user->id,
            'token' => 'invalid-legacy-token-abcdefghijklmnopqrstu',
            'platform' => 'android',
        ]);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'success' => 0,
                'failure' => 1,
                'results' => [['error' => 'NotRegistered']],
            ], 200),
        ]);

        $result = app(FcmPushService::class)->sendToUser($user, 'Title', 'Body');
        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseCount('device_push_tokens', 0);
    }
}
