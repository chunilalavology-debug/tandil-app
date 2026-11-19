<?php

namespace Tests\Feature\Vendor;

use App\Enums\VendorStatus;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorDevicePushTokenE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'vendor', 'guard_name' => 'web']);
    }

    public function test_pending_vendor_login_then_vendor_auth_device_token_saves_row(): void
    {
        $user = User::factory()->create([
            'role' => 'vendor',
            'email' => 'e2e-pending-vendor@test.com',
            'password' => Hash::make('secret12'),
            'status' => 'active',
        ]);
        $user->assignRole('vendor');
        Vendor::create(['user_id' => $user->id, 'status' => VendorStatus::Pending->value]);
        VendorProfile::create([
            'vendor_id' => $user->fresh()->vendor->id,
            'business_name' => 'E2E Pending',
            'owner_name' => 'Owner',
            'email' => $user->email,
        ]);

        $login = $this->postJson('/api/vendor/auth/login', [
            'email' => 'e2e-pending-vendor@test.com',
            'password' => 'secret12',
            'roles' => 'vendor',
        ])->assertOk();

        $bearer = $login->json('data.token');
        $this->assertNotEmpty($bearer);

        $this->postJson('/api/vendor/auth/device-token', [
            'expo_push_token' => 'ExponentPushToken[vendor-e2e-pending-ios-android]',
            'platform' => 'android',
            'device_id' => 'e2e-device-uuid',
        ], [
            'Authorization' => 'Bearer '.$bearer,
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.platform', 'android');

        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[vendor-e2e-pending-ios-android]',
            'platform' => 'android',
        ]);
    }

    public function test_approved_vendor_can_use_global_auth_device_token_path(): void
    {
        $user = User::factory()->create([
            'role' => 'vendor',
            'email' => 'e2e-approved-vendor@test.com',
            'password' => Hash::make('secret12'),
            'status' => 'active',
        ]);
        $user->assignRole('vendor');
        Vendor::create([
            'user_id' => $user->id,
            'status' => VendorStatus::Approved->value,
            'approved_at' => now(),
        ]);
        VendorProfile::create([
            'vendor_id' => $user->fresh()->vendor->id,
            'business_name' => 'E2E Approved',
            'owner_name' => 'Owner',
            'email' => $user->email,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'e2e-approved-vendor@test.com',
            'password' => 'secret12',
            'roles' => 'vendor',
        ])->assertOk();

        $bearer = $login->json('data.token');

        $this->postJson('/api/auth/device-token', [
            'token' => 'ExponentPushToken[vendor-e2e-approved-global-path]',
            'platform' => 'ios',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertOk();

        $this->assertSame(1, DevicePushToken::query()->where('user_id', $user->id)->count());
    }
}