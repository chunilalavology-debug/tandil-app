<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeletionRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reference',
        'user_id',
        'full_name',
        'email',
        'phone',
        'account_type',
        'reason',
        'status',
        'request_ip',
        'user_agent',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::creating(function (AccountDeletionRequest $request): void {
            if (empty($request->reference)) {
                $request->reference = 'ADR-'.strtoupper(substr(uniqid(), -8));
            }

            if (empty($request->status)) {
                $request->status = self::STATUS_PENDING;
            }
        });
    }
}
