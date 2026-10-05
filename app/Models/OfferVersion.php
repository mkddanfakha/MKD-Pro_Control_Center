<?php

namespace App\Models;

use App\Exceptions\Commercial\ImmutableCommercialRecordException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class OfferVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const BILLING_CYCLE_MONTHLY = 'monthly';

    /**
     * @var list<string>
     */
    private const PUBLISHED_IMMUTABLE_ATTRIBUTES = [
        'offer_id',
        'version',
        'code',
        'price',
        'currency',
        'billing_cycle',
        'effective_from',
        'description',
        'inclusions',
        'limitations',
        'exclusions',
        'commercial_conditions',
    ];

    private bool $performingControlledCommercialTransition = false;

    protected $fillable = [
        'offer_id',
        'version',
        'code',
        'price',
        'currency',
        'billing_cycle',
        'effective_from',
        'effective_until',
        'status',
        'description',
        'inclusions',
        'limitations',
        'exclusions',
        'commercial_conditions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'inclusions' => 'array',
            'limitations' => 'array',
            'exclusions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (OfferVersion $offerVersion): void {
            if ($offerVersion->performingControlledCommercialTransition) {
                $offerVersion->assertControlledTransitionIsValid();

                return;
            }

            $originalStatus = $offerVersion->getOriginal('status');

            if (in_array($originalStatus, [self::STATUS_ACTIVE, self::STATUS_RETIRED], true)) {
                throw new ImmutableCommercialRecordException('Published offer versions cannot be modified.');
            }
        });
    }

    public function applyPublicationTransition(): void
    {
        $this->performingControlledCommercialTransition = true;

        try {
            $this->status = self::STATUS_ACTIVE;
            $this->save();
        } finally {
            $this->performingControlledCommercialTransition = false;
        }
    }

    public function applyRetirementTransition(CarbonInterface $retiredAt): void
    {
        $this->performingControlledCommercialTransition = true;

        try {
            $this->status = self::STATUS_RETIRED;

            if ($this->effective_until === null) {
                $this->effective_until = Carbon::instance($retiredAt);
            }

            $this->save();
        } finally {
            $this->performingControlledCommercialTransition = false;
        }
    }

    /**
     * @throws ImmutableCommercialRecordException
     */
    private function assertControlledTransitionIsValid(): void
    {
        $originalStatus = $this->getOriginal('status');
        $dirty = array_keys($this->getDirty());

        if ($originalStatus === self::STATUS_DRAFT && $this->status === self::STATUS_ACTIVE) {
            $allowed = ['status', 'updated_at'];
            $this->assertOnlyDirtyAttributes($dirty, $allowed, 'publication');

            return;
        }

        if ($originalStatus === self::STATUS_ACTIVE && $this->status === self::STATUS_RETIRED) {
            $allowed = ['status', 'effective_until', 'updated_at'];
            $this->assertOnlyDirtyAttributes($dirty, $allowed, 'retrait');

            foreach (self::PUBLISHED_IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($this->isDirty($attribute)) {
                    throw new ImmutableCommercialRecordException('Le retrait ne peut pas modifier le contenu publié.');
                }
            }

            if ($this->isDirty('effective_until')) {
                $originalUntil = $this->getOriginal('effective_until');

                if ($originalUntil !== null) {
                    throw new ImmutableCommercialRecordException('La date de fin d\'effet existante ne peut pas être modifiée lors du retrait.');
                }
            }

            return;
        }

        throw new ImmutableCommercialRecordException('Transition commerciale non autorisée.');
    }

    /**
     * @param  list<string>  $dirty
     * @param  list<string>  $allowed
     *
     * @throws ImmutableCommercialRecordException
     */
    private function assertOnlyDirtyAttributes(array $dirty, array $allowed, string $operation): void
    {
        $unexpected = array_diff($dirty, $allowed);

        if ($unexpected !== []) {
            throw new ImmutableCommercialRecordException("Transition de {$operation} invalide : champs interdits modifiés.");
        }
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
