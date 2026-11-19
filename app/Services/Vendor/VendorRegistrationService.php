<?php

namespace App\Services\Vendor;

use App\Enums\VendorDocumentType;
use App\Enums\VendorStatus;
use App\Models\DevicePushToken;
use App\Support\DevicePushTokenPersistence;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorApprovalLog;
use App\Models\VendorDocument;
use App\Models\VendorProfile;
use App\Services\ImageCompressionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class VendorRegistrationService
{
    public const REGISTRATION_SUCCESS_MESSAGE = 'Thank you! We have received your registration request successfully.';

    public function __construct(
        private readonly VendorDocumentService $documents,
        private readonly VendorApplicationService $application
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile|null>  $documentFiles  type => file
     * @param  VendorStatus|null  $initialStatus  When set (admin create), applied after signup.
     */
    public function register(
        array $data,
        ?UploadedFile $logo = null,
        array $documentFiles = [],
        ?User $adminCreator = null,
        ?VendorStatus $initialStatus = null
    ): Vendor
    {
        // Compress uploads FIRST (under 2 MB) so we never persist huge originals
        // and fail with a clear message before creating the user account.
        $preparedLogo = null;
        $preparedDocs = [];
        try {
            if ($logo) {
                $preparedLogo = ImageCompressionService::storeCompressedPublic($logo, 'vendors/logos');
            }
            foreach ($documentFiles as $type => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }
                if (! in_array($type, VendorDocumentType::values(), true)) {
                    continue;
                }
                $preparedDocs[$type] = [
                    'path' => ImageCompressionService::storeCompressedPublic(
                        $file,
                        'vendors/tmp-docs'
                    ),
                    'original_name' => $file->getClientOriginalName(),
                ];
            }

            $vendor = DB::transaction(function () use ($data, $preparedLogo) {
                $user = User::create([
                    'name' => $data['owner_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => $data['password'],
                    'role' => 'vendor',
                    'status' => 'active',
                ]);
                $this->ensureVendorRole($user);

                $vendor = Vendor::create([
                    'user_id' => $user->id,
                    'status' => VendorStatus::Pending->value,
                ]);

                $profileData = array_merge(
                    $this->mapProfileFields($data),
                    [
                        'vendor_id' => $vendor->id,
                        'business_name' => $data['business_name'] ?? ($data['company_name'] ?? $data['owner_name']),
                        'owner_name' => $data['owner_name'] ?? ($data['authorized_person_name'] ?? $data['name'] ?? 'Contractor'),
                        'email' => $data['email'],
                        'logo_path' => $preparedLogo,
                        'onboarding_completed_at' => now(),
                    ]
                );

                if ($this->termsAccepted($data)) {
                    $profileData['terms_accepted_at'] = now();
                }

                $vendor->setRelation('profile', VendorProfile::create($profileData));
                $vendor->setRelation('user', $user);

                $vendorTypeSlugs = $this->vendorTypeSlugs($data);
                if ($vendorTypeSlugs !== []) {
                    $this->application->syncVendorTypes($vendor, $vendorTypeSlugs);
                }

                if (! empty($data['category_ids']) && is_array($data['category_ids'])) {
                    $this->application->syncCategories($vendor, $data['category_ids']);
                }

                if (! empty($data['subcategory_ids']) && is_array($data['subcategory_ids'])) {
                    $merged = array_values(array_unique(array_merge(
                        array_map('intval', $data['category_ids'] ?? []),
                        array_map('intval', $data['subcategory_ids'])
                    )));
                    $this->application->syncCategories($vendor, $merged);
                }

                if (! empty($data['service_ids']) && is_array($data['service_ids'])) {
                    $this->syncServices($vendor, $data['service_ids']);
                }

                if (! empty($data['area_ids']) && is_array($data['area_ids'])) {
                    $this->syncAreas($vendor, $data['area_ids']);
                }

                VendorApprovalLog::create([
                    'vendor_id' => $vendor->id,
                    'performed_by' => null,
                    'action' => 'submitted_for_review',
                    'old_status' => null,
                    'new_status' => VendorStatus::Pending->value,
                    'notes' => 'Contractor registration submitted for admin review.',
                ]);

                return $vendor;
            });

            $documents = [];
            foreach ($preparedDocs as $type => $prepared) {
                $finalPath = $this->movePreparedDocument($prepared['path'], $vendor->id);
                $documents[] = VendorDocument::create([
                    'vendor_id' => $vendor->id,
                    'type' => $type,
                    'file_path' => $finalPath,
                    'original_name' => $prepared['original_name'],
                    'verification_status' => 'pending',
                ]);
            }
            $vendor->setRelation('documents', collect($documents));

            $vendorId = $vendor->id;
            if ($adminCreator === null) {
                $fresh = Vendor::query()->with(['profile', 'user'])->find($vendorId);
                if ($fresh) {
                    if ($fresh->user) {
                        DevicePushTokenPersistence::upsertForUser($fresh->user, $data);
                    }
                    app(VendorAdminNotifier::class)->newRegistration($fresh);
                    app(VendorVendorNotifier::class)->registrationSubmitted($fresh);
                }
            }

            $targetStatus = $initialStatus ?? VendorStatus::Pending;
            if ($targetStatus !== VendorStatus::Pending) {
                $vendor = app(VendorApprovalService::class)->transition(
                    $vendor->fresh(['profile', 'user']),
                    $targetStatus,
                    $adminCreator,
                    $adminCreator ? 'Vendor created by admin.' : null
                );
            } elseif ($adminCreator !== null) {
                VendorApprovalLog::query()
                    ->where('vendor_id', $vendor->id)
                    ->where('action', 'submitted_for_review')
                    ->latest('id')
                    ->limit(1)
                    ->update([
                        'performed_by' => $adminCreator->id,
                        'notes' => 'Vendor created by admin.',
                    ]);
            }

            return $vendor->fresh(['profile', 'user', 'documents', 'categories', 'vendorTypes']);
        } catch (\Throwable $e) {
            $this->cleanupPreparedPaths(array_filter([
                $preparedLogo,
                ...array_column($preparedDocs, 'path'),
            ]));
            throw $e;
        }
    }

    /**
     * Lean payload for the mobile thank-you screen (avoids extra relation queries).
     *
     * @return array<string, mixed>
     */
    public function registrationResponsePayload(Vendor $vendor): array
    {
        $profile = $vendor->profile;
        $documents = $vendor->relationLoaded('documents')
            ? $vendor->documents
            : $vendor->documents()->get(['id', 'vendor_id', 'type', 'file_path', 'original_name', 'verification_status', 'created_at', 'updated_at']);

        $docCount = $documents->count();
        // Full wizard submit ≈ profile + terms + required docs; categories optional.
        $completion = $docCount >= 2 ? 95 : ($docCount === 1 ? 85 : 75);

        $push = null;
        if ($vendor->user_id) {
            $push = DevicePushToken::query()
                ->where('user_id', $vendor->user_id)
                ->latest('id')
                ->first(['id', 'platform', 'device_id', 'created_at']);
        }

        return [
            'vendor_id' => $vendor->id,
            'status' => $vendor->status,
            'logo_url' => $vendor->logo_url,
            'profile' => $profile,
            'documents' => $documents->values(),
            'completion_percent' => $completion,
            'device_push_token' => $push ? [
                'id' => $push->id,
                'platform' => $push->platform,
                'device_id' => $push->device_id,
                'registered_at' => $push->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function movePreparedDocument(string $relativePath, int $vendorId): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $filename = basename($relativePath);
        $destination = "vendors/{$vendorId}/documents/{$filename}";

        if ($relativePath !== $destination && Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->move($relativePath, $destination);

            return $destination;
        }

        return $relativePath;
    }

    /**
     * @param  list<string|null>  $paths
     */
    private function cleanupPreparedPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
    }

    /**
     * @param  array<string, UploadedFile|null>  $documentFiles  type => file
     */
    public function updateProfile(Vendor $vendor, array $data, ?UploadedFile $logo = null, bool $removeLogo = false, array $documentFiles = []): Vendor
    {
        return DB::transaction(function () use ($vendor, $data, $logo, $removeLogo, $documentFiles) {
            $profile = $vendor->profile;

            // Only persist fields that were actually provided so partial edits don't wipe data.
            $updates = $this->mapProfileFields($data);
            foreach (['business_name', 'owner_name', 'email'] as $core) {
                if (array_key_exists($core, $data) && $data[$core] !== null) {
                    $updates[$core] = $data[$core];
                }
            }

            if ($this->termsAccepted($data) && $profile->terms_accepted_at === null) {
                $updates['terms_accepted_at'] = now();
            }

            if ($removeLogo && $profile->logo_path) {
                Storage::disk('public')->delete($profile->logo_path);
                $updates['logo_path'] = null;
            }
            if ($logo) {
                if ($profile->logo_path) {
                    Storage::disk('public')->delete($profile->logo_path);
                }
                $updates['logo_path'] = $this->storeAndOptimizeImage($logo, 'vendors/logos');
            }

            if (! empty($updates)) {
                $profile->update($updates);
            }

            if (array_key_exists('vendor_type', $data)) {
                $vendorTypeSlugs = $this->vendorTypeSlugs($data);
                if ($vendorTypeSlugs !== []) {
                    $this->application->syncVendorTypes($vendor, $vendorTypeSlugs);
                }
            }

            if (isset($data['category_ids']) && is_array($data['category_ids'])) {
                $this->application->syncCategories($vendor, $data['category_ids']);
            }

            $userUpdates = [];
            if (array_key_exists('owner_name', $data) && $data['owner_name'] !== null && $data['owner_name'] !== '') {
                $userUpdates['name'] = $data['owner_name'];
            }
            if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== '') {
                $userUpdates['email'] = $data['email'];
            }
            if (array_key_exists('phone', $data)) {
                $userUpdates['phone'] = $data['phone'] === '' ? null : $data['phone'];
            }
            if (! empty($data['password'])) {
                $userUpdates['password'] = $data['password'];
            }
            if ($userUpdates !== []) {
                $vendor->user->update($userUpdates);
            }

            foreach ($documentFiles as $type => $file) {
                if ($file instanceof UploadedFile) {
                    $this->documents->upload($vendor, $type, $file);
                }
            }

            return $vendor->fresh(['profile', 'user', 'documents', 'categories', 'vendorTypes']);
        });
    }

    /**
     * Vendor self-service Edit Profile update (API) — mobile UI fields only.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateEditableProfile(
        Vendor $vendor,
        array $data,
        ?UploadedFile $profilePicture = null,
        ?UploadedFile $logo = null
    ): Vendor {
        $data = $this->mapEditProfileInput($data);

        return DB::transaction(function () use ($vendor, $data, $profilePicture, $logo) {
            $profile = $vendor->profile;
            $updates = [];

            foreach ([
                'business_name',
                'owner_name',
                'phone',
                'description',
                'address',
                'city',
                'operating_hours',
                'delivery_radius',
                'minimum_order_amount',
                'bank_name',
                'iban',
                'account_holder_name',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field] === '' ? null : $data[$field];
                }
            }

            if ($profilePicture) {
                if ($profile->profile_picture_path) {
                    Storage::disk('public')->delete($profile->profile_picture_path);
                }
                $updates['profile_picture_path'] = $this->storeAndOptimizeImage(
                    $profilePicture,
                    'vendors/profile-pictures'
                );
            }

            if ($logo) {
                if ($profile->logo_path) {
                    Storage::disk('public')->delete($profile->logo_path);
                }
                $updates['logo_path'] = $this->storeAndOptimizeImage($logo, 'vendors/logos');
            }

            if (! empty($updates)) {
                $profile->update($updates);
            }

            $userUpdates = [];
            if (array_key_exists('owner_name', $data) && $data['owner_name'] !== null && $data['owner_name'] !== '') {
                $userUpdates['name'] = $data['owner_name'];
            }
            if (array_key_exists('phone', $data)) {
                $userUpdates['phone'] = $data['phone'] === '' ? null : $data['phone'];
            }
            if ($userUpdates !== []) {
                $vendor->user->update($userUpdates);
            }

            return $vendor->fresh(['profile', 'user']);
        });
    }

    private function storeAndOptimizeImage(UploadedFile $file, string $directory): string
    {
        // Compress under 2 MB before persisting — keeps DB/media lean and APIs fast.
        return ImageCompressionService::storeCompressedPublic($file, $directory);
    }

    private function ensureVendorRole(User $user): void
    {
        try {
            if (! class_exists(Role::class)) {
                return;
            }

            $role = Role::findOrCreate('vendor', 'web');
            // Fast path: skip Spatie assignRole (permission cache flush) on hot signup.
            DB::table(config('permission.table_names.model_has_roles'))->insertOrIgnore([
                'role_id' => $role->id,
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->getKey(),
            ]);
        } catch (\Throwable) {
            // Spatie optional / already attached via users.role
        }
    }

    /**
     * Map Edit Profile UI field names to database columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapEditProfileInput(array $data): array
    {
        if (array_key_exists('contact_person', $data)) {
            $data['owner_name'] = $data['contact_person'];
            unset($data['contact_person']);
        }

        if (array_key_exists('store_description', $data)) {
            $data['description'] = $data['store_description'];
            unset($data['store_description']);
        }

        if (array_key_exists('delivery_radius_km', $data)) {
            $data['delivery_radius'] = $data['delivery_radius_km'];
            unset($data['delivery_radius_km']);
        }

        $opensAt = trim((string) ($data['opens_at'] ?? ''));
        $closesAt = trim((string) ($data['closes_at'] ?? ''));

        if ($opensAt !== '' && $closesAt !== '') {
            $data['operating_hours'] = $opensAt.' - '.$closesAt;
        }

        unset($data['opens_at'], $data['closes_at']);

        return $data;
    }

    /**
     * Map incoming request data to vendor_profiles columns, keeping only provided keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapProfileFields(array $data): array
    {
        $fields = [
            'phone',
            'trade_license_number',
            'trade_license_expiry',
            'emirate',
            'city',
            'city_id',
            'address',
            'google_maps_location',
            'bank_name',
            'bank_id',
            'iban',
            'bank_account_number',
            'account_holder_name',
            'delivery_radius',
            'operating_hours',
            'minimum_order_amount',
            'tax_vat_number',
            'description',
            'years_in_business',
        ];

        $mapped = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $mapped[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }

        if (! empty($mapped['bank_id']) && empty($mapped['bank_name']) && \Illuminate\Support\Facades\Schema::hasTable('contractor_banks')) {
            $mapped['bank_name'] = \App\Models\ContractorBank::query()->whereKey($mapped['bank_id'])->value('name');
        }

        if (! empty($mapped['city_id']) && empty($mapped['city']) && \Illuminate\Support\Facades\Schema::hasTable('contractor_cities')) {
            $city = \App\Models\ContractorCity::query()->find($mapped['city_id']);
            if ($city) {
                $mapped['city'] = $city->name;
                if (empty($mapped['emirate']) && $city->emirate) {
                    $mapped['emirate'] = $city->emirate->name;
                }
            }
        }

        if (! empty($mapped['emirate']) && is_numeric($mapped['emirate'])) {
            $mapped['emirate'] = \App\Models\Emirate::query()->whereKey((int) $mapped['emirate'])->value('name')
                ?? $mapped['emirate'];
        } elseif (! empty($mapped['emirate'])) {
            $mapped['emirate'] = \App\Models\Emirate::resolveToName($mapped['emirate']) ?? $mapped['emirate'];
        }

        // vendor_type may be a single slug or an array of slugs (multi-select). The
        // vendor_profiles column only ever stores the primary/first slug for backward
        // compatible display; the full selection is synced to vendor_vendor_type — see
        // syncVendorTypesFromData() below.
        if (array_key_exists('vendor_type', $data)) {
            $mapped['vendor_type'] = $this->vendorTypeSlugs($data)[0] ?? null;
        }

        // Accept "vat_number" as alias for tax_vat_number.
        if (! array_key_exists('tax_vat_number', $mapped) && array_key_exists('vat_number', $data)) {
            $mapped['tax_vat_number'] = $data['vat_number'] === '' ? null : $data['vat_number'];
        }

        return $mapped;
    }

    /**
     * Normalize the request's vendor_type input (single slug or array of slugs) to a list.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function vendorTypeSlugs(array $data): array
    {
        $raw = $data['vendor_type'] ?? null;

        if (is_array($raw)) {
            return array_values(array_filter($raw, fn ($slug) => is_string($slug) && $slug !== ''));
        }

        return is_string($raw) && $raw !== '' ? [$raw] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function termsAccepted(array $data): bool
    {
        if (! array_key_exists('terms_accepted', $data)) {
            return false;
        }

        return filter_var($data['terms_accepted'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Collect document files from request using standard and legacy field names.
     *
     * @return array<string, UploadedFile>
     */
    public static function documentFilesFromRequest(\Illuminate\Http\Request $request): array
    {
        $files = [];
        foreach (VendorDocumentType::values() as $type) {
            if ($request->hasFile($type)) {
                $files[$type] = $request->file($type);
            }
        }
        if ($request->hasFile('business_proof') && ! isset($files[VendorDocumentType::BusinessLicense->value])) {
            $files[VendorDocumentType::BusinessLicense->value] = $request->file('business_proof');
        }
        if ($request->hasFile('vat_certificate') && ! isset($files[VendorDocumentType::VatCertificate->value])) {
            $files[VendorDocumentType::VatCertificate->value] = $request->file('vat_certificate');
        }
        if ($request->hasFile('bank_confirmation_letter') && ! isset($files[VendorDocumentType::BankConfirmationLetter->value])) {
            $files[VendorDocumentType::BankConfirmationLetter->value] = $request->file('bank_confirmation_letter');
        }

        return $files;
    }

    /**
     * @param  list<int|string>  $serviceIds
     */
    private function syncServices(Vendor $vendor, array $serviceIds): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('service_vendor')) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $serviceIds))));
        $vendor->services()->sync($ids);
    }

    /**
     * @param  list<int|string>  $areaIds
     */
    private function syncAreas(Vendor $vendor, array $areaIds): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('vendor_area')) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $areaIds))));
        $vendor->areas()->sync($ids);
    }
}
