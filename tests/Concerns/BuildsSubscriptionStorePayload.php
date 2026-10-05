<?php

namespace Tests\Concerns;

use App\Models\OfferVersion;
use Database\Seeders\CommercialCatalogSeeder;

trait BuildsSubscriptionStorePayload
{
    protected function seedActiveCatalogOfferVersion(): OfferVersion
    {
        $this->seed(CommercialCatalogSeeder::class);

        return OfferVersion::query()->where('code', 'MKD-GEST-BASE-2026-01')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function subscriptionStorePayload(int $installationId, array $overrides = []): array
    {
        $offerVersion = $this->seedActiveCatalogOfferVersion();

        return array_merge([
            'installation_id' => $installationId,
            'offer_version_id' => $offerVersion->id,
            'currency' => $offerVersion->currency,
            'amount' => (int) $offerVersion->price,
            'starts_at' => '2026-10-01 00:00:00',
        ], $overrides);
    }
}
