<?php

namespace App\Services\Provisioning\Readiness;

use App\DTO\Provisioning\Readiness\InstallationReadinessRunStepSnapshot;
use App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Construit un contexte de vérification en lecture seule (TASK 381).
 */
final class InstallationReadinessVerificationContextFactory
{
    public function fromInstallationAndRun(
        Installation $installation,
        ?ProvisioningRun $provisioningRun = null,
        ?DateTimeInterface $verifiedAt = null,
    ): InstallationReadinessVerificationContext {
        $verifiedAt ??= new DateTimeImmutable;

        $steps = [];
        if ($provisioningRun !== null) {
            $provisioningRun->loadMissing('steps');
            foreach ($provisioningRun->steps as $step) {
                $steps[] = new InstallationReadinessRunStepSnapshot(
                    stepKey: (string) $step->step_key,
                    stepOrder: (int) $step->step_order,
                    status: (string) $step->status,
                );
            }
        }

        $installation->loadMissing('client');

        return new InstallationReadinessVerificationContext(
            installationId: $installation->id,
            installationStatus: $installation->status,
            installationTerminated: $installation->status === 'terminated' || $installation->terminated_at !== null,
            clientRecordPresent: $installation->client !== null,
            subdomain: $installation->subdomain,
            domain: $installation->domain,
            installationVersion: $installation->version,
            databaseName: $installation->database_name,
            databaseHost: $installation->database_host,
            provisioningRunId: $provisioningRun?->id,
            provisioningRunStatus: $provisioningRun?->status,
            targetVersion: $provisioningRun?->target_version,
            targetCommit: $provisioningRun?->target_commit,
            runSteps: $steps,
            provisioningConfigFlags: $this->nonSensitiveProvisioningFlags(),
            verifiedAt: $verifiedAt,
        );
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function nonSensitiveProvisioningFlags(): array
    {
        return [
            'app_env' => config('app.env'),
            'provisioning_config_exists' => config('provisioning') !== null,
            'gestion_health_check_count' => count(config('provisioning.gestion.health_check_definitions', [])),
            'o2switch_cpanel_host_configured' => filled(config('provisioning.o2switch.database.cpanel_host')),
            'cloudflare_zone_name_configured' => filled(config('provisioning.cloudflare.dns.zone_name'))
                || filled(config('provisioning.cloudflare.dns.zone_id')),
            'cloudflare_dns_enabled' => (bool) config('provisioning.cloudflare.dns.enabled'),
            'o2switch_hosting_enabled' => (bool) config('provisioning.o2switch.hosting.enabled'),
        ];
    }

    /**
     * @param  Collection<int, ProvisioningRunStep>|list<InstallationReadinessRunStepSnapshot>  $steps
     */
    public function fromScalars(
        ?int $installationId,
        ?string $installationStatus,
        bool $installationTerminated,
        bool $clientRecordPresent,
        ?string $subdomain,
        ?string $domain,
        ?string $installationVersion,
        ?string $databaseName,
        ?string $databaseHost,
        ?int $provisioningRunId,
        ?string $provisioningRunStatus,
        ?string $targetVersion,
        ?string $targetCommit,
        array $steps,
        ?array $provisioningConfigFlags = null,
        ?DateTimeInterface $verifiedAt = null,
    ): InstallationReadinessVerificationContext {
        $verifiedAt ??= new DateTimeImmutable;
        $snapshots = [];

        foreach ($steps as $step) {
            if ($step instanceof InstallationReadinessRunStepSnapshot) {
                $snapshots[] = $step;

                continue;
            }

            if ($step instanceof ProvisioningRunStep) {
                $snapshots[] = new InstallationReadinessRunStepSnapshot(
                    stepKey: (string) $step->step_key,
                    stepOrder: (int) $step->step_order,
                    status: (string) $step->status,
                );
            }
        }

        return new InstallationReadinessVerificationContext(
            installationId: $installationId,
            installationStatus: $installationStatus,
            installationTerminated: $installationTerminated,
            clientRecordPresent: $clientRecordPresent,
            subdomain: $subdomain,
            domain: $domain,
            installationVersion: $installationVersion,
            databaseName: $databaseName,
            databaseHost: $databaseHost,
            provisioningRunId: $provisioningRunId,
            provisioningRunStatus: $provisioningRunStatus,
            targetVersion: $targetVersion,
            targetCommit: $targetCommit,
            runSteps: $snapshots,
            provisioningConfigFlags: $provisioningConfigFlags ?? $this->nonSensitiveProvisioningFlags(),
            verifiedAt: $verifiedAt,
        );
    }
}
