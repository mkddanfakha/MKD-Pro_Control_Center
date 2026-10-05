<?php

namespace Tests\Feature\Provisioning;

use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\Readiness\InstallationReadinessAssessor;
use App\Services\Provisioning\Readiness\InstallationReadinessLocalProofCollector;
use App\Services\Provisioning\Readiness\InstallationReadinessVerificationContextFactory;
use App\Support\Provisioning\InstallationReadinessContract;
use App\Support\Provisioning\InstallationReadinessProofCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationReadinessLocalVerificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_verifiers_feed_assessor_and_remain_not_ready_due_to_external_blockers(): void
    {
        $installation = $this->makeInstallationWithSucceededRun();

        $factory = new InstallationReadinessVerificationContextFactory;
        $run = $installation->provisioningRuns()->first();
        $context = $factory->fromInstallationAndRun($installation, $run);

        $proofs = (new InstallationReadinessLocalProofCollector)->collect($context);
        $byCode = [];
        foreach ($proofs as $proof) {
            $byCode[$proof->code] = $proof;
        }

        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $byCode[InstallationReadinessProofCatalog::CODE_DOMAIN_CONFIGURATION_PRESENT]->status);
        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $byCode[InstallationReadinessProofCatalog::CODE_PROVISIONING_RUN_SUCCEEDED]->status);
        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $byCode[InstallationReadinessProofCatalog::CODE_PROVISIONING_STEPS_ALL_SUCCEEDED]->status);
        $this->assertSame(InstallationReadinessProofStatus::NOT_YET_AUTOMATED, $byCode[InstallationReadinessContract::CODE_BACKUP_VERIFIED]->status);

        $assessment = (new InstallationReadinessAssessor)->assess(
            $proofs,
            InstallationReadinessProofCatalog::requiredProofCodesForClientReady(),
        );

        $this->assertFalse($assessment->isReady());
        $this->assertSame(InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED, $assessment->outcome);
        $this->assertStringContainsString('manual_intervention', $assessment->safeSummary);
    }

    public function test_niane_like_fictional_scenario_stays_not_ready(): void
    {
        $installation = $this->makeInstallationWithSucceededRun([
            'subdomain' => 'niane-demo',
            'domain' => 'niane.example.test',
        ]);

        $run = $installation->provisioningRuns()->first();
        $context = (new InstallationReadinessVerificationContextFactory)->fromInstallationAndRun($installation, $run);
        $proofs = (new InstallationReadinessLocalProofCollector)->collect($context);

        $assessment = (new InstallationReadinessAssessor)->assess(
            $proofs,
            InstallationReadinessProofCatalog::requiredProofCodesForClientReady(),
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED, $assessment->outcome);

        $externalBlocked = array_filter($proofs, static fn ($proof) => $proof->status === InstallationReadinessProofStatus::NOT_YET_AUTOMATED
            && $proof->level === 'required');

        $this->assertNotEmpty($externalBlocked);
    }

    public function test_collector_does_not_mutate_installation_or_run_status(): void
    {
        $installation = $this->makeInstallationWithSucceededRun();
        $run = $installation->provisioningRuns()->first();

        $installationStatusBefore = $installation->status;
        $runStatusBefore = $run->status;

        $context = (new InstallationReadinessVerificationContextFactory)->fromInstallationAndRun($installation->fresh(), $run->fresh());
        (new InstallationReadinessLocalProofCollector)->collect($context);

        $this->assertSame($installationStatusBefore, $installation->fresh()->status);
        $this->assertSame($runStatusBefore, $run->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $installationOverrides
     */
    private function makeInstallationWithSucceededRun(array $installationOverrides = []): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Fictif Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Fictif Install',
            'subdomain' => 'demo-'.uniqid(),
            'domain' => 'demo.example.test',
            'database_name' => 'mkd_demo',
            'database_host' => 'mysql.internal.test',
            'status' => 'active',
            'version' => '1.2.3',
        ], $installationOverrides));

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_SUCCEEDED,
            'trigger' => 'manual',
            'target_version' => '1.2.3',
            'target_commit' => 'deadbeef',
            'pipeline_version' => '1',
        ]);

        foreach (ProvisioningRunStep::CANONICAL_STEP_KEYS as $index => $stepKey) {
            ProvisioningRunStep::query()->create([
                'provisioning_run_id' => $run->id,
                'step_key' => $stepKey,
                'step_order' => $index + 1,
                'status' => ProvisioningRunStep::STATUS_SUCCEEDED,
            ]);
        }

        return $installation->fresh(['provisioningRuns']);
    }
}
