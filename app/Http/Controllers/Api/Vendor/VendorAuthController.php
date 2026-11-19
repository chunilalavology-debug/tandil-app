<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\VendorRegistrationRequest;
use App\Services\Vendor\VendorRegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class VendorAuthController extends Controller
{
    public function __construct(
        private readonly VendorRegistrationService $registration
    ) {}

    public function register(VendorRegistrationRequest $request): JsonResponse
    {
        try {
            $vendor = $this->registration->register(
                array_merge(
                    $request->validated(),
                    $request->only([
                        'token',
                        'expo_push_token',
                        'push_token',
                        'fcm_token',
                        'deviceToken',
                        'pushToken',
                        'expoPushToken',
                        'platform',
                        'device_id',
                        'app_version',
                        'locale',
                    ])
                ),
                $request->file('logo'),
                VendorRegistrationService::documentFilesFromRequest($request)
            );

            return ApiResponse::success(
                VendorRegistrationService::REGISTRATION_SUCCESS_MESSAGE,
                $this->registration->registrationResponsePayload($vendor),
                201
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422, [
                'upload' => [$e->getMessage()],
            ]);
        } catch (QueryException $e) {
            $sql = $e->getMessage();
            if (
                str_contains($sql, 'users_phone_unique')
                || str_contains($sql, 'users_phone_role_unique')
                || (str_contains($sql, 'Duplicate entry') && str_contains($sql, 'phone'))
            ) {
                $msg = 'This phone number is already registered. Please log in or use a different phone number.';

                return ApiResponse::error($msg, 422, ['phone' => [$msg]]);
            }
            if (
                str_contains($sql, 'users_email_unique')
                || str_contains($sql, 'users_email_role_unique')
                || (str_contains($sql, 'Duplicate entry') && str_contains($sql, 'email'))
            ) {
                $msg = 'This email is already registered. Please log in or use a different email.';

                return ApiResponse::error($msg, 422, ['email' => [$msg]]);
            }

            Log::error('Vendor registration DB error: '.$e->getMessage());

            return ApiResponse::error(
                'Registration could not be saved. Please check your details and try again.',
                500
            );
        } catch (\Throwable $e) {
            Log::error('Vendor registration failed: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);

            return ApiResponse::error(
                'Registration failed: '.$this->publicFailureReason($e),
                500
            );
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::success('Logged out successfully.');
    }

    /**
     * Validate the current Bearer token and return vendor session context.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendor = $request->attributes->get('vendor') ?? $user?->vendor?->loadMissing('profile');

        return ApiResponse::success('Authenticated.', [
            'user' => $user?->toLoginArray(),
            'vendor' => $vendor ? [
                'vendor_id' => $vendor->id,
                'status' => $vendor->status,
                'is_approved' => $vendor->isApproved(),
                'business_name' => $vendor->profile?->business_name,
            ] : null,
        ]);
    }

    private function publicFailureReason(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'Please check your internet connection, photos/documents, and form details, then try again.';
        }

        // Keep mobile-facing text short and actionable (never dump SQL).
        if (str_contains($message, 'SQLSTATE') || str_contains($message, 'SQL:')) {
            return 'A database error occurred while saving your registration. Please try again.';
        }

        if (strlen($message) > 220) {
            return 'Please check your photos/documents and form details, then try again.';
        }

        return $message;
    }
}
