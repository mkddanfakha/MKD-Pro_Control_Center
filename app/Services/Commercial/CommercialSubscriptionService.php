<?php

namespace App\Services\Commercial;

use App\Models\OfferVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CommercialSubscriptionService
{
    /**
     * @return Collection<int, OfferVersion>
     */
    public function selectableActiveOfferVersions(?Carbon $at = null): Collection
    {
        $at = $at ?? Carbon::now();

        return OfferVersion::query()
            ->with(['offer.product'])
            ->where('status', OfferVersion::STATUS_ACTIVE)
            ->where('effective_from', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $at);
            })
            ->orderBy('code')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selectableActiveOfferVersionsForForm(?Carbon $at = null): array
    {
        return $this->selectableActiveOfferVersions($at)
            ->map(function (OfferVersion $version): array {
                $offer = $version->offer;
                $product = $offer?->product;

                return [
                    'id' => $version->id,
                    'code' => $version->code,
                    'version' => $version->version,
                    'description' => $version->description,
                    'catalogue_price' => (int) $version->price,
                    'currency' => $version->currency,
                    'billing_cycle' => $version->billing_cycle,
                    'effective_from' => $version->effective_from?->format('Y-m-d H:i:s'),
                    'effective_until' => $version->effective_until?->format('Y-m-d H:i:s'),
                    'offer' => $offer ? [
                        'id' => $offer->id,
                        'code' => $offer->code,
                        'name' => $offer->name,
                    ] : null,
                    'product' => $product ? [
                        'id' => $product->id,
                        'code' => $product->code,
                        'name' => $product->name,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function resolveOfferVersionForNewSubscription(int $offerVersionId, ?Carbon $at = null): OfferVersion
    {
        $at = $at ?? Carbon::now();

        $offerVersion = OfferVersion::query()
            ->with(['offer.product'])
            ->whereKey($offerVersionId)
            ->first();

        if ($offerVersion === null) {
            throw ValidationException::withMessages([
                'offer_version_id' => 'La version commerciale sélectionnée est introuvable.',
            ]);
        }

        if ($offerVersion->status === OfferVersion::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'offer_version_id' => 'Une version brouillon ne peut pas être utilisée pour un abonnement.',
            ]);
        }

        if ($offerVersion->status === OfferVersion::STATUS_RETIRED) {
            throw ValidationException::withMessages([
                'offer_version_id' => 'Une version retirée ne peut pas être utilisée pour un nouvel abonnement.',
            ]);
        }

        if ($offerVersion->status !== OfferVersion::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'offer_version_id' => 'Seule une version commerciale active peut être sélectionnée.',
            ]);
        }

        if ($offerVersion->effective_from === null || $offerVersion->effective_from->gt($at)) {
            throw ValidationException::withMessages([
                'offer_version_id' => 'Cette version commerciale n\'est pas encore applicable.',
            ]);
        }

        if ($offerVersion->effective_until !== null && $offerVersion->effective_until->lt($at)) {
            throw ValidationException::withMessages([
                'offer_version_id' => 'Cette version commerciale n\'est plus applicable.',
            ]);
        }

        if ($offerVersion->offer === null || $offerVersion->offer->product === null) {
            throw ValidationException::withMessages([
                'offer_version_id' => 'La version commerciale sélectionnée est incomplète.',
            ]);
        }

        return $offerVersion;
    }
}
