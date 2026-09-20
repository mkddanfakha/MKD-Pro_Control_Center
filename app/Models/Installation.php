<?php

namespace App\Models;

use App\Models\InstallationModule;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installation extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'subdomain',
        'domain',
        'status',
        'version',
        'database_name',
        'database_host',
        'installed_at',
        'last_seen_at',
        'suspended_at',
        'terminated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function installationModules(): HasMany
    {
        return $this->hasMany(InstallationModule::class);
    }
}
