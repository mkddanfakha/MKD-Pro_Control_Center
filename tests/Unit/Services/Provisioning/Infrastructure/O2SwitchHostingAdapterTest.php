<?php

namespace Tests\Unit\Services\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\HostingSpaceAdapter;
use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchHostingGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Services\Provisioning\Infrastructure\O2Switch\NullO2SwitchHostingGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\O2SwitchHostingAdapter;
use App\Services\Provisioning\Infrastructure\O2Switch\O2SwitchHostingConfiguration;
use App\Services\Provisioning\Steps\HostingProvisioningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class O2SwitchHostingAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('provisioning.o2switch.hosting', [
            'provider' => 'o2switch',
            'enabled' => false,
            'dry_run' => false,
            'account_logical_id' => '',
            'environment' => 'production',
        ]);

        parent::tearDown();
    }

    public function test_default_configuration_is_disabled_without_network(): void
    {
        Http::fake();

        $adapter = app(HostingSpaceAdapter::class);
        $this->assertInstanceOf(O2SwitchHostingAdapter::class, $adapter);

        $result = $adapter->prepareHosting($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(
            InfrastructureAdapterResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );
        $this->assertSame('o2switch_hosting_disabled', $result->code);
    }

    public function test_feature_flag_disabled_returns_manual_intervention(): void
    {
        Http::fake();

        $adapter = new O2SwitchHostingAdapter(
            new NullO2SwitchHostingGateway,
            new O2SwitchHostingConfiguration(
                provider: 'o2switch',
                enabled: false,
                dryRun: false,
                accountLogicalId: 'acct-demo',
                environment: 'production',
            ),
        );

        $result = $adapter->prepareHosting($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame('o2switch_hosting_disabled', $result->code);
    }

    public function test_enabled_without_account_logical_id_is_not_configured(): void
    {
        $adapter = new O2SwitchHostingAdapter(
            new NullO2SwitchHostingGateway,
            new O2SwitchHostingConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: '',
                environment: 'production',
            ),
        );

        $result = $adapter->prepareHosting($this->makeContext());

        $this->assertSame('o2switch_hosting_not_configured', $result->code);
    }

    public function test_dry_run_succeeds_without_network_and_marks_simulation(): void
    {
        Http::fake();

        $adapter = new O2SwitchHostingAdapter(
            new NullO2SwitchHostingGateway,
            new O2SwitchHostingConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: true,
                accountLogicalId: 'o2switch-account-ref',
                environment: 'staging',
            ),
        );

        $result = $adapter->prepareHosting($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(InfrastructureAdapterResult::OUTCOME_SUCCEEDED, $result->outcome);
        $this->assertSame('dry_run', $result->metadata['execution_mode'] ?? null);
        $this->assertTrue($result->metadata['simulated'] ?? false);
        $this->assertTrue($result->outputSummary['dry_run'] ?? false);
    }

    public function test_enabled_live_mode_delegates_to_gateway_without_network_by_default(): void
    {
        Http::fake();

        $gateway = new class implements O2SwitchHostingGateway
        {
            public int $calls = 0;

            public function prepareHostingSpace(
                ProvisioningContext $context,
                O2SwitchHostingConfiguration $configuration,
            ): InfrastructureAdapterResult {
                $this->calls++;

                return InfrastructureAdapterResult::manualInterventionRequired(
                    'gateway_test',
                    'Gateway simulé.',
                );
            }
        };

        $adapter = new O2SwitchHostingAdapter(
            $gateway,
            new O2SwitchHostingConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: 'o2switch-account-ref',
                environment: 'production',
            ),
        );

        $context = $this->makeContext();
        $adapter->prepareHosting($context);

        $this->assertSame(1, $gateway->calls);
        Http::assertNothingSent();
    }

    public function test_null_gateway_returns_protocol_pending_not_success(): void
    {
        $adapter = new O2SwitchHostingAdapter(
            new NullO2SwitchHostingGateway,
            new O2SwitchHostingConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: 'o2switch-account-ref',
                environment: 'production',
            ),
        );

        $result = $adapter->prepareHosting($this->makeContext());

        $this->assertSame('o2switch_hosting_protocol_pending', $result->code);
        $this->assertSame(
            InfrastructureAdapterResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );
    }

    public function test_results_never_contain_forbidden_secret_keys(): void
    {
        $adapter = app(O2SwitchHostingAdapter::class);
        $result = $adapter->prepareHosting($this->makeContext());

        $encoded = json_encode([$result->outputSummary, $result->metadata]);
        $this->assertStringNotContainsString('api_key', strtolower((string) $encoded));
        $this->assertStringNotContainsString('password', strtolower((string) $encoded));
        $this->assertStringNotContainsString('token', strtolower((string) $encoded));
    }

    public function test_hosting_step_uses_contract_and_respects_disabled_adapter(): void
    {
        Http::fake();

        $step = app(HostingProvisioningStep::class);
        $result = $step->execute($this->makeContext());

        Http::assertNothingSent();
        $this->assertSame(
            \App\DTO\Provisioning\ProvisioningStepResult::OUTCOME_MANUAL_INTERVENTION_REQUIRED,
            $result->outcome,
        );
    }

    public function test_hosting_step_with_injected_fake_gateway_returns_controlled_result(): void
    {
        $fakeGateway = new class implements O2SwitchHostingGateway
        {
            public function prepareHostingSpace(
                ProvisioningContext $context,
                O2SwitchHostingConfiguration $configuration,
            ): InfrastructureAdapterResult {
                return InfrastructureAdapterResult::manualInterventionRequired(
                    'fake_hosting_check',
                    'Contrôle simulé.',
                    metadata: ['installation_id' => $context->installationId],
                );
            }
        };

        $this->app->instance(O2SwitchHostingGateway::class, $fakeGateway);
        $this->app->instance(HostingSpaceAdapter::class, new O2SwitchHostingAdapter(
            $fakeGateway,
            new O2SwitchHostingConfiguration(
                provider: 'o2switch',
                enabled: true,
                dryRun: false,
                accountLogicalId: 'acct-test',
                environment: 'production',
            ),
        ));
        $this->app->forgetInstance(HostingProvisioningStep::class);

        $result = app(HostingProvisioningStep::class)->execute($this->makeContext());

        $this->assertSame('fake_hosting_check', $result->code);
    }

    public function test_context_installation_id_is_reflected_in_metadata(): void
    {
        $context = $this->makeContext();

        $adapter = new O2SwitchHostingAdapter(
            new NullO2SwitchHostingGateway,
            new O2SwitchHostingConfiguration(
                provider: 'o2switch',
                enabled: false,
                dryRun: false,
                accountLogicalId: '',
                environment: 'production',
            ),
        );

        $result = $adapter->prepareHosting($context);

        $this->assertSame($context->installationId, $result->metadata['installation_id'] ?? null);
    }

    private function makeContext(): ProvisioningContext
    {
        $client = Client::query()->create([
            'company_name' => 'O2Switch Adapter Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        $installation = Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Hosting Adapter Installation',
            'subdomain' => 'o2s-'.uniqid(),
            'status' => 'active',
        ]);

        $run = ProvisioningRun::query()->create([
            'installation_id' => $installation->id,
            'status' => ProvisioningRun::STATUS_PENDING,
            'trigger' => 'manual',
        ]);

        $run->setRelation('installation', $installation);
        $installation->setRelation('client', $client);

        return ProvisioningContext::fromRun($run);
    }
}
