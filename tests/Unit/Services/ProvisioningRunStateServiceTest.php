<?php

namespace Tests\Unit\Services;

use App\Exceptions\InvalidProvisioningRunTransition;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\ProvisioningRunStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisioningRunStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProvisioningRunStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProvisioningRunStateService::class);
    }

    public function test_pending_to_running_is_allowed_and_sets_started_at(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $run = $this->makePendingRun();

        $this->service->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $run->refresh();
        $this->assertSame(ProvisioningRun::STATUS_RUNNING, $run->status);
        $this->assertSame('2026-10-01 10:00:00', $run->started_at?->format('Y-m-d H:i:s'));
    }

    public function test_pending_to_cancelled_is_allowed(): void
    {
        $run = $this->makePendingRun();
        $this->service->transitionTo($run, ProvisioningRun::STATUS_CANCELLED);
        $this->assertSame(ProvisioningRun::STATUS_CANCELLED, $run->fresh()->status);
    }

    public function test_running_to_terminal_states_are_allowed(): void
    {
        foreach ([
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_FAILED,
            ProvisioningRun::STATUS_CANCELLED,
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ] as $terminal) {
            $run = $this->makeRunningRun();
            $this->service->transitionTo($run, $terminal);
            $this->assertSame($terminal, $run->fresh()->status);
        }
    }

    public function test_forbidden_transitions_from_terminal_or_failed_states(): void
    {
        $cases = [
            [ProvisioningRun::STATUS_FAILED, ProvisioningRun::STATUS_RUNNING],
            [ProvisioningRun::STATUS_FAILED, ProvisioningRun::STATUS_SUCCEEDED],
            [ProvisioningRun::STATUS_SUCCEEDED, ProvisioningRun::STATUS_RUNNING],
            [ProvisioningRun::STATUS_CANCELLED, ProvisioningRun::STATUS_RUNNING],
            [ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED, ProvisioningRun::STATUS_RUNNING],
        ];

        foreach ($cases as [$from, $to]) {
            $run = $this->makeRunWithStatus($from);

            try {
                $this->service->transitionTo($run, $to);
                $this->fail("Transition {$from} → {$to} aurait dû être refusée.");
            } catch (InvalidProvisioningRunTransition) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_started_at_is_not_reset_on_subsequent_running_transition_attempt(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $run = $this->makePendingRun();
        $this->service->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        $this->travelTo('2026-10-01 11:00:00');
        $run->refresh();
        $originalStarted = $run->started_at;

        $this->service->transitionTo($run, ProvisioningRun::STATUS_SUCCEEDED);
        $run->refresh();

        $this->assertSame($originalStarted?->format('Y-m-d H:i:s'), $run->started_at?->format('Y-m-d H:i:s'));
    }

    public function test_finished_at_is_set_on_terminalization_and_not_modified(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $run = $this->makeRunningRun();
        $this->service->transitionTo($run, ProvisioningRun::STATUS_SUCCEEDED);

        $run->refresh();
        $this->assertSame('2026-10-01 12:00:00', $run->finished_at?->format('Y-m-d H:i:s'));

        $this->expectException(InvalidProvisioningRunTransition::class);
        $this->service->transitionTo($run, ProvisioningRun::STATUS_FAILED);
    }

    public function test_current_step_allowed_only_on_running(): void
    {
        $run = $this->makeRunningRun();
        $this->service->setCurrentStep($run, 'validate');
        $this->assertSame('validate', $run->fresh()->current_step);
    }

    public function test_current_step_rejected_on_terminal_run(): void
    {
        foreach ([
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_FAILED,
            ProvisioningRun::STATUS_CANCELLED,
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ] as $status) {
            $run = $this->makeRunWithStatus($status);

            try {
                $this->service->setCurrentStep($run, 'deploy');
                $this->fail("current_step aurait dû être refusé pour le statut {$status}.");
            } catch (InvalidProvisioningRunTransition) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function makePendingRun(): ProvisioningRun
    {
        return $this->makeRunWithStatus(ProvisioningRun::STATUS_PENDING);
    }

    private function makeRunningRun(): ProvisioningRun
    {
        $run = $this->makePendingRun();
        $this->service->transitionTo($run, ProvisioningRun::STATUS_RUNNING);

        return $run->fresh();
    }

    private function makeRunWithStatus(string $status): ProvisioningRun
    {
        $client = Client::query()->create([
            'company_name' => 'State Test Co',
            'contact_name' => 'Test',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'State Test Installation',
            'subdomain' => 'state-'.uniqid(),
            'status' => 'active',
        ]);

        $attributes = [
            'installation_id' => $installation->id,
            'status' => $status,
            'trigger' => 'manual',
        ];

        if ($status !== ProvisioningRun::STATUS_PENDING) {
            $attributes['started_at'] = now();
        }

        if (in_array($status, [
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_FAILED,
            ProvisioningRun::STATUS_CANCELLED,
            ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED,
        ], true)) {
            $attributes['finished_at'] = now();
        }

        return ProvisioningRun::query()->create($attributes);
    }
}
