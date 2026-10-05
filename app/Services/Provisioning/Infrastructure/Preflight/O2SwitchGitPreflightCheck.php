<?php

namespace App\Services\Provisioning\Infrastructure\Preflight;

use App\Contracts\Provisioning\Infrastructure\InfrastructurePreflightCheck;
use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightState;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\CpanelGitUapiO2SwitchDeployGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployConfiguration;
use App\Support\Provisioning\InfrastructureLiveValidationProbeGuard;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class O2SwitchGitPreflightCheck implements InfrastructurePreflightCheck
{
    public const SERVICE_KEY = 'o2switch_git';

    public function serviceKey(): string
    {
        return self::SERVICE_KEY;
    }

    public function run(): InfrastructurePreflightCheckResult
    {
        $configuration = O2SwitchDeployConfiguration::fromApplicationConfig();
        $featureEnabled = $configuration->enabled;
        $probeRoot = trim((string) config('provisioning.preflight.git_probe_root', ''));

        if ($configuration->cpanelHost === '') {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_git_host_missing',
                'Hôte cPanel absent pour le préflight Git.',
                $featureEnabled,
                false,
            );
        }

        $auth = O2SwitchCpanelPreflightHttp::authorizedClient($configuration->cpanelHost);
        if ($auth === null) {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_git_credentials_missing',
                'Identifiants cPanel UAPI absents pour le préflight Git.',
                $featureEnabled,
                false,
            );
        }

        if ($probeRoot === '') {
            return $this->result(
                InfrastructurePreflightState::PROTOCOL_PENDING,
                'o2switch_git_probe_path_not_configured',
                'Chemin de sonde Git absent : retrieve read-only non exécutable sans PROVISIONING_PREFLIGHT_GIT_PROBE_ROOT.',
                $featureEnabled,
                false,
                ['git_retrieve' => 'not_configured'],
            );
        }

        $probeRejection = InfrastructureLiveValidationProbeGuard::validateGitProbeRoot($probeRoot);
        if ($probeRejection === InfrastructureLiveValidationProbeGuard::REJECTION_FORBIDDEN_PROBE_PATH) {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_git_probe_rejected',
                'Chemin de sonde Git refusé (probe non sûre).',
                $featureEnabled,
                false,
                ['git_retrieve' => 'probe_rejected'],
            );
        }

        [$client, $host] = $auth;
        $url = O2SwitchCpanelPreflightHttp::uapiBaseUrl(
            $host,
            CpanelGitUapiO2SwitchDeployGateway::CPANEL_UAPI_GIT_EXECUTE_PATH,
        ).'/retrieve';

        try {
            $response = $client->get($url, ['root' => $probeRoot]);

            if ($response->status() === 401) {
                return $this->result(
                    InfrastructurePreflightState::AUTHENTICATION_FAILED,
                    'o2switch_git_authentication_failed',
                    'Authentification cPanel refusée (Git retrieve).',
                    $featureEnabled,
                    false,
                    ['http_status' => 401],
                );
            }

            if ($response->status() === 403) {
                return $this->result(
                    InfrastructurePreflightState::FORBIDDEN,
                    'o2switch_git_forbidden',
                    'Permissions cPanel insuffisantes pour Git retrieve.',
                    $featureEnabled,
                    false,
                    ['http_status' => 403],
                );
            }

            if (! $response->successful()) {
                return $this->result(
                    InfrastructurePreflightState::FAILED,
                    'o2switch_git_retrieve_failed',
                    'Git retrieve read-only en échec (dépôt absent ou chemin invalide).',
                    $featureEnabled,
                    false,
                    ['http_status' => $response->status()],
                );
            }

            return $this->result(
                InfrastructurePreflightState::READY,
                'o2switch_git_ready',
                'Git cPanel : retrieve read-only exécutable sur le chemin de sonde.',
                $featureEnabled,
                true,
                ['git_retrieve' => true],
            );
        } catch (ConnectionException) {
            return $this->result(
                InfrastructurePreflightState::UNREACHABLE,
                'o2switch_git_unreachable',
                'cPanel inaccessible pour le préflight Git.',
                $featureEnabled,
                false,
            );
        } catch (Throwable) {
            return $this->result(
                InfrastructurePreflightState::FAILED,
                'o2switch_git_preflight_error',
                'Échec du préflight Git cPanel.',
                $featureEnabled,
                false,
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $diagnostics
     */
    private function result(
        string $state,
        string $code,
        string $message,
        bool $featureEnabled,
        bool $reachable,
        ?array $diagnostics = null,
    ): InfrastructurePreflightCheckResult {
        return new InfrastructurePreflightCheckResult(
            serviceKey: self::SERVICE_KEY,
            serviceLabel: 'o2switch Git',
            capability: 'git_retrieve_read_only',
            state: $state,
            code: $code,
            operatorMessage: $message,
            provisioningFeatureEnabled: $featureEnabled,
            configuredAndReachable: $reachable,
            diagnostics: $diagnostics,
        );
    }
}
