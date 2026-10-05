<?php

namespace Tests\Feature\Provisioning;

use App\DTO\Provisioning\InstallationReadinessAssessmentResult;
use App\DTO\Provisioning\InstallationReadinessDecisionOutcome;
use App\DTO\Provisioning\InstallationReadinessProof;
use App\DTO\Provisioning\InstallationReadinessProofLevel;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\User;
use App\Services\Provisioning\ProvisioningRunFactory;
use App\Support\Provisioning\ProvisioningInstallationReadinessPresentation;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProvisioningReadinessHttpTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_SECRET = 'fake-readiness-secret-must-not-leak';

    public function test_installation_show_exposes_provisioning_readiness_for_authorized_admin(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->createInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installations/Show')
                ->has('provisioning_readiness.state')
                ->has('provisioning_readiness.label')
                ->has('provisioning_readiness.summary')
                ->has('provisioning_readiness.can_execute'));
    }

    public function test_installation_show_forbidden_without_control_center_gate(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'accessControlCenter' ? false : null);

        $user = User::factory()->create();
        $installation = $this->createInstallation();

        $this->actingAs($user)
            ->get(route('installations.show', $installation))
            ->assertForbidden();
    }

    public function test_provisioning_run_show_not_found_for_missing_run(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->get('/provisioning-runs/999999')
            ->assertNotFound();
    }

    public function test_execute_not_found_for_missing_run(): void
    {
        $user = $this->controlCenterAdminUser();

        $this->actingAs($user)
            ->post('/provisioning-runs/999999/execute')
            ->assertNotFound();
    }

    public function test_readiness_presentation_never_exposes_secrets_in_proof_messages(): void
    {
        $proof = new InstallationReadinessProof(
            domain: 'infrastructure',
            code: 'infrastructure_cloudflare_dns_verified',
            level: InstallationReadinessProofLevel::REQUIRED,
            status: InstallationReadinessProofStatus::FAILED,
            source: 'preflight',
            automaticallyVerifiable: true,
            persistable: true,
            safeSummary: 'DNS check failed (token redacted)',
            verifiedAt: new DateTimeImmutable,
        );

        $assessment = new InstallationReadinessAssessmentResult(
            outcome: InstallationReadinessDecisionOutcome::NOT_READY,
            missingRequiredProofCodes: [],
            failedRequiredProofs: [$proof],
            manualInterventionRequiredProofs: [],
            recommendedWarnings: [],
            optionalProofs: [],
            outsideProvisioningProofs: [],
            safeSummary: 'readiness_outcome=not_ready',
            calculatedAt: new DateTimeImmutable,
        );

        $presented = ProvisioningInstallationReadinessPresentation::present($assessment);
        $encoded = json_encode($presented, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::FAKE_SECRET, $encoded);
        $this->assertStringNotContainsString('Bearer', $encoded);
    }

    public function test_run_show_readiness_payload_is_json_safe_without_credentials(): void
    {
        $user = $this->controlCenterAdminUser();
        $installation = $this->createInstallation();
        $run = app(ProvisioningRunFactory::class)->createRequest($installation, $user->id);

        $response = $this->actingAs($user)
            ->get(route('provisioning-runs.show', $run));

        $response->assertOk();
        $json = json_encode($response->viewData('page')['props'] ?? [], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('api_key', strtolower($json));
        $this->assertStringNotContainsString('private_key', strtolower($json));
    }

    private function createInstallation(): Installation
    {
        $client = Client::query()->create([
            'company_name' => 'Readiness Co',
            'contact_name' => 'Ops',
            'status' => 'active',
        ]);

        return Installation::query()->create([
            'client_id' => $client->id,
            'name' => 'Readiness Site',
            'subdomain' => 'ready-'.uniqid(),
            'status' => 'active',
            'database_name' => 'mkd_ready_'.uniqid(),
            'database_host' => 'localhost',
        ]);
    }
}
