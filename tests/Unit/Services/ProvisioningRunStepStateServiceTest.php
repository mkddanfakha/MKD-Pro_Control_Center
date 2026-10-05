<?php

namespace Tests\Unit\Services;

use App\Exceptions\InvalidProvisioningRunStepTransition;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\ProvisioningRunStateService;
use App\Services\ProvisioningRunStepStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisioningRunStepStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProvisioningRunStepStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProvisioningRunStepStateService::class);
    }

    public function test_pending_to_running_and_skipped(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $step = $this->makePendingStep();

        $this->service->transitionTo($step, ProvisioningRunStep::STATUS_RUNNING);
        $step->refresh();
        $this->assertSame('2026-10-01 09:00:00', $step->started_at?->format('Y-m-d H:i:s'));

        $skipped = $this->makePendingStep();
        $this->service->transitionTo($skipped, ProvisioningRunStep::STATUS_SKIPPED);
        $this->assertNotNull($skipped->fresh()->finished_at);
    }

    public function test_running_to_terminal_outcomes(): void
    {
        foreach ([
            ProvisioningRunStep::STATUS_SUCCEEDED,
            ProvisioningRunStep::STATUS_FAILED,
            ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ] as $to) {
            $step = $this->makeRunningStep();
            $this->service->transitionTo($step, $to);
            $this->assertSame($to, $step->fresh()->status);
            $this->assertNotNull($step->fresh()->finished_at);
        }
    }

    public function test_forbidden_transitions_from_terminal_states(): void
    {
        $cases = [
            [ProvisioningRunStep::STATUS_FAILED, ProvisioningRunStep::STATUS_RUNNING],
            [ProvisioningRunStep::STATUS_SKIPPED, ProvisioningRunStep::STATUS_RUNNING],
            [ProvisioningRunStep::STATUS_SUCCEEDED, ProvisioningRunStep::STATUS_RUNNING],
        ];

        foreach ($cases as [$from, $to]) {
            $step = $this->makeStepWithStatus($from);

            try {
                $this->service->transitionTo($step, $to);
                $this->fail("Transition step {$from} → {$to} aurait dû être refusée.");
            } catch (InvalidProvisioningRunStepTransition) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_finished_at_terminalization_blocks_further_transitions(): void
    {
        $step = $this->makeRunningStep();
        $this->service->transitionTo($step, ProvisioningRunStep::STATUS_SUCCEEDED);
        $finished = $step->fresh()->finished_at;

        $this->expectException(InvalidProvisioningRunStepTransition::class);
        $this->service->transitionTo($step->fresh(), ProvisioningRunStep::STATUS_FAILED);

        $this->assertSame($finished?->format('Y-m-d H:i:s'), $step->fresh()->finished_at?->format('Y-m-d H:i:s'));
    }

    public function test_attempt_is_not_modified_by_state_service(): void
    {
        $step = $this->makePendingStep();
        $step->update(['attempt' => 3]);

        $this->service->transitionTo($step, ProvisioningRunStep::STATUS_RUNNING);
        $this->service->transitionTo($step->fresh(), ProvisioningRunStep::STATUS_SUCCEEDED);

        $this->assertSame(3, $step->fresh()->attempt);
    }

    private function makePendingStep(): ProvisioningRunStep
    {
        return $this->makeStepWithStatus(ProvisioningRunStep::STATUS_PENDING);
    }

    private function makeRunningStep(): ProvisioningRunStep
    {
        $step = $this->makePendingStep();
        $this->service->transitionTo($step, ProvisioningRunStep::STATUS_RUNNING);

        return $step->fresh();
    }

    private function makeStepWithStatus(string $status): ProvisioningRunStep
    {
        $run = $this->makeActiveRunForSteps();

        $attributes = [
            'provisioning_run_id' => $run->id,
            'step_key' => 'validate',
            'step_order' => 1,
            'status' => $status,
            'attempt' => 1,
        ];

        if ($status !== ProvisioningRunStep::STATUS_PENDING) {
            $attributes['started_at'] = now();
        }

        if (in_array($status, [
            ProvisioningRunStep::STATUS_SUCCEEDED,
            ProvisioningRunStep::STATUS_FAILED,
            ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
            ProvisioningRunStep::STATUS_SKIPPED,
        ], true)) {
            $attributes['finished_at'] = now();
        }

        return ProvisioningRunStep::query()->create($attributes);
    }

    private function makeActiveRunForSteps(): ProvisioningRun
    {
        $client = Client::query()->create([
            'company_name' => 'Step State Co',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Step State Installation',
            'subdomain' => 'step-'.uniqid(),
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        return app(ProvisioningRunStateService::class)
            ->transitionTo($run, ProvisioningRun::STATUS_RUNNING);
    }
}
