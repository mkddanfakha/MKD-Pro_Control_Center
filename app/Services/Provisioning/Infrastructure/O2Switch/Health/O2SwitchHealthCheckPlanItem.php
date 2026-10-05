<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Health;

final class O2SwitchHealthCheckPlanItem
{
    /**
     * @param  list<string>  $dependsOnCheckKeys
     * @param  list<string>|null  $artisanArgv
     */
    public function __construct(
        public readonly string $checkKey,
        public readonly int $order,
        public readonly string $type,
        public readonly string $scope,
        public readonly string $category,
        public readonly string $expectedOutcome,
        public readonly int $logicalTimeoutSeconds,
        public readonly array $dependsOnCheckKeys,
        public readonly ?string $httpPath = null,
        public readonly ?string $relativePath = null,
        public readonly ?string $externalReferenceKey = null,
        public readonly ?array $artisanArgv = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSafePlanArray(): array
    {
        return array_filter([
            'check_key' => $this->checkKey,
            'order' => $this->order,
            'type' => $this->type,
            'scope' => $this->scope,
            'category' => $this->category,
            'expected_outcome' => $this->expectedOutcome,
            'logical_timeout_seconds' => $this->logicalTimeoutSeconds,
            'depends_on' => $this->dependsOnCheckKeys !== [] ? $this->dependsOnCheckKeys : null,
            'http_path' => $this->httpPath,
            'relative_path' => $this->relativePath,
            'external_reference_key' => $this->externalReferenceKey,
            'artisan_command' => $this->artisanArgv !== null && isset($this->artisanArgv[2])
                ? $this->artisanArgv[2]
                : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
