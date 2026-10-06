<?php

namespace App\Services\Provisioning\Infrastructure\Preflight;

use App\Contracts\Provisioning\Infrastructure\InfrastructurePreflightCheck;
use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightState;
use App\Services\Provisioning\Infrastructure\O2Switch\Database\O2SwitchDatabaseConfiguration;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Vérification read-only du compte cPanel (Variables/get_user_information) — TASK 3W.
 */
final class O2SwitchAccountPreflightCheck implements InfrastructurePreflightCheck
{
    public const SERVICE_KEY = 'o2switch_account';

    public const CPANEL_UAPI_VARIABLES_PATH = '/execute/Variables';

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
                'o2switch_account_host_missing',
                'Hôte cPanel absent pour la vérification compte o2switch.',
                $featureEnabled,
                false,
            );
        }

        $auth = O2SwitchCpanelPreflightHttp::authorizedClient($configuration->cpanelHost);
        if ($auth === null) {
            return $this->result(
                InfrastructurePreflightState::NOT_CONFIGURED,
                'o2switch_account_credentials_missing',
                'Identifiants cPanel UAPI absents pour la vérification compte o2switch.',
                $featureEnabled,
                false,
            );
        }

        [$client, $host] = $auth;
        $url = O2SwitchCpanelPreflightHttp::uapiBaseUrl($host, self::CPANEL_UAPI_VARIABLES_PATH).'/get_user_information';

        try {
            $response = $client->get($url);

            if ($response->status() === 401) {
                return $this->result(
                    InfrastructurePreflightState::AUTHENTICATION_FAILED,
                    'o2switch_account_authentication_failed',
                    'Authentification cPanel refusée (Variables get_user_information).',
                    $featureEnabled,
                    false,
                    ['http_status' => 401],
                );
            }

            if ($response->status() === 403) {
                return $this->result(
                    InfrastructurePreflightState::FORBIDDEN,
                    'o2switch_account_forbidden',
                    'Permissions cPanel insuffisantes pour lire les informations compte.',
                    $featureEnabled,
                    false,
                    ['http_status' => 403],
                );
            }

            if (! $response->successful()) {
                return $this->result(
                    InfrastructurePreflightState::FAILED,
                    'o2switch_account_read_failed',
                    'Lecture compte cPanel via UAPI en échec.',
                    $featureEnabled,
                    false,
                    ['http_status' => $response->status()],
                );
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return $this->result(
                    InfrastructurePreflightState::FAILED,
                    'o2switch_account_invalid_payload',
                    'Réponse cPanel invalide pour get_user_information.',
                    $featureEnabled,
                    false,
                );
            }

            /** @var mixed $resultBlock */
            $resultBlock = $payload['result'] ?? null;
            if (! is_array($resultBlock)) {
                return $this->result(
                    InfrastructurePreflightState::FAILED,
                    'o2switch_account_invalid_payload',
                    'Réponse cPanel invalide pour get_user_information.',
                    $featureEnabled,
                    false,
                );
            }

            $status = $resultBlock['status'] ?? null;
            if ($status !== 1 && $status !== '1') {
                return $this->result(
                    InfrastructurePreflightState::FAILED,
                    'o2switch_account_uapi_error',
                    'cPanel UAPI a signalé un échec pour get_user_information.',
                    $featureEnabled,
                    false,
                );
            }

            /** @var mixed $data */
            $data = $resultBlock['data'] ?? null;
            if (! is_array($data)) {
                return $this->result(
                    InfrastructurePreflightState::FAILED,
                    'o2switch_account_missing_data',
                    'Données compte cPanel absentes dans la réponse UAPI.',
                    $featureEnabled,
                    false,
                );
            }

            return $this->result(
                InfrastructurePreflightState::READY,
                'o2switch_account_ready',
                'Compte cPanel : authentification et get_user_information OK (lecture seule).',
                $featureEnabled,
                true,
                $this->safeAccountDiagnostics($data),
            );
        } catch (ConnectionException) {
            return $this->result(
                InfrastructurePreflightState::UNREACHABLE,
                'o2switch_account_unreachable',
                'cPanel inaccessible pour la vérification compte (connexion ou timeout).',
                $featureEnabled,
                false,
            );
        } catch (Throwable) {
            return $this->result(
                InfrastructurePreflightState::FAILED,
                'o2switch_account_preflight_error',
                'Échec du préflight compte cPanel.',
                $featureEnabled,
                false,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function safeAccountDiagnostics(array $data): array
    {
        $diagnostics = [
            'account_read' => true,
        ];

        foreach (['user', 'homedir', 'theme', 'server_name'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (is_string($value) && $value !== '') {
                $diagnostics[$key] = $value;
            }
        }

        return $diagnostics;
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
            serviceLabel: 'o2switch Account',
            capability: 'account_read',
            state: $state,
            code: $code,
            operatorMessage: $message,
            provisioningFeatureEnabled: $featureEnabled,
            configuredAndReachable: $reachable,
            diagnostics: $diagnostics,
        );
    }
}
