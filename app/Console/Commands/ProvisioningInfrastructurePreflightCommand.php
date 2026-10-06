<?php

namespace App\Console\Commands;

use App\Services\Provisioning\ProvisioningInfrastructurePreflight;
use Illuminate\Console\Command;

/**
 * Préflight infrastructure manuel — n'active aucun flag provisioning (TASK 375).
 */
final class ProvisioningInfrastructurePreflightCommand extends Command
{
    protected $signature = 'provisioning:infrastructure-preflight|provisioning:preflight';

    protected $description = 'Vérifie la configuration et la connectivité read-only de l\'infrastructure provisioning';

    public function handle(ProvisioningInfrastructurePreflight $preflight): int
    {
        $report = $preflight->run();

        $this->line('Infrastructure preflight');
        $this->newLine();

        foreach ($report->checks as $check) {
            $enabled = $check->provisioningFeatureEnabled ? 'enabled' : 'disabled';
            $this->line(sprintf(
                '%s → %s (%s, capability %s, provisioning %s)',
                $check->serviceLabel,
                $check->state,
                $check->code,
                $check->capability,
                $enabled,
            ));
        }

        $this->newLine();
        $this->line('Global readiness → '.$report->globalState);
        $this->newLine();
        $this->line('Read-only preflight: no mutating provisioning operation executed.');

        return $report->globalState === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
