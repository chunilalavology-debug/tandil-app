<?php

namespace Tests\Feature\Api;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevicePushTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_device_token(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $user->assignRole('client');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/auth/device-token', [
            'token' => 'fcm-token-abcdefghijklmnopqrstuvwxyz0123456789',
            'platform' => 'android',
            'device_id' => 'device-1',
            'app_version' => '1.0.0',
        ], [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $user->id,
            'token' => 'fcm-token-abcdefghijklmnopqrstuvwxyz0123456789',
            'platform' => 'android',
        ]);
    }

    public function test_token_moves_to_new_user_on_re_register(): void
    {
        $first = User::factory()->create(['role' => 'client']);
        $first->assignRole('client');
        $second = User::factory()->create(['role' => 'vendor']);
        $second->assignRole('vendor');

        DevicePushToken::create([
            'user_id' => $first->id,
            'token' => 'shared-fcm-token-abcdefghijklmnopqrstuvwxyz',
            'platform' => 'ios',
        ]);

        $bearer = $second->createToken('test')->plainTextToken;
        $this->postJson('/api/user/device-token', [
            'token' => 'shared-fcm-token-abcdefghijklmnopqrstuvwxyz',
            'platform' => 'ios',
        ], [
            'Authorization' => 'Bearer '.$bearer,
            'Accept' => 'application/json',
        ])->assertOk();

        $this->assertDatabaseMissing('device_push_tokens', [
            'user_id' => $first->id,
            'token' => 'shared-fcm-token-abcdefghijklmnopqrstuvwxyz',
        ]);
        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $second->id,
            'token' => 'shared-fcm-token-abcdefghijklmnopqrstuvwxyz',
        ]);
    }

    public function test_user_can_delete_own_device_token(): void
    {
        $user = User::factory()->create(['role' => 'technician']);
        $user->assignRole('technician');
        DevicePushToken::create([
            'user_id' => $user->id,
            'token' => 'delete-me-fcm-token-abcdefghijklmnopqrstuv',
            'platform' => 'android',
        ]);

        $bearer = $user->createToken('test')->plainTextToken;
        $this->deleteJson('/api/auth/device-token', [
            'token' => 'delete-me-fcm-token-abcdefghijklmnopqrstuv',
        ], [
            'Authorization' => 'Bearer '.$bearer,
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertDatabaseCount('device_push_tokens', 0);
    }

    public function test_guest_cannot_register_device_token(): void
    {
        $this->postJson('/api/auth/device-token', [
            'token' => 'fcm-token-abcdefghijklmnopqrstuvwxyz0123456789',
            'platform' => 'android',
        ])->assertStatus(401);
    }
}
