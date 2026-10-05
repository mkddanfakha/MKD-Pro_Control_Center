<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Database;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDatabaseGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use Illuminate\Support\Facades\Http;

/**
 * Gateway cPanel UAPI (Mysql) — non enregistré en production par défaut (TASK 359).
 *
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/Mysql-create_database/
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/Mysql-list_databases/
 */
final class CpanelUapiO2SwitchDatabaseGateway implements O2SwitchDatabaseGateway
{
    public const CPANEL_UAPI_EXECUTE_PATH = '/execute/Mysql';

    public function provisionDatabase(
        ProvisioningContext $context,
        O2SwitchDatabaseConfiguration $configuration,
    ): InfrastructureAdapterResult {
        $naming = O2SwitchDatabaseNaming::resolve($context, $configuration);
        if ($naming === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_database_context_incomplete',
                'Nom de base indéterminé ou incompatible avec le préfixe cPanel.',
            );
        }

        $token = config('provisioning.secrets.o2switch_api_token');
        $cpanelUser = config('provisioning.secrets.o2switch_cpanel_username');
        if (! is_string($token) || $token === '' || ! is_string($cpanelUser) || $cpanelUser === '') {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_database_not_configured',
                'Identifiants cPanel UAPI absents.',
            );
        }

        $host = $configuration->cpanelHost;
        $baseUrl = 'https://'.$host.':2083'.self::CPANEL_UAPI_EXECUTE_PATH;

        $listResponse = Http::withHeaders([
            'Authorization' => 'cpanel '.$cpanelUser.':'.$token,
        ])->get($baseUrl.'/list_databases');

        if ($listResponse->status() === 401 || $listResponse->status() === 403) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_database_access_denied',
                'Accès cPanel UAPI refusé pour lister les bases MySQL.',
            );
        }

        if (! $listResponse->successful()) {
            return InfrastructureAdapterResult::failed(
                'o2switch_database_list_failed',
                'Impossible de lister les bases MySQL via cPanel UAPI.',
                retryable: true,
                category: ProvisioningErrorCategory::Retryable,
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $listResponse->json() ?? [];
        $existing = $this->extractDatabaseNames($payload);

        if ($this->containsDatabase($existing, $naming->fullDatabaseName)) {
            return $this->idempotentSuccess($context, $configuration, $naming);
        }

        if ($context->databaseName !== null && trim($context->databaseName) !== '') {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_database_incompatible',
                sprintf(
                    'La base attendue « %s » est absente du compte cPanel.',
                    $naming->fullDatabaseName,
                ),
            );
        }

        $createResponse = Http::withHeaders([
            'Authorization' => 'cpanel '.$cpanelUser.':'.$token,
        ])->asForm()->post($baseUrl.'/create_database', [
            'name' => $naming->cpanelCreateSuffix,
        ]);

        if ($createResponse->status() === 401 || $createResponse->status() === 403) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_database_access_denied',
                'Accès cPanel UAPI refusé pour créer la base MySQL.',
            );
        }

        if ($createResponse->successful()) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'database_name' => $naming->fullDatabaseName,
                    'database_host' => $configuration->mysqlHostLogical,
                    'created' => true,
                ],
                metadata: [
                    'operation' => 'client_database',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $verifyResponse = Http::withHeaders([
            'Authorization' => 'cpanel '.$cpanelUser.':'.$token,
        ])->get($baseUrl.'/list_databases');

        if ($verifyResponse->successful()) {
            /** @var array<string, mixed> $verifyPayload */
            $verifyPayload = $verifyResponse->json() ?? [];
            if ($this->containsDatabase($this->extractDatabaseNames($verifyPayload), $naming->fullDatabaseName)) {
                return $this->idempotentSuccess($context, $configuration, $naming);
            }
        }

        return InfrastructureAdapterResult::failed(
            'o2switch_database_create_failed',
            'Création de base MySQL via cPanel UAPI en échec.',
            retryable: true,
            category: ProvisioningErrorCategory::Retryable,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function extractDatabaseNames(array $payload): array
    {
        $names = [];

        $data = $payload['result']['data'] ?? $payload['data'] ?? null;
        if (is_array($data)) {
            foreach ($data as $entry) {
                if (is_string($entry)) {
                    $names[] = strtolower($entry);
                } elseif (is_array($entry) && isset($entry['database']) && is_string($entry['database'])) {
                    $names[] = strtolower($entry['database']);
                } elseif (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
                    $names[] = strtolower($entry['name']);
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $existing
     */
    private function containsDatabase(array $existing, string $expectedFullName): bool
    {
        $needle = strtolower($expectedFullName);

        foreach ($existing as $name) {
            if ($name === $needle) {
                return true;
            }
        }

        return false;
    }

    private function idempotentSuccess(
        ProvisioningContext $context,
        O2SwitchDatabaseConfiguration $configuration,
        O2SwitchDatabaseNamingResolution $naming,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'database_name' => $naming->fullDatabaseName,
                'database_host' => $configuration->mysqlHostLogical,
                'idempotent_replay' => true,
            ],
            metadata: [
                'operation' => 'client_database',
                'installation_id' => $context->installationId,
                'idempotent_replay' => true,
                ...$configuration->safePublicMetadata(),
            ],
        );
    }
}
