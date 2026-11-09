<?php

namespace App\Services;

use App\Mail\AccountDeletionRequestMail;
use App\Models\AccountDeletionRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccountDeletionRequestService
{
    /**
     * Store a public account-deletion request and notify support.
     * Does not delete the account immediately — identity is verified first.
     *
     * @param  array{full_name:string,email:string,phone?:string|null,account_type:string,reason?:string|null}  $data
     */
    public function submit(array $data, Request $request): AccountDeletionRequest
    {
        $email = strtolower(trim($data['email']));
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;
        if ($phone === '') {
            $phone = null;
        }

        $matchedUser = $this->findMatchingUser($email, $phone);

        $deletionRequest = AccountDeletionRequest::query()->create([
            'user_id' => $matchedUser?->id,
            'full_name' => trim($data['full_name']),
            'email' => $email,
            'phone' => $phone,
            'account_type' => $data['account_type'],
            'reason' => isset($data['reason']) ? trim((string) $data['reason']) : null,
            'status' => AccountDeletionRequest::STATUS_PENDING,
            'request_ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512) ?: null,
        ]);

        $this->notifySupport($deletionRequest);

        return $deletionRequest;
    }

    private function findMatchingUser(string $email, ?string $phone): ?User
    {
        $byEmail = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($byEmail) {
            return $byEmail;
        }

        if ($phone === null) {
            return null;
        }

        $normalized = trim($phone);

        return User::query()
            ->where(function ($query) use ($normalized): void {
                $query->where('phone', $normalized)
                    ->orWhere('phone', ltrim($normalized, '+'));
            })
            ->first();
    }

    private function notifySupport(AccountDeletionRequest $deletionRequest): void
    {
        $supportEmail = Setting::get('contact_email', 'support@tandil.ae');

        try {
            Mail::to($supportEmail)->send(new AccountDeletionRequestMail($deletionRequest));
        } catch (\Throwable $e) {
            Log::warning('Failed to email account deletion request', [
                'reference' => $deletionRequest->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
