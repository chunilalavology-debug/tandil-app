<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'google_id',
        'apple_id',
        'extra_emails',
        'phone',
        'extra_phones',
        'profile_picture',
        'password',
        'role',    // optional: quick reference
        'preferred_locale',
        'status',  // active / inactive
        'wallet_balance',
        'wallet_forfeited_total',
        'loyalty_points_balance',
    ];

    protected $appends = [
        'profile_picture_url',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'extra_emails' => 'array',
        'extra_phones' => 'array',
        'preferred_locale' => 'string',
        'wallet_balance' => 'decimal:2',
        'wallet_forfeited_total' => 'decimal:2',
        'loyalty_points_balance' => 'integer',
    ];

    /**
     * Full URL for profile picture (stored path under storage/app/public, served via /media/).
     * Used for all roles (technician, client, admin, etc.).
     */
    public function getProfilePictureUrlAttribute(): ?string
    {
        $path = $this->attributes['profile_picture'] ?? null;
        if (empty($path) || ! is_string($path)) {
            return null;
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return request()->getSchemeAndHttpHost() ? (rtrim(request()->getSchemeAndHttpHost(), '/').'/media/'.$path) : asset('media/'.$path);
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    // HR employee record
    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    // Client → Subscriptions
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'client_id');
    }

    // Technician → Visits assigned
    public function visits()
    {
        return $this->hasMany(Visit::class, 'technician_id');
    }

    public function devicePushTokens()
    {
        return $this->hasMany(DevicePushToken::class);
    }

    // Technician → Availability, breaks, vacations
    public function technicianAvailability()
    {
        return $this->hasOne(TechnicianAvailability::class);
    }

    public function technicianBreaks()
    {
        return $this->hasMany(TechnicianBreak::class);
    }

    public function technicianVacations()
    {
        return $this->hasMany(TechnicianVacation::class);
    }

    public function technicianBankAccounts()
    {
        return $this->hasMany(TechnicianBankAccount::class);
    }

    // Shopping cart
    public function cart()
    {
        return $this->hasMany(Cart::class);
    }

    // Orders
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function vendor()
    {
        return $this->hasOne(Vendor::class);
    }

    public function walletCredits()
    {
        return $this->hasMany(WalletCredit::class);
    }

    /*
    |--------------------------------------------------------------------------
    | SUPERVISOR RELATIONS
    |--------------------------------------------------------------------------
    */

    // Areas supervised by this user
    public function supervisedAreas()
    {
        return $this->belongsToMany(Area::class, 'area_supervisor', 'user_id', 'area_id');
    }

    /** Get IDs of areas supervised by this user (avoids ambiguous column in join). */
    public function supervisedAreaIds(): array
    {
        return $this->supervisedAreas()->selectRaw('areas.id as id')->pluck('id')->toArray();
    }

    /** Technician: zones (areas) this user is assigned to (area_technician). */
    public function assignedAreas()
    {
        return $this->belongsToMany(Area::class, 'area_technician', 'user_id', 'area_id');
    }

    /** Saved reusable payment methods (PayPal vault token / card token, etc). */
    public function paymentMethods()
    {
        return $this->hasMany(UserPaymentMethod::class);
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    /** Scope: only users with status = active (for listings where inactive should be hidden). */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'active')
                ->orWhereNull('status');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | ROLE HELPERS
    |--------------------------------------------------------------------------
    */

    /** App login / Sanctum: allowed portal slugs (token abilities). */
    public const LOGIN_PORTALS = ['client', 'technician', 'supervisor', 'area_manager', 'hr', 'admin', 'vendor'];

    /**
     * One portal slug for API token after login (Spatie first, same priority as web dashboard.redirect).
     */
    public function resolvedLoginPortal(): ?string
    {
        $ordered = ['admin', 'hr', 'area_manager', 'supervisor', 'technician', 'vendor', 'client'];

        try {
            $names = $this->getRoleNames();
            if ($names->isNotEmpty()) {
                foreach ($ordered as $candidate) {
                    if ($names->contains($candidate)) {
                        return $candidate;
                    }
                }
                foreach ($names as $name) {
                    if (is_string($name) && in_array($name, self::LOGIN_PORTALS, true)) {
                        return $name;
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to users.role
        }

        $col = $this->role ?? null;

        return is_string($col) && in_array($col, self::LOGIN_PORTALS, true) ? $col : null;
    }

    /**
     * Normalized users.role slug (lowercase), or null when missing/unknown.
     */
    public function normalizedAppRoleColumn(): ?string
    {
        $role = $this->role;
        if (! is_string($role) || trim($role) === '') {
            return null;
        }

        $normalized = strtolower(trim($role));

        return in_array($normalized, self::LOGIN_PORTALS, true) ? $normalized : null;
    }

    /**
     * Whether this user is the given app role (users.role column or Spatie role).
     * Matches logic used by web login redirects and CheckRole middleware.
     */
    public function hasAppRole(string $roleName): bool
    {
        $roleName = strtolower(trim($roleName));

        if ($this->normalizedAppRoleColumn() === $roleName) {
            return true;
        }

        try {
            if ($this->hasRole($roleName)) {
                return true;
            }

            return $this->getRoleNames()
                ->map(static fn ($name) => strtolower((string) $name))
                ->contains($roleName);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * API login portal check: when Spatie roles exist they are authoritative.
     * Avoids accepting portal=client because users.role stayed the DB default "client"
     * while the account was assigned area_manager (or another role) in Spatie only.
     */
    public function matchesLoginPortal(string $portal): bool
    {
        $portal = strtolower(trim($portal));

        try {
            $names = $this->getRoleNames();
            if ($names->isNotEmpty()) {
                return $names
                    ->map(static fn ($name) => strtolower((string) $name))
                    ->contains($portal);
            }
        } catch (\Throwable $e) {
            // fall through to users.role when Spatie tables are unavailable
        }

        return $this->normalizedAppRoleColumn() === $portal;
    }

    /**
     * True when the client still needs to add a phone (common after Google/Apple signup).
     */
    public function needsPhone(): bool
    {
        return trim((string) ($this->phone ?? '')) === '';
    }

    /**
     * Lean payload for login / social auth responses (avoids serializing the full model).
     *
     * @return array<string, mixed>
     */
    public function toLoginArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'google_id' => $this->google_id,
            'apple_id' => $this->apple_id,
            'phone' => $this->phone,
            'needs_phone' => $this->needsPhone(),
            'role' => $this->role,
            'status' => $this->status,
            'profile_picture_url' => $this->profile_picture_url,
            'preferred_locale' => $this->preferred_locale,
            'wallet_balance' => $this->wallet_balance,
            'email_verified_at' => $this->email_verified_at,
        ];
    }

    public function isTechnician()
    {
        return $this->hasRole('technician');
    }

    public function isSupervisor()
    {
        return $this->hasRole('supervisor');
    }

    public function isAreaManager()
    {
        return $this->hasRole('area_manager');
    }

    public function isAdmin()
    {
        return $this->hasRole('admin');
    }

    public function isClient()
    {
        return $this->hasRole('client');
    }

    public function isVendor(): bool
    {
        return $this->hasAppRole('vendor');
    }
}
