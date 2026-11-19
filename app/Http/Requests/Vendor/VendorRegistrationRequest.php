<?php

namespace App\Http\Requests\Vendor;

use App\Support\DevicePushTokenPersistence;
use App\Support\PasswordInput;
use App\Support\UserCredentialRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class VendorRegistrationRequest extends VendorProfileFormRequest
{
    /** @var list<string> */
    private const IMAGE_EXTENSIONS = 'jpeg,jpg,png,gif,webp';

    /** @var list<string> */
    private const DOCUMENT_EXTENSIONS = 'pdf,jpeg,jpg,png,webp';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize marketplace vendor signup aliases (not supervisor/contractor signup).
     */
    protected function prepareForValidation(): void
    {
        PasswordInput::normalize($this);
        $this->normalizeLegacyFieldAliases();
        $this->normalizeRegistrationFileAliases();
        $this->normalizeSingleFileUploads([
            'logo',
            'trade_license',
            'trade_license_upload',
            'emirates_id',
            'vat_certificate',
            'bank_confirmation_letter',
        ]);
        $this->ensureUploadFileExtensions([
            'logo',
            'trade_license',
            'trade_license_upload',
            'emirates_id',
            'vat_certificate',
            'bank_confirmation_letter',
        ]);

        if ($this->files->has('trade_license_upload') && ! $this->files->has('trade_license')) {
            $this->files->set('trade_license', $this->file('trade_license_upload'));
        }

        parent::prepareForValidation();

        $push = $this->input('token');
        if (! is_string($push) || trim($push) === '') {
            foreach (['expo_push_token', 'push_token', 'fcm_token', 'expoPushToken', 'pushToken', 'deviceToken'] as $key) {
                $candidate = $this->input($key);
                if (is_string($candidate) && trim($candidate) !== '') {
                    $this->merge(['token' => trim($candidate)]);
                    break;
                }
            }
        }

        if ($this->filled('platform')) {
            $this->merge(['platform' => strtolower(trim((string) $this->input('platform')))]);
        }

        $name = trim((string) ($this->input('name') ?: $this->input('owner_name') ?: ''));
        if ($name !== '') {
            $this->merge([
                'owner_name' => $this->input('owner_name') ?: $name,
                'name' => $this->input('name') ?: $name,
            ]);
        }

        if ($this->filled('company_name') && ! $this->filled('business_name')) {
            $this->merge(['business_name' => $this->input('company_name')]);
        }

        // Unknown mobile picker values should not block signup — prefer active "other" row.
        $vendorType = $this->input('vendor_type');
        $allowedVendorTypeSlugs = $this->allowedVendorTypeSlugs();
        $hasValidVendorTypeSelection = is_array($vendorType)
            ? array_intersect($vendorType, $allowedVendorTypeSlugs) !== []
            : in_array($vendorType, $allowedVendorTypeSlugs, true);

        if ($this->filled('vendor_type') && ! $hasValidVendorTypeSelection) {
            $fallback = \App\Models\VendorType::query()->active()->where('slug', 'other')->value('slug')
                ?? (\App\Models\VendorType::query()->active()->orderBy('id')->value('slug'))
                ?? 'other';
            $this->merge(['vendor_type' => is_array($vendorType) ? [$fallback] : $fallback]);
        }
    }

    protected function normalizeLegacyFieldAliases(): void
    {
        $map = [
            'company_address' => 'address',
            'trade_license_expiry_date' => 'trade_license_expiry',
            'trn' => 'tax_vat_number',
        ];

        foreach ($map as $from => $to) {
            if ($this->exists($from) && ! $this->filled($to)) {
                $this->merge([$to => $this->input($from)]);
            }
        }

        if ($this->filled('city') && is_numeric($this->input('city'))) {
            $city = \App\Models\ContractorCity::query()->find((int) $this->input('city'));
            if ($city) {
                $this->merge([
                    'city_id' => $city->id,
                    'city' => $city->name,
                ]);
            }
        }

        if ($this->filled('bank_name') && is_numeric($this->input('bank_name'))) {
            $bank = \App\Models\ContractorBank::query()->find((int) $this->input('bank_name'));
            if ($bank) {
                $this->merge([
                    'bank_id' => $bank->id,
                    'bank_name' => $bank->name,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $shared = $this->businessProfileRules(true);
        $shared['city'] = ['nullable', 'string', 'max:100'];
        $shared['vendor_type'] = ['nullable'];
        $shared['google_maps_location'] = ['nullable', 'string', 'max:500'];
        $shared['delivery_radius'] = ['nullable', 'numeric', 'min:0', 'max:10000'];
        $shared['operating_hours'] = ['nullable', 'string', 'max:500'];
        $shared['minimum_order_amount'] = ['nullable', 'numeric', 'min:0', 'max:1000000'];
        $shared['bank_name'] = ['nullable', 'string', 'max:191'];

        return array_merge($shared, [
            'company_name' => ['sometimes', 'string', 'max:255'],
            'authorized_person_name' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                UserCredentialRules::uniqueEmail('vendor'),
                Rule::unique('vendor_profiles', 'email'),
            ],
            'phone' => ['required', 'string', 'max:32', UserCredentialRules::uniquePhone('vendor')],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'terms_accepted' => ['required', 'accepted'],
            'logo' => ['nullable', 'file', 'max:102400', 'extensions:'.self::IMAGE_EXTENSIONS],
            'trade_license' => ['required', 'file', 'max:102400', 'extensions:'.self::DOCUMENT_EXTENSIONS],
            'emirates_id' => ['nullable', 'file', 'max:102400', 'extensions:'.self::DOCUMENT_EXTENSIONS],
            'vat_certificate' => ['nullable', 'file', 'max:102400', 'extensions:'.self::DOCUMENT_EXTENSIONS],
            'bank_confirmation_letter' => ['nullable', 'file', 'max:102400', 'extensions:'.self::DOCUMENT_EXTENSIONS],
            'trade_license_expiry' => ['nullable', 'date'],
            'bank_id' => ['nullable', 'integer', 'exists:contractor_banks,id'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'city_id' => ['nullable', 'integer', 'exists:contractor_cities,id'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
            'area_ids' => ['nullable', 'array'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
            'coverage_emirate_ids' => ['nullable', 'array'],
            'coverage_emirate_ids.*' => ['integer', 'exists:emirates,id'],
            'coverage_city_ids' => ['nullable', 'array'],
            'coverage_city_ids.*' => ['integer', 'exists:contractor_cities,id'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ], DevicePushTokenPersistence::optionalRegistrationRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.unique' => 'This email is already registered. Please log in or use a different email.',
            'phone.unique' => 'This phone number is already registered. Please log in or use a different phone number.',
            'trade_license.required' => 'Trade license document is required.',
            'bank_confirmation_letter.required' => 'Bank account confirmation letter is required.',
            'category_ids.required' => 'Select at least one main service category.',
            'service_ids.required' => 'Select at least one available service.',
            'area_ids.required' => 'Select at least one service coverage area.',
            'coverage_emirate_ids.required' => 'Select at least one emirate for coverage.',
        ]);
    }

    protected function normalizeRegistrationFileAliases(): void
    {
        $aliases = [
            'company_logo' => 'logo',
            'logo_image' => 'logo',
            'trade_license_file' => 'trade_license',
            'trade_license_document' => 'trade_license',
            'trade_license_upload' => 'trade_license',
            'emirates_id_file' => 'emirates_id',
            'emirates_id_document' => 'emirates_id',
        ];

        foreach ($aliases as $from => $to) {
            if (! $this->files->has($from)) {
                continue;
            }

            $aliasFile = $this->files->get($from);

            if (is_array($aliasFile) && isset($aliasFile[0]) && $aliasFile[0] instanceof UploadedFile) {
                $aliasFile = $aliasFile[0];
            }

            if ($aliasFile instanceof UploadedFile) {
                $this->files->set($to, $aliasFile);
            }
        }
    }

    /**
     * @param  list<string>  $keys
     */
    protected function normalizeSingleFileUploads(array $keys): void
    {
        foreach ($keys as $key) {
            $file = $this->file($key);

            if (is_array($file) && isset($file[0]) && $file[0] instanceof UploadedFile) {
                $this->files->set($key, $file[0]);
            }
        }
    }

    /**
     * @param  list<string>  $keys
     */
    protected function ensureUploadFileExtensions(array $keys): void
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'application/pdf' => 'pdf',
        ];

        foreach ($keys as $key) {
            $file = $this->file($key);
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $original = (string) $file->getClientOriginalName();
            $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
            if ($ext !== '') {
                continue;
            }

            $clientMime = strtolower((string) ($file->getClientMimeType() ?: ''));
            $detectedMime = strtolower((string) ($file->getMimeType() ?: ''));
            $inferred = $mimeMap[$clientMime] ?? $mimeMap[$detectedMime] ?? null;
            if ($inferred === null) {
                continue;
            }

            $base = pathinfo($original !== '' ? $original : $key, PATHINFO_FILENAME) ?: $key;
            $newName = $base.'.'.$inferred;

            if ($file instanceof \Illuminate\Http\Testing\File) {
                $replacement = new \Illuminate\Http\Testing\File($newName, $file->tempFile);
                $replacement->sizeToReport = $file->sizeToReport;
                $replacement->mimeTypeToReport = $file->mimeTypeToReport
                    ?: ($clientMime !== '' ? $clientMime : $detectedMime);
                $this->files->set($key, $replacement);
            } else {
                $renamed = new UploadedFile(
                    $file->getPathname(),
                    $newName,
                    $clientMime !== '' ? $clientMime : ($detectedMime !== '' ? $detectedMime : null),
                    $file->getError(),
                    true
                );
                $this->files->set($key, $renamed);
            }
        }

        $this->convertedFiles = null;
    }
}
