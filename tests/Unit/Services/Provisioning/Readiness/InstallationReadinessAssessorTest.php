<?php

namespace Tests\Unit\Services\Provisioning\Readiness;

use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\InstallationReadinessProofLevel;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\Services\Provisioning\Readiness\InstallationReadinessAssessor;
use App\Support\Provisioning\InstallationReadinessContract;
use App\Support\Provisioning\ProvisioningSecretSanitizer;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallationReadinessAssessorTest extends TestCase
{
    private InstallationReadinessAssessor $assessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assessor = new InstallationReadinessAssessor;
    }

    /**
     * @param  list<string>  $requiredCodes
     */
    private function proof(
        string $code,
        string $status,
        string $level = InstallationReadinessProofLevel::REQUIRED,
        string $domain = 'test',
        string $summary = 'proof_ok',
    ): InstallationReadinessProof {
        return new InstallationReadinessProof(
            domain: $domain,
            code: $code,
            level: $level,
            status: $status,
            source: 'unit_test',
            automaticallyVerifiable: true,
            persistable: true,
            safeSummary: $summary,
            verifiedAt: $status === InstallationReadinessProofStatus::VERIFIED
                ? new DateTimeImmutable('2026-01-15T10:00:00+00:00')
                : null,
        );
    }

    public function test_all_required_verified_yields_ready(): void
    {
        $codes = ['alpha', 'beta'];
        $proofs = [
            $this->proof('alpha', InstallationReadinessProofStatus::VERIFIED),
            $this->proof('beta', InstallationReadinessProofStatus::VERIFIED),
        ];

        $result = $this->assessor->assess($proofs, $codes, new DateTimeImmutable('2026-02-01T12:00:00+00:00'));

        $this->assertTrue($result->isReady());
        $this->assertSame(InstallationReadinessDecisionOutcome::READY, $result->outcome);
        $this->assertSame([], $result->missingRequiredProofCodes);
        $this->assertSame('2026-02-01T12:00:00+00:00', $result->calculatedAt->format(DATE_ATOM));
    }

    public function test_missing_required_proof_yields_not_ready(): void
    {
        $result = $this->assessor->assess(
            [$this->proof('only_one', InstallationReadinessProofStatus::VERIFIED)],
            ['only_one', 'missing_code'],
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::NOT_READY, $result->outcome);
        $this->assertSame(['missing_code'], $result->missingRequiredProofCodes);
    }

    public function test_required_not_verified_yields_not_ready(): void
    {
        $result = $this->assessor->assess(
            [$this->proof('req', InstallationReadinessProofStatus::NOT_VERIFIED)],
            ['req'],
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::NOT_READY, $result->outcome);
        $this->assertCount(0, $result->manualInterventionRequiredProofs);
    }

    public function test_required_not_verified_is_in_required_incomplete_via_outcome(): void
    {
        $result = $this->assessor->assess(
            [$this->proof('req', InstallationReadinessProofStatus::NOT_VERIFIED, summary: 'not_verified_yet')],
            ['req'],
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::NOT_READY, $result->outcome);
        $this->assertStringContainsString('required_incomplete=1', $result->safeSummary);
    }

    public function test_required_failed_yields_failed_outcome(): void
    {
        $result = $this->assessor->assess(
            [$this->proof('req', InstallationReadinessProofStatus::FAILED, summary: 'database_connection_failed')],
            ['req'],
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::FAILED, $result->outcome);
        $this->assertCount(1, $result->failedRequiredProofs);
    }

    public function test_required_manual_intervention_yields_manual_intervention_outcome(): void
    {
        $result = $this->assessor->assess(
            [$this->proof('req', InstallationReadinessProofStatus::MANUAL_INTERVENTION_REQUIRED, summary: 'adapter_unavailable')],
            ['req'],
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED, $result->outcome);
        $this->assertCount(1, $result->manualInterventionRequiredProofs);
    }

    public function test_required_not_configured_yields_not_ready(): void
    {
        $result = $this->assessor->assess(
            [$this->proof('req', InstallationReadinessProofStatus::NOT_CONFIGURED)],
            ['req'],
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::NOT_READY, $result->outcome);
    }

    public function test_required_not_yet_automated_yields_not_ready(): void
    {
        $result = $this->assessor->assess(
            [$this->proof('req', InstallationReadinessProofStatus::NOT_YET_AUTOMATED, summary: 'not_yet_automated')],
            ['req'],
        );

        $this->assertSame(InstallationReadinessDecisionOutcome::NOT_READY, $result->outcome);
    }

    public function test_recommended_gap_still_ready_when_required_complete(): void
    {
        $result = $this->assessor->assess([
            $this->proof('req', InstallationReadinessProofStatus::VERIFIED),
            $this->proof('rec', InstallationReadinessProofStatus::NOT_VERIFIED, InstallationReadinessProofLevel::RECOMMENDED, summary: 'monitoring_optional'),
        ], ['req']);

        $this->assertTrue($result->isReady());
        $this->assertCount(1, $result->recommendedWarnings);
    }

    public function test_optional_not_verified_still_ready(): void
    {
        $result = $this->assessor->assess([
            $this->proof('req', InstallationReadinessProofStatus::VERIFIED),
            $this->proof('opt', InstallationReadinessProofStatus::NOT_VERIFIED, InstallationReadinessProofLevel::OPTIONAL),
        ], ['req']);

        $this->assertTrue($result->isReady());
        $this->assertCount(1, $result->optionalProofs);
    }

    public function test_outside_provisioning_not_verified_still_ready(): void
    {
        $result = $this->assessor->assess([
            $this->proof('req', InstallationReadinessProofStatus::VERIFIED),
            $this->proof('ext', InstallationReadinessProofStatus::NOT_YET_AUTOMATED, InstallationReadinessProofLevel::OUTSIDE_PROVISIONING),
        ], ['req']);

        $this->assertTrue($result->isReady());
        $this->assertCount(1, $result->outsideProvisioningProofs);
    }

    public function test_multiple_blockers_all_reported_with_failed_priority(): void
    {
        $result = $this->assessor->assess([
            $this->proof('a', InstallationReadinessProofStatus::FAILED),
            $this->proof('b', InstallationReadinessProofStatus::MANUAL_INTERVENTION_REQUIRED),
            $this->proof('c', InstallationReadinessProofStatus::NOT_VERIFIED),
        ], ['a', 'b', 'c']);

        $this->assertSame(InstallationReadinessDecisionOutcome::FAILED, $result->outcome);
        $this->assertCount(1, $result->failedRequiredProofs);
        $this->assertCount(1, $result->manualInterventionRequiredProofs);
        $this->assertStringContainsString('failed_required=1', $result->safeSummary);
    }

    public function test_proof_summary_rejects_secrets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InstallationReadinessProof(
            domain: 'database',
            code: 'db',
            level: InstallationReadinessProofLevel::REQUIRED,
            status: InstallationReadinessProofStatus::VERIFIED,
            source: 'test',
            automaticallyVerifiable: true,
            persistable: true,
            safeSummary: 'password=leaked',
        );
    }

    public function test_assessment_result_summary_is_safe(): void
    {
        $result = $this->assessor->assess(
            [$this->proof('req', InstallationReadinessProofStatus::VERIFIED, summary: 'database_connection_verified')],
            ['req'],
        );

        $this->assertFalse(ProvisioningSecretSanitizer::stringContainsSensitiveExposure($result->safeSummary));
        $this->assertStringContainsString('readiness_outcome=ready', $result->safeSummary);
    }

    public function test_niane_like_scenario_not_ready_when_ops_proofs_not_yet_automated(): void
    {
        $required = InstallationReadinessContract::representativeRequiredProofCodes();

        $proofs = [
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_APPLICATION,
                code: InstallationReadinessContract::CODE_APPLICATION_ACCESSIBLE,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::VERIFIED,
                source: 'health_probe_fictif',
                automaticallyVerifiable: true,
                persistable: true,
                safeSummary: 'application_accessible',
            ),
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_DATABASE,
                code: InstallationReadinessContract::CODE_DATABASE_CONNECTION,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::VERIFIED,
                source: 'verification_fictif',
                automaticallyVerifiable: true,
                persistable: true,
                safeSummary: 'database_connection_verified',
            ),
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_BUILD,
                code: InstallationReadinessContract::CODE_BUILD_MANIFEST,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::VERIFIED,
                source: 'filesystem_fictif',
                automaticallyVerifiable: true,
                persistable: true,
                safeSummary: 'build_manifest_verified',
            ),
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_HEALTH,
                code: InstallationReadinessContract::CODE_HEALTH_CATALOG,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::VERIFIED,
                source: 'health_step_fictif',
                automaticallyVerifiable: true,
                persistable: true,
                safeSummary: 'health_catalog_verified',
            ),
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_ADMIN,
                code: InstallationReadinessContract::CODE_ADMIN_BOOTSTRAP,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::VERIFIED,
                source: 'admin_fictif',
                automaticallyVerifiable: true,
                persistable: true,
                safeSummary: 'admin_bootstrap_verified',
            ),
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_BACKUP,
                code: InstallationReadinessContract::CODE_BACKUP_VERIFIED,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
                source: 'runbook_only',
                automaticallyVerifiable: false,
                persistable: true,
                safeSummary: 'backup_verified',
            ),
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_WORKER,
                code: InstallationReadinessContract::CODE_WORKER_VERIFIED,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
                source: 'runbook_only',
                automaticallyVerifiable: false,
                persistable: true,
                safeSummary: 'worker_verified',
            ),
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_SCHEDULER,
                code: InstallationReadinessContract::CODE_SCHEDULER_VERIFIED,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
                source: 'runbook_only',
                automaticallyVerifiable: false,
                persistable: true,
                safeSummary: 'scheduler_verified',
            ),
            new InstallationReadinessProof(
                domain: InstallationReadinessContract::DOMAIN_WEBROOT,
                code: InstallationReadinessContract::CODE_WEBROOT_SENSITIVE_ABSENT,
                level: InstallationReadinessProofLevel::REQUIRED,
                status: InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
                source: 'audit_fictif',
                automaticallyVerifiable: false,
                persistable: true,
                safeSummary: 'webroot_sensitive_files_absent',
            ),
        ];

        $result = $this->assessor->assess($proofs, $required);

        $this->assertFalse($result->isReady());
        $this->assertSame(InstallationReadinessDecisionOutcome::NOT_READY, $result->outcome);
        $this->assertStringContainsString('required_incomplete=4', $result->safeSummary);
    }

    public function test_assessor_does_not_touch_installation_or_provisioning_models(): void
    {
        $this->assertFalse(class_exists(\App\Models\Installation::class) && (new InstallationReadinessAssessor) instanceof \App\Models\Installation);
        $result = $this->assessor->assess([], []);
        $this->assertSame(InstallationReadinessDecisionOutcome::READY, $result->outcome);
    }

    #[DataProvider('blockingStatusProvider')]
    public function test_required_blocking_status_maps_to_expected_outcome(string $status, string $expectedOutcome): void
    {
        $result = $this->assessor->assess(
            [$this->proof('x', $status)],
            ['x'],
        );

        $this->assertSame($expectedOutcome, $result->outcome);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockingStatusProvider(): array
    {
        return [
            'verified' => [InstallationReadinessProofStatus::VERIFIED, InstallationReadinessDecisionOutcome::READY],
            'not_verified' => [InstallationReadinessProofStatus::NOT_VERIFIED, InstallationReadinessDecisionOutcome::NOT_READY],
            'not_configured' => [InstallationReadinessProofStatus::NOT_CONFIGURED, InstallationReadinessDecisionOutcome::NOT_READY],
            'failed' => [InstallationReadinessProofStatus::FAILED, InstallationReadinessDecisionOutcome::FAILED],
            'manual' => [InstallationReadinessProofStatus::MANUAL_INTERVENTION_REQUIRED, InstallationReadinessDecisionOutcome::MANUAL_INTERVENTION_REQUIRED],
            'not_yet_automated' => [InstallationReadinessProofStatus::NOT_YET_AUTOMATED, InstallationReadinessDecisionOutcome::NOT_READY],
        ];
    }
}
