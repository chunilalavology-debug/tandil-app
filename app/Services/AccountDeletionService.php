<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\DevicePushToken;
use App\Models\Order;
use App\Models\ShopAppliedCheckoutCoupon;
use App\Models\ShopMobileCheckout;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\WalletCredit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AccountDeletionService
{
    /**
     * Permanently delete a client account and associated personal data.
     * Order history is retained for business records but unlinked from the user (user_id → null).
     */
    public function deleteClientAccount(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $userId = (int) $user->id;

            $user->tokens()->delete();
            DevicePushToken::query()->where('user_id', $userId)->delete();

            if (method_exists($user, 'notifications')) {
                $user->notifications()->delete();
            }

            Cart::query()->where('user_id', $userId)->delete();
            UserPaymentMethod::query()->where('user_id', $userId)->delete();
            WalletCredit::query()->where('user_id', $userId)->delete();
            ShopMobileCheckout::query()->where('user_id', $userId)->delete();
            ShopAppliedCheckoutCoupon::query()->where('user_id', $userId)->delete();

            $this->scrubLinkedOrderPersonalData($userId);

            $picture = $user->profile_picture;
            if (is_string($picture) && $picture !== '') {
                Storage::disk('public')->delete($picture);
            }

            if (method_exists($user, 'roles')) {
                $user->syncRoles([]);
            }

            $user->delete();
        });
    }

    private function scrubLinkedOrderPersonalData(int $userId): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $updates = ['user_id' => null];
        $order = new Order;
        foreach ([
            'guest_email',
            'guest_full_name',
            'guest_phone',
            'guest_street_address',
            'guest_city',
            'guest_state',
            'guest_zip_code',
            'guest_country',
        ] as $column) {
            if (Schema::hasColumn($order->getTable(), $column)) {
                $updates[$column] = null;
            }
        }

        Order::query()->where('user_id', $userId)->update($updates);
    }
}
