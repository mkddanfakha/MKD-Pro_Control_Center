<?php

namespace Tests\Concerns;

use App\DTO\Provisioning\InstallationReadinessAssessmentResult;
use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\Services\Provisioning\Readiness\InstallationReadinessEvaluationService;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;

trait MocksProvisioningExecutionReadiness
{
    protected function mockProvisioningExecutionReadinessReady(): void
    {
        $assessment = new InstallationReadinessAssessmentResult(
            outcome: InstallationReadinessDecisionOutcome::READY,
            missingRequiredProofCodes: [],
            failedRequiredProofs: [],
            manualInterventionRequiredProofs: [],
            recommendedWarnings: [],
            optionalProofs: [],
            outsideProvisioningProofs: [],
            safeSummary: 'readiness_outcome=ready',
            calculatedAt: new DateTimeImmutable,
        );

        $this->mockReadinessEvaluationService($assessment);
    }

    protected function mockProvisioningExecutionReadinessNotReady(string $reason = 'Installation non prête pour le provisioning.'): void
    {
        $assessment = new InstallationReadinessAssessmentResult(
            outcome: InstallationReadinessDecisionOutcome::NOT_READY,
            missingRequiredProofCodes: ['infrastructure_cloudflare_dns_verified'],
            failedRequiredProofs: [],
            manualInterventionRequiredProofs: [],
            recommendedWarnings: [],
            optionalProofs: [],
            outsideProvisioningProofs: [],
            safeSummary: 'readiness_outcome=not_ready',
            calculatedAt: new DateTimeImmutable,
        );

        /** @var InstallationReadinessEvaluationService&MockInterface $mock */
        $mock = Mockery::mock(InstallationReadinessEvaluationService::class);
        $mock->shouldReceive('evaluateProvisioningRun')->andReturn($assessment);
        $mock->shouldReceive('evaluateProvisioningContext')->andReturn($assessment);
        $mock->shouldReceive('evaluateInstallation')->andReturn($assessment);
        $mock->shouldReceive('allowsPipelineExecution')->andReturn(false);
        $mock->shouldReceive('executionBlockReason')->andReturn($reason);

        $this->app->instance(InstallationReadinessEvaluationService::class, $mock);
    }

    private function mockReadinessEvaluationService(InstallationReadinessAssessmentResult $assessment): void
    {
        /** @var InstallationReadinessEvaluationService&MockInterface $mock */
        $mock = Mockery::mock(InstallationReadinessEvaluationService::class);
        $mock->shouldReceive('evaluateProvisioningRun')->andReturn($assessment);
        $mock->shouldReceive('evaluateProvisioningContext')->andReturn($assessment);
        $mock->shouldReceive('evaluateInstallation')->andReturn($assessment);
        $mock->shouldReceive('allowsPipelineExecution')->andReturn(true);
        $mock->shouldReceive('executionBlockReason')->andReturn(null);

        $this->app->instance(InstallationReadinessEvaluationService::class, $mock);
    }
}
