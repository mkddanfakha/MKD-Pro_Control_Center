<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\Contracts\Provisioning\Infrastructure\Cloudflare\CloudflareDnsGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsConfiguration;

final class FakeCloudflareDnsGateway implements CloudflareDnsGateway
{
    public ?InfrastructureAdapterResult $nextResult = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function configureDns(
        ProvisioningContext $context,
        CloudflareDnsConfiguration $configuration,
    ): InfrastructureAdapterResult {
        $this->calls[] = [
            'installation_id' => $context->installationId,
            'zone_name' => $configuration->zoneName,
        ];

        return $this->nextResult ?? InfrastructureAdapterResult::manualInterventionRequired(
            'fake_cloudflare',
            'Fake gateway par défaut.',
        );
    }
}
