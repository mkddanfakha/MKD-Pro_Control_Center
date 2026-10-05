<?php

namespace App\Models;

use App\Exceptions\Commercial\ImmutableCommercialRecordException;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subscription extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRACE_PERIOD = 'grace_period';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_TERMINATED = 'terminated';

    protected $fillable = [
        'installation_id',
        'offer_version_id',
        'amount',
        'currency',
        'status',
        'starts_at',
        'current_period_start',
        'current_period_end',
        'grace_period_ends_at',
        'suspended_at',
        'terminated_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Subscription $subscription): void {
            if ($subscription->isDirty('offer_version_id')) {
                throw new ImmutableCommercialRecordException(
                    'La référence offer_version_id d’un abonnement ne peut pas être modifiée après création.',
                );
            }
        });
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class);
    }

    public function offerSnapshot(): HasOne
    {
        return $this->hasOne(SubscriptionOfferSnapshot::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(SubscriptionPaymentConsumption::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === self::STATUS_GRACE_PERIOD;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isTerminated(): bool
    {
        return $this->status === self::STATUS_TERMINATED;
    }
}
