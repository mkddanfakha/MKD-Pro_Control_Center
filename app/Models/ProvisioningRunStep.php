<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningRunStep extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MANUAL_INTERVENTION_REQUIRED = 'manual_intervention_required';

    public const STATUS_SKIPPED = 'skipped';

    public const STEP_VALIDATE = 'validate';

    public const STEP_RESERVE = 'reserve';

    public const STEP_DNS = 'dns';

    public const STEP_HOSTING = 'hosting';

    public const STEP_DATABASE = 'database';

    public const STEP_DEPLOY = 'deploy';

    public const STEP_ENVIRONMENT = 'environment';

    public const STEP_DEPENDENCIES = 'dependencies';

    public const STEP_BUILD = 'build';

    public const STEP_MIGRATE = 'migrate';

    public const STEP_STORAGE = 'storage';

    public const STEP_CACHE = 'cache';

    public const STEP_ADMIN = 'admin';

    public const STEP_MODULES = 'modules';

    public const STEP_HEALTH = 'health';

    public const STEP_MARK_DEPLOYED = 'mark_deployed';

    public const STEP_MARK_VERIFIED = 'mark_verified';

    public const STEP_MARK_READY = 'mark_ready';

    /**
     * @var list<string>
     */
    public const CANONICAL_STEP_KEYS = [
        self::STEP_VALIDATE,
        self::STEP_RESERVE,
        self::STEP_DNS,
        self::STEP_HOSTING,
        self::STEP_DATABASE,
        self::STEP_DEPLOY,
        self::STEP_ENVIRONMENT,
        self::STEP_DEPENDENCIES,
        self::STEP_BUILD,
        self::STEP_MIGRATE,
        self::STEP_STORAGE,
        self::STEP_CACHE,
        self::STEP_ADMIN,
        self::STEP_MODULES,
        self::STEP_HEALTH,
        self::STEP_MARK_DEPLOYED,
        self::STEP_MARK_VERIFIED,
        self::STEP_MARK_READY,
    ];

    protected $fillable = [
        'provisioning_run_id',
        'step_key',
        'step_order',
        'status',
        'attempt',
        'started_at',
        'finished_at',
        'error_code',
        'error_message',
        'input_summary',
        'output_summary',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_summary' => 'array',
            'output_summary' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function provisioningRun(): BelongsTo
    {
        return $this->belongsTo(ProvisioningRun::class);
    }
}
