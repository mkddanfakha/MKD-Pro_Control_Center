<?php

namespace Tests\Fakes\Provisioning\Infrastructure;

use App\DTO\Provisioning\ProvisioningContext;

trait RecordsAdapterInvocations
{
    /** @var list<array{operation: string, installation_id: int}> */
    public array $invocations = [];

    protected function recordInvocation(string $operation, ProvisioningContext $context): void
    {
        $this->invocations[] = [
            'operation' => $operation,
            'installation_id' => $context->installationId,
        ];
    }
}
