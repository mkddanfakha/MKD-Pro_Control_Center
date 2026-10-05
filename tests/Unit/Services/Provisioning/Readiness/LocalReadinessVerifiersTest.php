<?php

namespace Tests\Unit\Services\Provisioning\Readiness;

use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\DTO\Provisioning\Readiness\InstallationReadinessRunStepSnapshot;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\Readiness\InstallationReadinessLocalProofCollector;
use App\Services\Provisioning\Readiness\InstallationReadinessVerificationContextFactory;
use App\Services\Provisioning\Readiness\Verifiers\LocalInstallationReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\LocalProvisioningConfigurationReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\LocalProvisioningRunReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\LocalProvisioningStepsReadinessVerifier;
use App\Services\Provisioning\Readiness\Verifiers\PendingExternalReadinessProofVerifier;
use App\Support\Provisioning\InstallationReadinessProofCatalog;
use App\Support\Provisioning\ProvisioningSecretSanitizer;
use DateTimeImmutable;
use Tests\TestCase;

class LocalReadinessVerifiersTest extends TestCase
{
    private InstallationReadinessVerificationContextFactory $contextFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextFactory = new InstallationReadinessVerificationContextFactory;
    }

    /**
     * @return list<InstallationReadinessRunStepSnapshot>
     */
    private function canonicalSucceededSteps(): array
    {
        $steps = [];
        foreach (ProvisioningRunStep::CANONICAL_STEP_KEYS as $index => $key) {
            $steps[] = new InstallationReadinessRunStepSnapshot($key, $index + 1, ProvisioningRunStep::STATUS_SUCCEEDED);
        }

        return $steps;
    }

    private function baseContext(array $overrides = []): \App\DTO\Provisioning\Readiness\InstallationReadinessVerificationContext
    {
        return $this->contextFactory->fromScalars(
            installationId: $overrides['installationId'] ?? 42,
            installationStatus: $overrides['installationStatus'] ?? 'active',
            installationTerminated: $overrides['installationTerminated'] ?? false,
            clientRecordPresent: $overrides['clientRecordPresent'] ?? true,
            subdomain: array_key_exists('subdomain', $overrides) ? $overrides['subdomain'] : 'client-demo',
            domain: array_key_exists('domain', $overrides) ? $overrides['domain'] : 'client.example.test',
            installationVersion: $overrides['installationVersion'] ?? '1.0.0',
            databaseName: array_key_exists('databaseName', $overrides) ? $overrides['databaseName'] : 'mkd_client_demo',
            databaseHost: array_key_exists('databaseHost', $overrides) ? $overrides['databaseHost'] : 'mysql.internal.test',
            provisioningRunId: array_key_exists('provisioningRunId', $overrides) ? $overrides['provisioningRunId'] : 7,
            provisioningRunStatus: array_key_exists('provisioningRunStatus', $overrides) ? $overrides['provisioningRunStatus'] : ProvisioningRun::STATUS_SUCCEEDED,
            targetVersion: $overrides['targetVersion'] ?? '1.0.0',
            targetCommit: $overrides['targetCommit'] ?? 'abc123',
            steps: $overrides['steps'] ?? $this->canonicalSucceededSteps(),
            provisioningConfigFlags: $overrides['provisioningConfigFlags'] ?? [
                'app_env' => 'testing',
                'provisioning_config_exists' => true,
                'gestion_health_check_count' => 5,
                'o2switch_cpanel_host_configured' => true,
                'cloudflare_zone_name_configured' => true,
                'cloudflare_dns_enabled' => false,
                'o2switch_hosting_enabled' => false,
            ],
            verifiedAt: new DateTimeImmutable('2026-03-01T08:00:00+00:00'),
        );
    }

    /**
     * @return array<string, \App\DTO\Provisioning\InstallationReadinessProof>
     */
    private function proofsByCode(array $proofs): array
    {
        $map = [];
        foreach ($proofs as $proof) {
            $map[$proof->code] = $proof;
        }

        return $map;
    }

    public function test_installation_exists_is_verified(): void
    {
        $proofs = $this->proofsByCode((new LocalInstallationReadinessVerifier)->verify($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_INSTALLATION_RECORD_PRESENT]->status);
    }

    public function test_installation_without_subdomain(): void
    {
        $proofs = $this->proofsByCode((new LocalInstallationReadinessVerifier)->verify($this->baseContext(['subdomain' => ''])));
        $this->assertSame(InstallationReadinessProofStatus::NOT_CONFIGURED, $proofs[InstallationReadinessProofCatalog::CODE_SUBDOMAIN_CONFIGURATION_PRESENT]->status);
    }

    public function test_installation_without_domain(): void
    {
        $proofs = $this->proofsByCode((new LocalInstallationReadinessVerifier)->verify($this->baseContext(['domain' => null])));
        $this->assertSame(InstallationReadinessProofStatus::NOT_CONFIGURED, $proofs[InstallationReadinessProofCatalog::CODE_DOMAIN_CONFIGURATION_PRESENT]->status);
    }

    public function test_terminated_installation(): void
    {
        $proofs = $this->proofsByCode((new LocalInstallationReadinessVerifier)->verify($this->baseContext([
            'installationTerminated' => true,
            'installationStatus' => 'terminated',
        ])));
        $this->assertSame(InstallationReadinessProofStatus::FAILED, $proofs[InstallationReadinessProofCatalog::CODE_INSTALLATION_NOT_TERMINATED]->status);
    }

    public function test_run_absent(): void
    {
        $proofs = $this->proofsByCode((new LocalProvisioningRunReadinessVerifier)->verify($this->baseContext([
            'provisioningRunId' => null,
            'provisioningRunStatus' => null,
        ])));
        $this->assertSame(InstallationReadinessProofStatus::NOT_VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_RUN_PRESENT]->status);
    }

    public function test_run_present(): void
    {
        $proofs = $this->proofsByCode((new LocalProvisioningRunReadinessVerifier)->verify($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_RUN_PRESENT]->status);
    }

    public function test_run_succeeded(): void
    {
        $proofs = $this->proofsByCode((new LocalProvisioningRunReadinessVerifier)->verify($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_RUN_SUCCEEDED]->status);
    }

    public function test_run_failed(): void
    {
        $proofs = $this->proofsByCode((new LocalProvisioningRunReadinessVerifier)->verify($this->baseContext([
            'provisioningRunStatus' => ProvisioningRun::STATUS_FAILED,
        ])));
        $this->assertSame(InstallationReadinessProofStatus::FAILED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_RUN_NO_TERMINAL_FAILURE]->status);
    }

    public function test_steps_complete(): void
    {
        $proofs = $this->proofsByCode((new LocalProvisioningStepsReadinessVerifier)->verify($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_CANONICAL_STEPS_PRESENT]->status);
        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_STEPS_ALL_SUCCEEDED]->status);
    }

    public function test_step_missing(): void
    {
        $steps = $this->canonicalSucceededSteps();
        array_pop($steps);
        $proofs = $this->proofsByCode((new LocalProvisioningStepsReadinessVerifier)->verify($this->baseContext(['steps' => $steps])));
        $this->assertSame(InstallationReadinessProofStatus::NOT_VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_CANONICAL_STEPS_PRESENT]->status);
    }

    public function test_duplicate_step(): void
    {
        $steps = $this->canonicalSucceededSteps();
        $steps[] = $steps[0];
        $proofs = $this->proofsByCode((new LocalProvisioningStepsReadinessVerifier)->verify($this->baseContext(['steps' => $steps])));
        $this->assertSame(InstallationReadinessProofStatus::FAILED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_CANONICAL_STEPS_UNIQUE]->status);
    }

    public function test_incorrect_step_order(): void
    {
        $steps = $this->canonicalSucceededSteps();
        $steps[0] = new InstallationReadinessRunStepSnapshot(ProvisioningRunStep::STEP_DNS, 1, ProvisioningRunStep::STATUS_SUCCEEDED);
        $proofs = $this->proofsByCode((new LocalProvisioningStepsReadinessVerifier)->verify($this->baseContext(['steps' => $steps])));
        $this->assertSame(InstallationReadinessProofStatus::NOT_VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_CANONICAL_STEP_ORDER]->status);
    }

    public function test_failed_step(): void
    {
        $steps = $this->canonicalSucceededSteps();
        $steps[3] = new InstallationReadinessRunStepSnapshot($steps[3]->stepKey, $steps[3]->stepOrder, ProvisioningRunStep::STATUS_FAILED);
        $proofs = $this->proofsByCode((new LocalProvisioningStepsReadinessVerifier)->verify($this->baseContext(['steps' => $steps])));
        $this->assertSame(InstallationReadinessProofStatus::FAILED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_STEPS_NO_FAILED]->status);
    }

    public function test_configuration_present(): void
    {
        $proofs = $this->proofsByCode((new LocalProvisioningConfigurationReadinessVerifier)->verify($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_CONFIGURATION_LOADED]->status);
    }

    public function test_configuration_missing(): void
    {
        $proofs = $this->proofsByCode((new LocalProvisioningConfigurationReadinessVerifier)->verify($this->baseContext([
            'provisioningConfigFlags' => [
                'app_env' => 'testing',
                'provisioning_config_exists' => false,
                'o2switch_cpanel_host_configured' => false,
                'cloudflare_zone_name_configured' => false,
            ],
        ])));
        $this->assertSame(InstallationReadinessProofStatus::NOT_CONFIGURED, $proofs[InstallationReadinessProofCatalog::CODE_PROVISIONING_CONFIGURATION_LOADED]->status);
    }

    public function test_external_configuration_present_but_not_verified_as_infrastructure(): void
    {
        $proofs = $this->proofsByCode((new PendingExternalReadinessProofVerifier)->verify($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::NOT_YET_AUTOMATED, $proofs[InstallationReadinessProofCatalog::CODE_DNS_VERIFIED]->status);
        $this->assertSame(InstallationReadinessProofStatus::NOT_YET_AUTOMATED, $proofs[InstallationReadinessProofCatalog::CODE_HTTPS_VERIFIED]->status);
    }

    public function test_dns_not_simulated(): void
    {
        $proofs = $this->proofsByCode((new PendingExternalReadinessProofVerifier)->verify($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::NOT_YET_AUTOMATED, $proofs[InstallationReadinessProofCatalog::CODE_DNS_VERIFIED]->status);
        $this->assertNotSame(InstallationReadinessProofStatus::VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_DNS_VERIFIED]->status);
    }

    public function test_backup_not_simulated(): void
    {
        $collector = new InstallationReadinessLocalProofCollector;
        $proofs = $this->proofsByCode($collector->collect($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::NOT_YET_AUTOMATED, $proofs[\App\Support\Provisioning\InstallationReadinessContract::CODE_BACKUP_VERIFIED]->status);
        $this->assertSame(InstallationReadinessProofStatus::NOT_YET_AUTOMATED, $proofs[InstallationReadinessProofCatalog::CODE_BACKUP_R2_VERIFIED]->status);
    }

    public function test_worker_and_scheduler_not_simulated(): void
    {
        $proofs = $this->proofsByCode((new PendingExternalReadinessProofVerifier)->verify($this->baseContext()));
        $this->assertSame(InstallationReadinessProofStatus::NOT_YET_AUTOMATED, $proofs[\App\Support\Provisioning\InstallationReadinessContract::CODE_WORKER_VERIFIED]->status);
        $this->assertSame(InstallationReadinessProofStatus::NOT_YET_AUTOMATED, $proofs[\App\Support\Provisioning\InstallationReadinessContract::CODE_SCHEDULER_VERIFIED]->status);
    }

    public function test_no_proof_contains_secrets(): void
    {
        $proofs = (new InstallationReadinessLocalProofCollector)->collect($this->baseContext());
        foreach ($proofs as $proof) {
            $this->assertFalse(ProvisioningSecretSanitizer::stringContainsSensitiveExposure($proof->safeSummary));
            $encoded = json_encode($proof->toArray(), JSON_THROW_ON_ERROR);
            $this->assertFalse(ProvisioningSecretSanitizer::stringContainsSensitiveExposure($encoded));
        }
    }

    public function test_verifiers_do_not_write_to_database(): void
    {
        $classes = [
            LocalInstallationReadinessVerifier::class,
            LocalProvisioningRunReadinessVerifier::class,
            LocalProvisioningStepsReadinessVerifier::class,
            LocalProvisioningConfigurationReadinessVerifier::class,
            PendingExternalReadinessProofVerifier::class,
        ];

        foreach ($classes as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertStringNotContainsString('::create(', $source, $class);
            $this->assertStringNotContainsString('->save(', $source, $class);
            $this->assertStringNotContainsString('->update(', $source, $class);
        }
    }
}
