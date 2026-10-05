<?php

namespace App\Services\Provisioning\Infrastructure\Preflight;

use App\Contracts\Provisioning\Infrastructure\InfrastructurePreflightCheck;
use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightState;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\CpanelFileUapiO2SwitchEnvironmentGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Environment\O2SwitchEnvironmentConfiguration;
use App\Support\Provisioning\InfrastructureLiveValidationProbeGuard;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class O2SwitchFilemanPreflightCheck implements InfrastructurePreflightCheck
{
    public const SERVICE_KEY = 'o2switch_fileman';

    public function serviceKey(): string
    {
        return self::SERVICE_KEY;
    }

    public function run(): InfrastructurePreflightCheckResult
    {
        $configuration = O2SwitchEnvironmentConfiguration::fromApplicationConfig();
        $featureEnabled = $configuration->enabled;
        $probeDir = trim((string) config('provisioning.preflight.fileman_probe_dir', ''));
        $probeFile = trim((string) config('provisioning.preflight.fileman_probe_file', ''));

        if ($configuration->cpanelHost === '') {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_fileman_host_missing',
                'Hôte cPanel absent pour le préflight Fileman.',
                $featureEnabled,
                false,
            );
        }

        $auth = O2SwitchCpanelPreflightHttp::authorizedClient($configuration->cpanelHost);
        if ($auth === null) {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_fileman_credentials_missing',
                'Identifiants cPanel UAPI absents pour le préflight Fileman.',
                $featureEnabled,
                false,
            );
        }

        if ($probeDir === '' || $probeFile === '') {
            return $this->result(
                InfrastructurePreflightState::PROTOCOL_PENDING,
                'o2switch_fileman_probe_not_configured',
                'Fichier de sonde Fileman absent : get_file_content read-only requiert PROVISIONING_PREFLIGHT_FILEMAN_PROBE_DIR et _FILE.',
                $featureEnabled,
                false,
                ['fileman_read' => 'not_configured'],
            );
        }

        $probeRejection = InfrastructureLiveValidationProbeGuard::validateFilemanProbe($probeDir, $probeFile);
        if ($probeRejection === InfrastructureLiveValidationProbeGuard::REJECTION_FORBIDDEN_PROBE_PATH) {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_fileman_probe_rejected',
                'Fichier de sonde Fileman refusé (probe non sûre).',
                $featureEnabled,
                false,
                ['fileman_read' => 'probe_rejected'],
            );
        }

        [$client, $host] = $auth;
        $url = O2SwitchCpanelPreflightHttp::uapiBaseUrl(
            $host,
            CpanelFileUapiO2SwitchEnvironmentGateway::CPANEL_UAPI_FILEMAN_EXECUTE_PATH,
        ).'/get_file_content';

        try {
            $response = $client->get($url, [
                'dir' => $probeDir,
                'file' => $probeFile,
            ]);

            if ($response->status() === 401) {
                return $this->result(
                    InfrastructurePreflightState::AUTHENTICATION_FAILED,
                    'o2switch_fileman_authentication_failed',
                    'Authentification cPanel refusée (Fileman get_file_content).',
                    $featureEnabled,
                    false,
                    ['http_status' => 401],
                );
            }

            if ($response->status() === 403) {
                return $this->result(
                    InfrastructurePreflightState::FORBIDDEN,
                    'o2switch_fileman_forbidden',
                    'Permissions cPanel insuffisantes pour lire le fichier de sonde.',
                    $featureEnabled,
                    false,
                    ['http_status' => 403],
                );
            }

            if (! $response->successful()) {
                return $this->result(
                    InfrastructurePreflightState::FAILED,
                    'o2switch_fileman_read_failed',
                    'Lecture Fileman read-only en échec (fichier de sonde introuvable ou chemin invalide).',
                    $featureEnabled,
                    false,
                    ['http_status' => $response->status()],
                );
            }

            return $this->result(
                InfrastructurePreflightState::READY,
                'o2switch_fileman_ready',
                'Fileman cPanel : get_file_content read-only OK sur le fichier de sonde.',
                $featureEnabled,
                true,
                ['fileman_read' => true],
            );
        } catch (ConnectionException) {
            return $this->result(
                InfrastructurePreflightState::UNREACHABLE,
                'o2switch_fileman_unreachable',
                'cPanel inaccessible pour le préflight Fileman.',
                $featureEnabled,
                false,
            );
        } catch (Throwable) {
            return $this->result(
                InfrastructurePreflightState::FAILED,
                'o2switch_fileman_preflight_error',
                'Échec du préflight Fileman cPanel.',
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
            serviceLabel: 'o2switch Fileman',
            capability: 'fileman_get_file_content_read_only',
            state: $state,
            code: $code,
            operatorMessage: $message,
            provisioningFeatureEnabled: $featureEnabled,
            configuredAndReachable: $reachable,
            diagnostics: $diagnostics,
        );
    }
}
