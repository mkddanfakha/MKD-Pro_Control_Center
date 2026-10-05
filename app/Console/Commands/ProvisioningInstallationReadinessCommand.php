<?php

namespace App\Console\Commands;

use App\Models\Installation;
use App\Services\Provisioning\Readiness\InstallationReadinessEvaluationService;
use App\Support\Provisioning\ProvisioningInstallationReadinessPresentation;
use Illuminate\Console\Command;

/**
 * Diagnostic readiness read-only (TASK 3W).
 */
final class ProvisioningInstallationReadinessCommand extends Command
{
    protected $signature = 'provisioning:readiness {installation : ID de l\'installation}';

    protected $description = 'Évalue la readiness provisioning d\'une installation (lecture seule, sans effet de bord)';

    public function handle(InstallationReadinessEvaluationService $evaluation): int
    {
        $installation = Installation::query()->find($this->argument('installation'));

        if ($installation === null) {
            $this->error('Installation introuvable.');

            return self::FAILURE;
        }

        $assessment = $evaluation->evaluateInstallation(
            $installation,
            preflightReport: null,
            runPreflightWhenMissing: true,
        );

        $presented = ProvisioningInstallationReadinessPresentation::present($assessment);

        $this->line('Provisioning readiness — installation #'.$installation->id);
        $this->line('Décision : '.$presented['label'].' ('.$presented['state'].')');
        $this->line('Résumé : '.$presented['summary']);
        $this->newLine();

        if ($presented['blockers'] !== []) {
            $this->warn('Blocages :');
            foreach ($presented['blockers'] as $blocker) {
                $this->line(sprintf(
                    ' - [%s] %s : %s',
                    $blocker['status'] ?? 'unknown',
                    $blocker['code'] ?? 'unknown',
                    $blocker['message'] ?? '',
                ));
            }
        }

        if ($presented['warnings'] !== []) {
            $this->line('Avertissements :');
            foreach ($presented['warnings'] as $warning) {
                $this->line(sprintf(
                    ' - %s : %s',
                    $warning['code'] ?? 'warning',
                    $warning['message'] ?? '',
                ));
            }
        }

        return $assessment->isReady() ? self::SUCCESS : self::FAILURE;
    }
}
