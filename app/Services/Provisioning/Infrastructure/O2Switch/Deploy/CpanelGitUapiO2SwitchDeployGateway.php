<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Deploy;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchDeployGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use Illuminate\Support\Facades\Http;

/**
 * Gateway cPanel UAPI (Git Version Control) — non enregistré en production par défaut (TASK 360).
 *
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/create_repo/
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/update_repo/
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/retrieve_repo/
 */
final class CpanelGitUapiO2SwitchDeployGateway implements O2SwitchDeployGateway
{
    public const CPANEL_UAPI_GIT_EXECUTE_PATH = '/execute/Git';

    public function deployApplication(
        ProvisioningContext $context,
        O2SwitchDeployConfiguration $configuration,
    ): InfrastructureAdapterResult {
        $path = O2SwitchDeployPathResolver::resolve($context, $configuration);
        if ($path === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_deploy_invalid_path',
                'Chemin de déploiement distant invalide.',
            );
        }

        $revision = O2SwitchDeployRevision::resolve($context, $configuration);
        if ($revision === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_deploy_not_configured',
                'Référence Git absente pour le déploiement.',
            );
        }

        $token = config('provisioning.secrets.o2switch_api_token');
        $cpanelUser = config('provisioning.secrets.o2switch_cpanel_username');
        if (! is_string($token) || $token === '' || ! is_string($cpanelUser) || $cpanelUser === '') {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_deploy_not_configured',
                'Identifiants cPanel UAPI absents.',
            );
        }

        $host = $configuration->cpanelHost;
        $baseUrl = 'https://'.$host.':2083'.self::CPANEL_UAPI_GIT_EXECUTE_PATH;
        $authHeader = ['Authorization' => 'cpanel '.$cpanelUser.':'.$token];

        $retrieveResponse = Http::withHeaders($authHeader)->get($baseUrl.'/retrieve', [
            'root' => $path->absoluteDeployPath,
        ]);

        if ($retrieveResponse->status() === 401 || $retrieveResponse->status() === 403) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_deploy_access_denied',
                'Accès cPanel UAPI refusé pour inspecter le dépôt Git.',
            );
        }

        $repositoryPresent = $retrieveResponse->successful()
            && $this->repositoryExists($retrieveResponse->json());

        if ($repositoryPresent) {
            $deployedRef = $this->extractDeployedReference($retrieveResponse->json());

            if ($deployedRef === null) {
                return InfrastructureAdapterResult::manualInterventionRequired(
                    'o2switch_deploy_inconsistent',
                    'Dépôt Git présent mais état de révision illisible.',
                );
            }

            if ($this->referencesMatch($deployedRef, $revision->reference)) {
                return InfrastructureAdapterResult::succeeded(
                    outputSummary: [
                        'deploy_path' => $path->absoluteDeployPath,
                        'deployed_reference' => $deployedRef,
                        'idempotent_replay' => true,
                    ],
                    metadata: [
                        'operation' => 'application_deploy',
                        'installation_id' => $context->installationId,
                        'idempotent_replay' => true,
                        ...$configuration->safePublicMetadata(),
                    ],
                );
            }

            $updateResponse = Http::withHeaders($authHeader)->asForm()->post($baseUrl.'/update', [
                'root' => $path->absoluteDeployPath,
                'branch' => $revision->referenceKind === 'commit' ? '' : $revision->reference,
            ]);

            if ($updateResponse->status() === 401 || $updateResponse->status() === 403) {
                return InfrastructureAdapterResult::manualInterventionRequired(
                    'o2switch_deploy_access_denied',
                    'Accès cPanel UAPI refusé pour mettre à jour le dépôt Git.',
                );
            }

            if ($updateResponse->successful()) {
                return InfrastructureAdapterResult::succeeded(
                    outputSummary: [
                        'deploy_path' => $path->absoluteDeployPath,
                        'deployed_reference' => $revision->reference,
                        'updated' => true,
                    ],
                    metadata: [
                        'operation' => 'application_deploy',
                        'installation_id' => $context->installationId,
                        ...$configuration->safePublicMetadata(),
                    ],
                );
            }

            return InfrastructureAdapterResult::failed(
                'o2switch_deploy_update_failed',
                'Mise à jour Git via cPanel UAPI en échec.',
                retryable: true,
                category: ProvisioningErrorCategory::Retryable,
            );
        }

        if ($retrieveResponse->successful()) {
            $createResponse = Http::withHeaders($authHeader)->asForm()->post($baseUrl.'/create', [
                'root' => $path->absoluteDeployPath,
                'source' => $configuration->gestionGitRepositoryUrl,
                'branch' => $revision->referenceKind === 'commit' ? '' : $revision->reference,
                'name' => 'gestion_'.$context->installationId,
            ]);

            if ($createResponse->status() === 401 || $createResponse->status() === 403) {
                return InfrastructureAdapterResult::manualInterventionRequired(
                    'o2switch_deploy_access_denied',
                    'Accès cPanel UAPI refusé pour créer le dépôt Git.',
                );
            }

            if ($createResponse->successful()) {
                return InfrastructureAdapterResult::succeeded(
                    outputSummary: [
                        'deploy_path' => $path->absoluteDeployPath,
                        'deployed_reference' => $revision->reference,
                        'created' => true,
                    ],
                    metadata: [
                        'operation' => 'application_deploy',
                        'installation_id' => $context->installationId,
                        ...$configuration->safePublicMetadata(),
                    ],
                );
            }

            return InfrastructureAdapterResult::failed(
                'o2switch_deploy_create_failed',
                'Création du dépôt Git via cPanel UAPI en échec.',
                retryable: true,
                category: ProvisioningErrorCategory::Retryable,
            );
        }

        return InfrastructureAdapterResult::failed(
            'o2switch_deploy_inspect_failed',
            'Inspection du dépôt Git distante en échec.',
            retryable: true,
            category: ProvisioningErrorCategory::Retryable,
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function repositoryExists(?array $payload): bool
    {
        if ($payload === null) {
            return false;
        }

        $data = $payload['result']['data'] ?? $payload['data'] ?? null;

        return is_array($data) && $data !== [];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractDeployedReference(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $data = $payload['result']['data'] ?? $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        foreach (['branch', 'commit', 'head', 'ref', 'deployed_ref'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        if (isset($data[0]) && is_array($data[0])) {
            foreach (['branch', 'commit', 'head', 'ref'] as $key) {
                if (isset($data[0][$key]) && is_string($data[0][$key]) && $data[0][$key] !== '') {
                    return $data[0][$key];
                }
            }
        }

        return null;
    }

    private function referencesMatch(string $deployed, string $expected): bool
    {
        $deployedNormalized = strtolower(trim($deployed));
        $expectedNormalized = strtolower(trim($expected));

        if ($deployedNormalized === $expectedNormalized) {
            return true;
        }

        if (strlen($expectedNormalized) === 40 && str_starts_with($deployedNormalized, $expectedNormalized)) {
            return true;
        }

        return false;
    }
}
