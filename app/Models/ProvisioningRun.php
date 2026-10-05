<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProvisioningRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_MANUAL_INTERVENTION_REQUIRED = 'manual_intervention_required';

    protected $fillable = [
        'installation_id',
        'status',
        'trigger',
        'requested_by',
        'target_version',
        'target_commit',
        'pipeline_version',
        'current_step',
        'error_code',
        'error_message',
        'retry_of_run_id',
        'metadata',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_run_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_run_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProvisioningRunStep::class);
    }
}
