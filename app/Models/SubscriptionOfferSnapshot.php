<?php

namespace App\Models;

use App\Exceptions\Commercial\ImmutableCommercialRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionOfferSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'offer_version_id',
        'offer_code',
        'offer_version_code',
        'product_code',
        'offer_name',
        'catalogue_price',
        'effective_price_at_subscription',
        'currency',
        'billing_cycle',
        'inclusions',
        'limitations',
        'exclusions',
        'commercial_conditions',
        'signed_at',
        'document_hash',
        'contract_reference',
        'negotiated_rate_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'catalogue_price' => 'integer',
            'effective_price_at_subscription' => 'integer',
            'inclusions' => 'array',
            'limitations' => 'array',
            'exclusions' => 'array',
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new ImmutableCommercialRecordException('Subscription offer snapshots are append-only and cannot be modified.');
        });

        static::deleting(function (): void {
            throw new ImmutableCommercialRecordException('Subscription offer snapshots cannot be deleted.');
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class);
    }

    public static function createFromOfferVersion(
        Subscription $subscription,
        OfferVersion $offerVersion,
        ?string $negotiatedRateReason = null,
    ): self {
        $offerVersion->loadMissing('offer.product');

        $offer = $offerVersion->offer;
        $product = $offer->product;

        return self::query()->create([
            'subscription_id' => $subscription->id,
            'offer_version_id' => $offerVersion->id,
            'offer_code' => $offer->code,
            'offer_version_code' => $offerVersion->code,
            'product_code' => $product->code,
            'offer_name' => $offer->name,
            'catalogue_price' => (int) $offerVersion->price,
            'effective_price_at_subscription' => (int) $subscription->amount,
            'currency' => $subscription->currency,
            'billing_cycle' => $offerVersion->billing_cycle,
            'inclusions' => $offerVersion->inclusions,
            'limitations' => $offerVersion->limitations,
            'exclusions' => $offerVersion->exclusions,
            'commercial_conditions' => $offerVersion->commercial_conditions,
            'negotiated_rate_reason' => $negotiatedRateReason,
        ]);
    }
}
