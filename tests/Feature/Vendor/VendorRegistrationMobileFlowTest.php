<?php

namespace Tests\Feature\Vendor;

use App\Enums\VendorStatus;
use App\Services\Vendor\VendorRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * End-to-end vendor registration scenarios matching the mobile supplier signup wizard.
 */
class VendorRegistrationMobileFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        if (class_exists(Role::class)) {
            Role::firstOrCreate(['name' => 'vendor', 'guard_name' => 'web']);
        }
    }

    /** @return array<string, mixed> */
    private function mobileWizardPayload(string $email): array
    {
        return [
            'company_name' => 'Ahmed Fresh Market',
            'authorized_person_name' => 'Ahmed',
            'email' => $email,
            'phone' => '+971501234567',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'address' => 'Industrial Area, Sharjah',
            'trade_license_number' => 'TL-998877',
            'vendor_type' => 'fruits',
            'emirate' => 'Dubai',
            'city' => 'Dubai',
            'google_maps_location' => '25.2048,55.2708',
            'bank_name' => 'Emirates NBD',
            'iban' => 'AE267779282929393',
            'account_holder_name' => 'ahmed',
            'delivery_radius' => '25',
            'opens_at' => '06:00',
            'closes_at' => '09:00',
            'minimum_order_amount' => '100',
            'terms_accepted' => '1',
            'logo' => UploadedFile::fake()->image('store-logo.jpg', 800, 800)->size(2500),
            'trade_license' => UploadedFile::fake()->image('trade-license.jpg', 1200, 1600)->size(1800),
            'emirates_id' => UploadedFile::fake()->image('emirates-id.jpg', 1200, 1600)->size(1500),
        ];
    }

    public function test_vendor_registration_persists_optional_expo_push_token(): void
    {
        $email = 'vendor-push-reg-'.uniqid().'@test.com';
        $payload = $this->mobileWizardPayload($email);
        $payload['expo_push_token'] = 'ExponentPushToken[vendor-register-push-save-test]';
        $payload['platform'] = 'android';
        $payload['device_id'] = 'register-flow-device-id';

        $this->post('/api/vendor/auth/register', $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.device_push_token.platform', 'android');

        $this->assertDatabaseHas('device_push_tokens', [
            'token' => 'ExponentPushToken[vendor-register-push-save-test]',
            'platform' => 'android',
            'device_id' => 'register-flow-device-id',
        ]);
    }

    public function test_new_vendor_registers_successfully_with_mobile_wizard_payload(): void
    {
        $email = 'mobile-vendor-'.uniqid().'@test.com';

        $response = $this->post('/api/vendor/auth/register', $this->mobileWizardPayload($email), [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', VendorRegistrationService::REGISTRATION_SUCCESS_MESSAGE)
            ->assertJsonPath('data.status', VendorStatus::Pending->value)
            ->assertJsonPath('data.profile.business_name', 'Ahmed Fresh Market')
            ->assertJsonPath('data.profile.owner_name', 'Ahmed')
            ->assertJsonPath('data.profile.iban', 'AE267779282929393')
            ->assertJsonPath('data.profile.account_holder_name', 'ahmed')
            ->assertJsonPath('data.profile.operating_hours', '06:00 - 09:00')
            ->assertJsonPath('data.profile.minimum_order_amount', '100.00')
            ->assertJsonPath('data.documents.0.type', fn ($type) => in_array($type, ['trade_license', 'emirates_id'], true));

        $this->assertDatabaseHas('users', ['email' => $email, 'role' => 'vendor']);
        $this->assertDatabaseHas('vendor_profiles', [
            'email' => $email,
            'business_name' => 'Ahmed Fresh Market',
            'iban' => 'AE267779282929393',
        ]);
        $this->assertDatabaseHas('vendor_documents', ['type' => 'trade_license']);
        $this->assertDatabaseHas('vendor_documents', ['type' => 'emirates_id']);
    }

    public function test_mobile_registration_accepts_camera_uploads_with_octet_stream_mime(): void
    {
        $email = 'octet-vendor-'.uniqid().'@test.com';
        $payload = $this->mobileWizardPayload($email);
        $payload['trade_license'] = UploadedFile::fake()->create('trade-license.jpg', 500, 'application/octet-stream');
        $payload['emirates_id'] = UploadedFile::fake()->create('emirates-id.jpg', 500, 'application/octet-stream');
        unset($payload['logo']);

        $this->post('/api/vendor/auth/register', $payload, ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_mobile_registration_accepts_delivery_radius_km_alias(): void
    {
        $email = 'radius-alias-'.uniqid().'@test.com';
        $payload = $this->mobileWizardPayload($email);
        unset($payload['delivery_radius']);
        $payload['delivery_radius_km'] = '30';

        $this->post('/api/vendor/auth/register', $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.profile.delivery_radius', '30.00');
    }

    public function test_mobile_registration_works_without_optional_logo(): void
    {
        $email = 'no-logo-'.uniqid().'@test.com';
        $payload = $this->mobileWizardPayload($email);
        unset($payload['logo']);

        $this->post('/api/vendor/auth/register', $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.logo_url', null);
    }
}
