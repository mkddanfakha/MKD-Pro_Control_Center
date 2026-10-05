<?php

namespace App\DTO\Provisioning;

use App\Models\Installation;
use App\Models\ProvisioningRun;
use InvalidArgumentException;

/**
 * Données d'exécution d'un run — jamais de credentials bruts (voir isForbiddenSecretKey).
 */
final class ProvisioningContext
{
    /**
     * @param  array<string, mixed>  $configuration  Paramètres non secrets (hostnames logiques, flags, etc.)
     * @param  array<string, mixed>  $externalReferences  Identifiants ressources externes non secrets
     */
    public readonly int $provisioningRunId;

    public readonly int $installationId;

    public readonly int $clientId;

    public readonly string $installationName;

    public readonly string $subdomain;

    public readonly ?string $domain;

    public readonly ?string $databaseName;

    public readonly ?string $databaseHost;

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $externalReferences
     */
    public function __construct(
        public readonly ProvisioningRun $provisioningRun,
        public readonly Installation $installation,
        public readonly ?string $targetVersion,
        public readonly ?string $targetCommit,
        public readonly ?string $pipelineVersion,
        public readonly array $configuration = [],
        public readonly array $externalReferences = [],
    ) {
        $this->provisioningRunId = (int) $provisioningRun->id;
        $this->installationId = (int) $installation->id;
        $this->clientId = (int) $installation->client_id;
        $this->installationName = (string) $installation->name;
        $this->subdomain = (string) $installation->subdomain;
        $this->domain = $installation->domain !== null ? (string) $installation->domain : null;
        $this->databaseName = $installation->database_name !== null ? (string) $installation->database_name : null;
        $this->databaseHost = $installation->database_host !== null ? (string) $installation->database_host : null;

        $this->assertNoForbiddenSecrets($configuration, 'configuration');
        $this->assertNoForbiddenSecrets($externalReferences, 'externalReferences');
    }

    public static function fromRun(ProvisioningRun $run): self
    {
        $run->loadMissing('installation');

        if ($run->installation === null) {
            throw new InvalidArgumentException('ProvisioningRun sans installation associée.');
        }

        $installation = $run->installation;

        return new self(
            provisioningRun: $run,
            installation: $installation,
            targetVersion: $run->target_version,
            targetCommit: $run->target_commit,
            pipelineVersion: $run->pipeline_version,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'provisioning_run_id' => $this->provisioningRunId,
            'installation_id' => $this->installationId,
            'client_id' => $this->clientId,
            'installation_name' => $this->installationName,
            'subdomain' => $this->subdomain,
            'domain' => $this->domain,
            'database_name' => $this->databaseName,
            'database_host' => $this->databaseHost,
            'target_version' => $this->targetVersion,
            'target_commit' => $this->targetCommit,
            'pipeline_version' => $this->pipelineVersion,
            'configuration' => $this->configuration,
            'external_references' => $this->externalReferences,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoForbiddenSecrets(array $data, string $field): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isForbiddenSecretKey($key)) {
                throw new InvalidArgumentException(
                    "Clé interdite dans ProvisioningContext->{$field} : {$key}."
                );
            }

            if (is_array($value)) {
                $this->assertNoForbiddenSecrets($value, $field);
            }
        }
    }

    public static function isForbiddenSecretKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach ([
            'password',
            'secret',
            'token',
            'api_key',
            'private_key',
            'credential',
            'vault',
            'env_body',
        ] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
