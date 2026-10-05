<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionReminder extends Model
{
    public const TYPE_SUBSCRIPTION_EXPIRY = 'subscription_expiry';

    public const STATUS_DETECTED = 'detected';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'subscription_id',
        'reminder_type',
        'threshold_days',
        'scheduled_for',
        'detected_at',
        'sent_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'detected_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
