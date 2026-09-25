<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'subscription_id',
        'amount',
        'currency',
        'status',
        'due_at',
        'paid_at',
        'monthly_unit_amount',
        'credit_months_purchased',
        'credit_exhausted_at',
        'renewal_applied_at',
        'period_start',
        'period_end',
        'payment_method',
        'reference',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'credit_exhausted_at' => 'datetime',
            'renewal_applied_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(SubscriptionPaymentConsumption::class);
    }

    public function remainingCreditMonths(): int
    {
        if ($this->credit_months_purchased === null) {
            return 0;
        }

        $consumedCount = $this->relationLoaded('consumptions')
            ? $this->consumptions->count()
            : $this->consumptions()->count();

        return max(0, (int) $this->credit_months_purchased - $consumedCount);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    public function hasRenewalBeenApplied(): bool
    {
        return $this->renewal_applied_at !== null;
    }
}
