<?php

namespace Tests\Unit\Services\Provisioning\Readiness;

use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightReport;
use App\DTO\Provisioning\InfrastructurePreflightState;
use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\Services\Provisioning\Readiness\InfrastructurePreflightReadinessProofBridge;
use App\Services\Provisioning\Readiness\InstallationReadinessAssessor;
use App\Services\Provisioning\Readiness\InstallationReadinessCompositeProofCollector;
use App\Services\Provisioning\Readiness\InstallationReadinessVerificationContextFactory;
use App\Support\Provisioning\InfrastructurePreflightReadinessStateMapper;
use App\Support\Provisioning\InstallationReadinessContract;
use App\Support\Provisioning\InstallationReadinessProofCatalog;
use App\Support\Provisioning\ProvisioningSecretSanitizer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InfrastructurePreflightReadinessProofBridgeTest extends TestCase
{
    private InfrastructurePreflightReadinessProofBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new InfrastructurePreflightReadinessProofBridge;
    }

    private function check(string $serviceKey, string $state, string $code = 'diagnostic_code'): InfrastructurePreflightCheckResult
    {
        return new InfrastructurePreflightCheckResult(
            serviceKey: $serviceKey,
            serviceLabel: $serviceKey,
            capability: 'probe',
            state: $state,
            code: $code,
            operatorMessage: 'Preflight check message without secrets.',
            provisioningFeatureEnabled: false,
            configuredAndReachable: $state === InfrastructurePreflightState::READY,
        );
    }

    /**
     * @return list<InfrastructurePreflightCheckResult>
     */
    private function allMandatoryNotConfigured(): array
    {
        return [
            $this->check('cloudflare_dns', InfrastructurePreflightState::NOT_CONFIGURED, 'cloudflare_dns_token_missing'),
            $this->check('o2switch_account', InfrastructurePreflightState::NOT_CONFIGURED, 'o2switch_account_host_missing'),
            $this->check('o2switch_database', InfrastructurePreflightState::NOT_CONFIGURED, 'o2switch_database_host_missing'),
            $this->check('o2switch_git', InfrastructurePreflightState::NOT_CONFIGURED, 'o2switch_git_host_missing'),
            $this->check('o2switch_fileman', InfrastructurePreflightState::NOT_CONFIGURED, 'o2switch_fileman_host_missing'),
        ];
    }

    private function report(array $checks): InfrastructurePreflightReport
    {
        return InfrastructurePreflightReport::fromChecks($checks, InstallationReadinessProofCatalog::preflightMandatoryServiceKeys());
    }

    /**
     * @return array<string, \App\DTO\Provisioning\InstallationReadinessProof>
     */
    private function byCode(array $proofs): array
    {
        $map = [];
        foreach ($proofs as $proof) {
            $map[$proof->code] = $proof;
        }

        return $map;
    }

    public function test_local_not_configured_scenario_produces_no_verified_proofs(): void
    {
        $proofs = $this->byCode($this->bridge->transform($this->report($this->allMandatoryNotConfigured())));

        foreach (InstallationReadinessProofCatalog::preflightMandatoryServiceKeys() as $serviceKey) {
            $code = InstallationReadinessProofCatalog::proofCodeForPreflightServiceKey($serviceKey);
            $this->assertSame(InstallationReadinessProofStatus::NOT_CONFIGURED, $proofs[$code]->status);
            $this->assertNotSame(InstallationReadinessProofStatus::VERIFIED, $proofs[$code]->status);
        }

        $context = (new InstallationReadinessVerificationContextFactory)->fromScalars(
            installationId: 1,
            installationStatus: 'active',
            installationTerminated: false,
            clientRecordPresent: true,
            subdomain: 'demo',
            domain: 'demo.example.test',
            installationVersion: '1.0.0',
            databaseName: 'mkd_demo',
            databaseHost: 'mysql.test',
            provisioningRunId: null,
            provisioningRunStatus: null,
            targetVersion: null,
            targetCommit: null,
            steps: [],
        );

        $collected = (new InstallationReadinessCompositeProofCollector)->collect($context, $this->report($this->allMandatoryNotConfigured()));
        $assessment = (new InstallationReadinessAssessor)->assess(
            $collected,
            InstallationReadinessProofCatalog::requiredProofCodesForClientReady(),
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED, $assessment->outcome);
    }

    public function test_nominal_fictional_all_ready_produces_verified_infrastructure_proofs(): void
    {
        $checks = [
            $this->check('cloudflare_dns', InfrastructurePreflightState::READY, 'cloudflare_dns_ready'),
            $this->check('o2switch_account', InfrastructurePreflightState::READY, 'o2switch_account_ready'),
            $this->check('o2switch_database', InfrastructurePreflightState::READY, 'o2switch_database_ready'),
            $this->check('o2switch_git', InfrastructurePreflightState::READY, 'o2switch_git_ready'),
            $this->check('o2switch_fileman', InfrastructurePreflightState::READY, 'o2switch_fileman_ready'),
        ];

        $proofs = $this->byCode($this->bridge->transform($this->report($checks)));

        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $proofs[InstallationReadinessProofCatalog::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED]->status);
        $this->assertSame(InfrastructurePreflightReadinessProofBridge::PROOF_SOURCE, $proofs[InstallationReadinessProofCatalog::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED]->source);
    }

    #[DataProvider('preflightStateExpectationProvider')]
    public function test_preflight_state_maps_to_expected_proof_status(string $preflightState, string $expectedProofStatus): void
    {
        $this->assertSame($expectedProofStatus, InfrastructurePreflightReadinessStateMapper::toProofStatus($preflightState));

        $proofs = $this->byCode($this->bridge->transform($this->report([
            $this->check('cloudflare_dns', $preflightState, 'cloudflare_dns_probe'),
        ])));

        $code = InstallationReadinessProofCatalog::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED;
        $this->assertSame($expectedProofStatus, $proofs[$code]->status);
        $this->assertNotSame(InstallationReadinessProofStatus::VERIFIED, $proofs[$code]->status);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function preflightStateExpectationProvider(): array
    {
        return [
            'not_configured' => [InfrastructurePreflightState::NOT_CONFIGURED, InstallationReadinessProofStatus::NOT_CONFIGURED],
            'authentication_failed' => [InfrastructurePreflightState::AUTHENTICATION_FAILED, InstallationReadinessProofStatus::FAILED],
            'forbidden' => [InfrastructurePreflightState::FORBIDDEN, InstallationReadinessProofStatus::MANUAL_INTERVENTION_REQUIRED],
            'unreachable' => [InfrastructurePreflightState::UNREACHABLE, InstallationReadinessProofStatus::FAILED],
            'protocol_pending' => [InfrastructurePreflightState::PROTOCOL_PENDING, InstallationReadinessProofStatus::NOT_YET_AUTOMATED],
            'unsupported' => [InfrastructurePreflightState::UNSUPPORTED, InstallationReadinessProofStatus::NOT_YET_AUTOMATED],
            'failed' => [InfrastructurePreflightState::FAILED, InstallationReadinessProofStatus::FAILED],
        ];
    }

    public function test_ready_is_the_only_state_that_produces_verified(): void
    {
        $proofs = $this->byCode($this->bridge->transform($this->report([
            $this->check('cloudflare_dns', InfrastructurePreflightState::READY, 'cloudflare_dns_ready'),
        ])));
        $proof = $proofs[InstallationReadinessProofCatalog::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED];

        $this->assertSame(InstallationReadinessProofStatus::VERIFIED, $proof->status);
    }

    public function test_proofs_never_contain_secrets(): void
    {
        $proofs = $this->bridge->transform($this->report($this->allMandatoryNotConfigured()));
        foreach ($proofs as $proof) {
            $this->assertFalse(ProvisioningSecretSanitizer::stringContainsSensitiveExposure($proof->safeSummary));
            $this->assertSame(InfrastructurePreflightReadinessProofBridge::PROOF_SOURCE, $proof->source);
        }
    }

    public function test_without_preflight_report_composite_keeps_external_not_yet_automated(): void
    {
        $context = (new InstallationReadinessVerificationContextFactory)->fromScalars(
            installationId: 1,
            installationStatus: 'active',
            installationTerminated: false,
            clientRecordPresent: true,
            subdomain: 'demo',
            domain: 'demo.example.test',
            installationVersion: null,
            databaseName: 'mkd_demo',
            databaseHost: 'mysql.test',
            provisioningRunId: null,
            provisioningRunStatus: null,
            targetVersion: null,
            targetCommit: null,
            steps: [],
        );

        $proofs = $this->byCode((new InstallationReadinessCompositeProofCollector)->collect($context, null));

        $this->assertSame(
            InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
            $proofs[InstallationReadinessContract::CODE_BACKUP_VERIFIED]->status,
        );
        $this->assertArrayNotHasKey(
            InstallationReadinessProofCatalog::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED,
            $proofs,
        );
    }

    public function test_integration_preflight_ready_still_not_ready_due_to_gestion_proofs(): void
    {
        $checks = [
            $this->check('cloudflare_dns', InfrastructurePreflightState::READY, 'cloudflare_dns_ready'),
            $this->check('o2switch_account', InfrastructurePreflightState::READY, 'o2switch_account_ready'),
            $this->check('o2switch_database', InfrastructurePreflightState::READY, 'o2switch_database_ready'),
            $this->check('o2switch_git', InfrastructurePreflightState::READY, 'o2switch_git_ready'),
            $this->check('o2switch_fileman', InfrastructurePreflightState::READY, 'o2switch_fileman_ready'),
        ];

        $context = (new InstallationReadinessVerificationContextFactory)->fromScalars(
            installationId: 10,
            installationStatus: 'active',
            installationTerminated: false,
            clientRecordPresent: true,
            subdomain: 'client',
            domain: 'client.example.test',
            installationVersion: '2.0.0',
            databaseName: 'mkd_client',
            databaseHost: 'mysql.test',
            provisioningRunId: 5,
            provisioningRunStatus: 'succeeded',
            targetVersion: '2.0.0',
            targetCommit: 'cafebabe',
            steps: [],
        );

        $proofs = (new InstallationReadinessCompositeProofCollector)->collect($context, $this->report($checks));
        $assessment = (new InstallationReadinessAssessor)->assess(
            $proofs,
            InstallationReadinessProofCatalog::requiredProofCodesForClientReady(),
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED, $assessment->outcome);
    }

    public function test_authentication_failed_with_backup_pending_reflects_failed_priority(): void
    {
        $checks = $this->allMandatoryNotConfigured();
        $checks[0] = $this->check('cloudflare_dns', InfrastructurePreflightState::AUTHENTICATION_FAILED, 'cloudflare_dns_authentication_failed');

        $context = (new InstallationReadinessVerificationContextFactory)->fromScalars(
            installationId: 1,
            installationStatus: 'active',
            installationTerminated: false,
            clientRecordPresent: true,
            subdomain: 'x',
            domain: 'x.example.test',
            installationVersion: null,
            databaseName: 'mkd_x',
            databaseHost: 'mysql.test',
            provisioningRunId: null,
            provisioningRunStatus: null,
            targetVersion: null,
            targetCommit: null,
            steps: [],
        );

        $proofs = (new InstallationReadinessCompositeProofCollector)->collect($context, $this->report($checks));

        $byCode = $this->byCode($proofs);
        $this->assertSame(
            InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
            $byCode[InstallationReadinessContract::CODE_BACKUP_VERIFIED]->status,
        );

        $assessment = (new InstallationReadinessAssessor)->assess(
            $proofs,
            [
                InstallationReadinessProofCatalog::CODE_INFRASTRUCTURE_CLOUDFLARE_DNS_VERIFIED,
                InstallationReadinessContract::CODE_BACKUP_VERIFIED,
            ],
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::FAILED, $assessment->outcome);
    }
}
