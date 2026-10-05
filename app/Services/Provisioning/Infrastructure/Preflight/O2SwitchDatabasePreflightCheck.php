<?php

namespace App\Services\Provisioning\Infrastructure\Preflight;

use App\Contracts\Provisioning\Infrastructure\InfrastructurePreflightCheck;
use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightState;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\CpanelUapiO2SwitchDatabaseGateway;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseConfiguration;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class O2SwitchDatabasePreflightCheck implements InfrastructurePreflightCheck
{
    public const SERVICE_KEY = 'o2switch_database';

    public function serviceKey(): string
    {
        return self::SERVICE_KEY;
    }

    public function run(): InfrastructurePreflightCheckResult
    {
        $configuration = O2SwitchDatabaseConfiguration::fromApplicationConfig();
        $featureEnabled = $configuration->enabled;

        if ($configuration->cpanelHost === '') {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_database_host_missing',
                'Hôte cPanel absent pour le préflight base MySQL.',
                $featureEnabled,
                false,
            );
        }

        $auth = O2SwitchCpanelPreflightHttp::authorizedClient($configuration->cpanelHost);
        if ($auth === null) {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_database_credentials_missing',
                'Identifiants cPanel UAPI absents pour le préflight MySQL.',
                $featureEnabled,
                false,
            );
        }

        [$client, $host] = $auth;
        $url = O2SwitchCpanelPreflightHttp::uapiBaseUrl(
            $host,
            CpanelUapiO2SwitchDatabaseGateway::CPANEL_UAPI_EXECUTE_PATH,
        ).'/list_databases';

        try {
            $response = $client->get($url);

            if ($response->status() === 401) {
                return $this->result(
                    InfrastructurePreflightState::AUTHENTICATION_FAILED,
                    'o2switch_database_authentication_failed',
                    'Authentification cPanel refusée (MySQL list_databases).',
                    $featureEnabled,
                    false,
                    ['http_status' => 401],
                );
            }

            if ($response->status() === 403) {
                return $this->result(
                    InfrastructurePreflightState::FORBIDDEN,
                    'o2switch_database_forbidden',
                    'Permissions cPanel insuffisantes pour lister les bases MySQL.',
                    $featureEnabled,
                    false,
                    ['http_status' => 403],
                );
            }

            if (! $response->successful()) {
                return $this->result(
                    InfrastructurePreflightState::FAILED,
                    'o2switch_database_list_failed',
                    'Liste des bases MySQL via cPanel UAPI en échec.',
                    $featureEnabled,
                    false,
                    ['http_status' => $response->status()],
                );
            }

            return $this->result(
                InfrastructurePreflightState::READY,
                'o2switch_database_ready',
                'MySQL cPanel : authentification et list_databases OK.',
                $featureEnabled,
                true,
                ['mysql_list_databases' => true],
            );
        } catch (ConnectionException) {
            return $this->result(
                InfrastructurePreflightState::UNREACHABLE,
                'o2switch_database_unreachable',
                'cPanel inaccessible pour le préflight MySQL.',
                $featureEnabled,
                false,
            );
        } catch (Throwable) {
            return $this->result(
                InfrastructurePreflightState::FAILED,
                'o2switch_database_preflight_error',
                'Échec du préflight MySQL cPanel.',
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
            serviceLabel: 'o2switch Database',
            capability: 'mysql_list_databases',
            state: $state,
            code: $code,
            operatorMessage: $message,
            provisioningFeatureEnabled: $featureEnabled,
            configuredAndReachable: $reachable,
            diagnostics: $diagnostics,
        );
    }
}
