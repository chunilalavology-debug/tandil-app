<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'account_type' => ['required', 'string', Rule::in([
                'client',
                'vendor',
                'worker',
                'supervisor',
                'area_manager',
                'hr',
                'admin',
                'other',
            ])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'confirm_deletion' => ['accepted'],
            'website' => ['nullable', 'max:0'], // honeypot
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_deletion.accepted' => 'Please confirm that you want to permanently delete your TANDIL account.',
            'website.max' => 'Unable to submit this request.',
        ];
    }
}
