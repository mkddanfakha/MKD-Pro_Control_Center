<?php

namespace App\DTO\Provisioning;

/**
 * Plan de validation live (dry-run ou pré-exécution) — TASK 383.
 */
final class InfrastructureLiveValidationPlan
{
    /**
     * @param  list<InfrastructureLiveValidationPlanItem>  $items
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly bool $environmentPermitsLiveNetwork,
        public readonly array $items,
    ) {}

    public function mandatoryConfigurationReady(): bool
    {
        foreach ($this->items as $item) {
            if (! $item->credentialsConfigured || ! $item->hostOrZoneConfigured) {
                return false;
            }
        }

        return true;
    }

    public function allProbesSafeAndConfigured(): bool
    {
        foreach ($this->items as $item) {
            if ($item->serviceKey === 'cloudflare_dns' || $item->serviceKey === 'o2switch_database') {
                continue;
            }

            if (! $item->probeConfigured || ! $item->probeSafe) {
                return false;
            }
        }

        return true;
    }
}
