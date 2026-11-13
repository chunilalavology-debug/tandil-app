<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DevicePushToken;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DevicePushTokenController extends Controller
{
    /**
     * Register or refresh an FCM device token for the authenticated user (any role).
     * Mobile apps should call this after login and whenever the FCM token refreshes.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['nullable', 'string', Rule::in(['android', 'ios', 'web', 'unknown'])],
            'device_id' => ['nullable', 'string', 'max:191'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:16'],
        ]);

        $user = $request->user();
        $token = trim($validated['token']);
        $platform = $validated['platform'] ?? 'unknown';

        // A physical device token belongs to one account at a time.
        DevicePushToken::query()->where('token', $token)->where('user_id', '!=', $user->id)->delete();

        $record = DevicePushToken::query()->updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'device_id' => $validated['device_id'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'locale' => $validated['locale'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return ApiResponse::success('Device push token registered.', [
            'id' => $record->id,
            'platform' => $record->platform,
            'device_id' => $record->device_id,
        ]);
    }

    /**
     * Remove a device token (logout / revoke notifications on this device).
     */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $deleted = DevicePushToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', trim($validated['token']))
            ->delete();

        return ApiResponse::success(
            $deleted ? 'Device push token removed.' : 'Token not found for this account.',
            ['deleted' => (bool) $deleted]
        );
    }
}
