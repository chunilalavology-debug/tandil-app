<?php

namespace Tests\Feature\Vendor;

use App\Enums\VendorStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorLoginRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'vendor', 'guard_name' => 'web']);
    }

    public function test_pending_vendor_can_login_for_onboarding_and_device_push(): void
    {
        $user = User::factory()->create([
            'role' => 'vendor',
            'email' => 'pending-vendor@test.com',
            'password' => Hash::make('secret12'),
            'status' => 'active',
        ]);
        $user->assignRole('vendor');
        $vendor = Vendor::create(['user_id' => $user->id, 'status' => VendorStatus::Pending->value]);
        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'business_name' => 'Pending Co',
            'owner_name' => 'Owner',
            'email' => $user->email,
        ]);

        $this->postJson('/api/vendor/auth/login', [
            'email' => 'pending-vendor@test.com',
            'password' => 'secret12',
            'roles' => 'vendor',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_approved_vendor_can_login_via_api(): void
    {
        $user = User::factory()->create([
            'role' => 'vendor',
            'email' => 'approved-vendor@test.com',
            'password' => Hash::make('secret12'),
            'status' => 'active',
        ]);
        $user->assignRole('vendor');
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'status' => VendorStatus::Approved->value,
            'approved_at' => now(),
        ]);
        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'business_name' => 'Approved Co',
            'owner_name' => 'Owner',
            'email' => $user->email,
        ]);

        $this->postJson('/api/vendor/auth/login', [
            'email' => 'approved-vendor@test.com',
            'password' => 'secret12',
            'roles' => 'vendor',
        ])
            ->assertOk()
            ->assertJsonPath('data.vendor.is_approved', true);
    }

    public function test_vendor_login_token_persists_for_products_and_survives_relogin(): void
    {
        $user = User::factory()->create([
            'role' => 'vendor',
            'email' => 'persist-vendor@test.com',
            'password' => Hash::make('secret12'),
            'status' => 'active',
        ]);
        $user->assignRole('vendor');
        Vendor::create([
            'user_id' => $user->id,
            'status' => VendorStatus::Approved->value,
            'approved_at' => now(),
        ]);
        $vendor = $user->fresh('vendor')->vendor;
        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'business_name' => 'Persist Co',
            'owner_name' => 'Owner',
            'email' => $user->email,
        ]);

        $payload = [
            'email' => 'persist-vendor@test.com',
            'password' => 'secret12',
            'roles' => 'vendor',
        ];

        $firstLogin = $this->postJson('/api/vendor/auth/login', $payload)->assertOk();
        $token = $firstLogin->json('data.token');
        $this->assertNotEmpty($token);

        $this->withToken($token)->getJson('/api/vendor/products')->assertOk();
        $this->withToken($token)->getJson('/api/vendor/auth/me')->assertOk();

        $this->postJson('/api/vendor/auth/login', $payload)->assertOk();

        $this->withToken($token)->getJson('/api/vendor/products')->assertOk();
    }

    public function test_vendor_token_survives_many_relogins_without_pruning(): void
    {
        $user = User::factory()->create([
            'role' => 'vendor',
            'email' => 'many-login-vendor@test.com',
            'password' => Hash::make('secret12'),
            'status' => 'active',
        ]);
        $user->assignRole('vendor');
        Vendor::create([
            'user_id' => $user->id,
            'status' => VendorStatus::Approved->value,
            'approved_at' => now(),
        ]);
        $vendor = $user->fresh('vendor')->vendor;
        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'business_name' => 'Many Login Co',
            'owner_name' => 'Owner',
            'email' => $user->email,
        ]);

        $payload = [
            'email' => 'many-login-vendor@test.com',
            'password' => 'secret12',
            'roles' => 'vendor',
        ];

        $firstLogin = $this->postJson('/api/vendor/auth/login', $payload)->assertOk();
        $token = $firstLogin->json('data.token');

        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/vendor/auth/login', $payload)->assertOk();
        }

        $this->withToken($token)->getJson('/api/vendor/auth/me')->assertOk();
        $this->withToken($token)->getJson('/api/vendor/profile')->assertOk();

        $this->assertGreaterThanOrEqual(16, $user->fresh()->tokens()->where('name', 'api_vendor')->count());
    }

    public function test_vendor_api_accepts_normalized_bearer_header(): void
    {
        $user = User::factory()->create(['role' => 'vendor', 'status' => 'active']);
        $user->assignRole('vendor');
        $vendor = Vendor::create(['user_id' => $user->id, 'status' => VendorStatus::Approved->value, 'approved_at' => now()]);
        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'business_name' => 'Header Co',
            'owner_name' => 'Owner',
            'email' => $user->email,
        ]);

        $token = $user->createToken('api_vendor', ['vendor'])->plainTextToken;

        $this->getJson('/api/vendor/auth/me', [
            'Authorization' => 'Bearer Bearer '.$token,
            'Accept' => 'application/json',
        ])->assertOk();
    }

    public function test_products_requires_authorization_header(): void
    {
        $this->getJson('/api/vendor/products')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_pending_vendor_can_access_profile_and_notifications_during_onboarding(): void
    {
        $user = User::factory()->create(['role' => 'vendor', 'status' => 'active']);
        $user->assignRole('vendor');
        $vendor = Vendor::create(['user_id' => $user->id, 'status' => VendorStatus::Pending->value]);
        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'business_name' => 'X',
            'owner_name' => 'Y',
            'email' => $user->email,
        ]);

        $token = $user->createToken('test', ['vendor'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/vendor/profile')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/vendor/notifications')
            ->assertOk();
    }
}
