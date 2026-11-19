<?php

namespace Tests\Feature\Push;

use App\Enums\VendorStatus;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProfile;
use App\Support\VendorRegistrationNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * End-to-end push delivery for marketplace vendor registration workflow.
 */
class VendorRegistrationPushE2ETest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EXPO = 'ExponentPushToken[admin-vendor-reg-push-e2e]';

    private const VENDOR_EXPO = 'ExponentPushToken[vendor-status-push-e2e-test]';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        if (class_exists(Role::class) && Schema::hasTable('roles')) {
            Role::firstOrCreate(['name' => 'vendor', 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        }
    }

    protected function tearDown(): void
    {
        config([
            'services.expo.driver' => env('EXPO_PUSH_DRIVER', 'log'),
            'services.expo.enabled' => true,
        ]);
        parent::tearDown();
    }

    private function enableExpoHttpPush(): void
    {
        config([
            'services.expo.enabled' => true,
            'services.expo.driver' => 'http',
        ]);
        Http::fake([
            'https://exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200),
            'exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200),
        ]);
    }

    private function makeAdminWithExpoToken(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin');
        DevicePushToken::create([
            'user_id' => $admin->id,
            'token' => self::ADMIN_EXPO,
            'platform' => 'ios',
        ]);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function minimalRegisterPayload(string $email): array
    {
        return [
            'company_name' => 'Push Test Market',
            'authorized_person_name' => 'Push Vendor',
            'email' => $email,
            'phone' => '+971508887766',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'address' => 'Dubai',
            'trade_license_number' => 'TL-PUSH',
            'vendor_type' => 'fruits',
            'emirate' => 'Dubai',
            'google_maps_location' => '25.2048,55.2708',
            'bank_name' => 'Emirates NBD',
            'iban' => 'AE070331234567890123456',
            'account_holder_name' => 'Push Test',
            'delivery_radius' => 10,
            'opens_at' => '08:00',
            'closes_at' => '22:00',
            'minimum_order_amount' => 0,
            'terms_accepted' => 1,
            'trade_license' => UploadedFile::fake()->create('trade-license.pdf', 100, 'application/pdf'),
            'emirates_id' => UploadedFile::fake()->create('emirates-id.pdf', 100, 'application/pdf'),
        ];
    }

    private function assertExpoPushContains(string $deviceToken, string $titleNeedle, string $bodyNeedle = ''): void
    {
        Http::assertSent(function ($request) use ($deviceToken, $titleNeedle, $bodyNeedle) {
            $url = $request->url();
            if (! str_contains($url, 'exp.host')) {
                return false;
            }
            $payload = $request->data();
            $messages = is_array($payload) && array_is_list($payload) ? $payload : [$payload];
            foreach ($messages as $message) {
                if (! is_array($message)) {
                    continue;
                }
                if (($message['to'] ?? null) !== $deviceToken) {
                    continue;
                }
                $title = (string) ($message['title'] ?? '');
                $body = (string) ($message['body'] ?? '');
                if (! str_contains($title, $titleNeedle)) {
                    continue;
                }
                if ($bodyNeedle !== '' && ! str_contains($body, $bodyNeedle)) {
                    continue;
                }

                return true;
            }

            return false;
        });
    }

    public function test_vendor_registration_sends_expo_push_to_admin(): void
    {
        $this->enableExpoHttpPush();
        $this->makeAdminWithExpoToken();

        $email = 'push-admin-notify-'.uniqid().'@test.com';

        $this->post('/api/vendor/auth/register', $this->minimalRegisterPayload($email), [
            'Accept' => 'application/json',
        ])->assertCreated();

        $this->assertExpoPushContains(self::ADMIN_EXPO, 'New Vendor Registration', 'awaiting review');
    }

    public function test_admin_approve_sends_expo_push_to_vendor(): void
    {
        $this->enableExpoHttpPush();

        $admin = User::factory()->create(['role' => 'admin', 'password' => Hash::make('password')]);
        $admin->assignRole('admin');
        $vendorUser = User::factory()->create(['role' => 'vendor']);
        $vendorUser->assignRole('vendor');
        DevicePushToken::create([
            'user_id' => $vendorUser->id,
            'token' => self::VENDOR_EXPO,
            'platform' => 'android',
        ]);
        $vendor = Vendor::create(['user_id' => $vendorUser->id, 'status' => VendorStatus::Pending->value]);
        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'business_name' => 'Approve Push Co',
            'owner_name' => 'Owner',
            'email' => $vendorUser->email,
        ]);

        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/vendors/{$vendor->id}/approve", [
            'notes' => 'Documents verified',
        ])->assertOk();

        $approved = VendorRegistrationNotifications::approved();
        $this->assertExpoPushContains(self::VENDOR_EXPO, $approved['title'], 'approved');
    }

    public function test_admin_reject_sends_expo_push_to_vendor(): void
    {
        $this->enableExpoHttpPush();

        $admin = User::factory()->create(['role' => 'admin', 'password' => Hash::make('password')]);
        $admin->assignRole('admin');
        $vendorUser = User::factory()->create(['role' => 'vendor']);
        $vendorUser->assignRole('vendor');
        DevicePushToken::create([
            'user_id' => $vendorUser->id,
            'token' => self::VENDOR_EXPO,
            'platform' => 'android',
        ]);
        $vendor = Vendor::create(['user_id' => $vendorUser->id, 'status' => VendorStatus::Pending->value]);
        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'business_name' => 'Reject Push Co',
            'owner_name' => 'Owner',
            'email' => $vendorUser->email,
        ]);

        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/vendors/{$vendor->id}/reject", [
            'reason' => 'Trade license is not valid',
        ])->assertOk();

        $rejected = VendorRegistrationNotifications::rejected();
        $this->assertExpoPushContains(self::VENDOR_EXPO, $rejected['title'], 'not approved');
    }
}