<?php

namespace App\Services\Provisioning\Infrastructure\Preflight;

use App\Contracts\Provisioning\Infrastructure\InfrastructurePreflightCheck;
use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightState;
use App\Services\Provisioning\Infrastructure\Cloudflare\CloudflareDnsConfiguration;
use App\Services\Provisioning\Infrastructure\Cloudflare\HttpCloudflareDnsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class CloudflareDnsPreflightCheck implements InfrastructurePreflightCheck
{
    public const SERVICE_KEY = 'cloudflare_dns';

    public function serviceKey(): string
    {
        return self::SERVICE_KEY;
    }

    public function run(): InfrastructurePreflightCheckResult
    {
        $configuration = CloudflareDnsConfiguration::fromApplicationConfig();
        $featureEnabled = $configuration->enabled;

        if ($configuration->apiToken() === null) {
            return $this->result(
                state: InfrastructurePreflightState::NOT_CONFIGURED,
                code: 'cloudflare_dns_token_missing',
                message: 'Token API Cloudflare absent.',
                featureEnabled: $featureEnabled,
                reachable: false,
                capability: 'dns_zone_and_records_read',
            );
        }

        if (! $configuration->hasZoneReference()) {
            return $this->result(
                state: InfrastructurePreflightState::NOT_CONFIGURED,
                code: 'cloudflare_dns_zone_missing',
                message: 'Zone Cloudflare (nom ou identifiant) absente.',
                featureEnabled: $featureEnabled,
                reachable: false,
                capability: 'dns_zone_and_records_read',
            );
        }

        $token = $configuration->apiToken();
        $timeout = (int) config('provisioning.preflight.http_timeout_seconds', 15);
        $timeout = $timeout > 0 ? $timeout : 15;

        try {
            $zoneLookup = $this->resolveZoneId($configuration, $token, $timeout);
            if ($zoneLookup['state'] !== null) {
                return $this->result(
                    state: $zoneLookup['state'],
                    code: (string) $zoneLookup['code'],
                    message: (string) $zoneLookup['message'],
                    featureEnabled: $featureEnabled,
                    reachable: false,
                    capability: 'dns_zone_and_records_read',
                    diagnostics: is_array($zoneLookup['diagnostics'] ?? null) ? $zoneLookup['diagnostics'] : null,
                );
            }

            $zoneId = $zoneLookup['zone_id'] ?? null;
            if (! is_string($zoneId) || $zoneId === '') {
                return $this->result(
                    state: InfrastructurePreflightState::FAILED,
                    code: 'cloudflare_dns_zone_unreachable',
                    message: 'Zone Cloudflare introuvable ou réponse API invalide.',
                    featureEnabled: $featureEnabled,
                    reachable: false,
                    capability: 'dns_zone_and_records_read',
                    diagnostics: ['http_status' => 'zone_lookup_failed'],
                );
            }

            $recordsResponse = Http::timeout($timeout)
                ->withToken($token)
                ->acceptJson()
                ->get(HttpCloudflareDnsGateway::API_BASE.'/zones/'.$zoneId.'/dns_records', [
                    'per_page' => 1,
                ]);

            return $this->mapCloudflareResponse(
                $recordsResponse->status(),
                $recordsResponse->json(),
                $featureEnabled,
                $zoneId,
            );
        } catch (ConnectionException) {
            return $this->result(
                state: InfrastructurePreflightState::UNREACHABLE,
                code: 'cloudflare_dns_unreachable',
                message: 'API Cloudflare inaccessible (timeout ou réseau).',
                featureEnabled: $featureEnabled,
                reachable: false,
                capability: 'dns_zone_and_records_read',
            );
        } catch (Throwable) {
            return $this->result(
                state: InfrastructurePreflightState::FAILED,
                code: 'cloudflare_dns_preflight_error',
                message: 'Échec du préflight Cloudflare DNS.',
                featureEnabled: $featureEnabled,
                reachable: false,
                capability: 'dns_zone_and_records_read',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function mapCloudflareResponse(
        int $status,
        mixed $payload,
        bool $featureEnabled,
        string $zoneId,
    ): InfrastructurePreflightCheckResult {
        if ($status === 401) {
            return $this->result(
                state: InfrastructurePreflightState::AUTHENTICATION_FAILED,
                code: 'cloudflare_dns_authentication_failed',
                message: 'Authentification Cloudflare refusée.',
                featureEnabled: $featureEnabled,
                reachable: false,
                capability: 'dns_zone_and_records_read',
                diagnostics: ['http_status' => $status],
            );
        }

        if ($status === 403) {
            return $this->result(
                state: InfrastructurePreflightState::FORBIDDEN,
                code: 'cloudflare_dns_forbidden',
                message: 'Permissions Cloudflare insuffisantes pour lire les enregistrements DNS.',
                featureEnabled: $featureEnabled,
                reachable: false,
                capability: 'dns_zone_and_records_read',
                diagnostics: ['http_status' => $status],
            );
        }

        if ($status < 200 || $status >= 300) {
            return $this->result(
                state: InfrastructurePreflightState::FAILED,
                code: 'cloudflare_dns_list_failed',
                message: 'Lecture des enregistrements DNS Cloudflare en échec.',
                featureEnabled: $featureEnabled,
                reachable: false,
                capability: 'dns_zone_and_records_read',
                diagnostics: ['http_status' => $status],
            );
        }

        if (! is_array($payload) || ! ($payload['success'] ?? false)) {
            return $this->result(
                state: InfrastructurePreflightState::FAILED,
                code: 'cloudflare_dns_invalid_response',
                message: 'Réponse API Cloudflare invalide lors de la lecture DNS.',
                featureEnabled: $featureEnabled,
                reachable: false,
                capability: 'dns_zone_and_records_read',
            );
        }

        return $this->result(
            state: InfrastructurePreflightState::READY,
            code: 'cloudflare_dns_ready',
            message: 'Cloudflare DNS : zone et lecture DNS vérifiables.',
            featureEnabled: $featureEnabled,
            reachable: true,
            capability: 'dns_zone_and_records_read',
            diagnostics: [
                'zone_id' => $zoneId,
                'dns_records_readable' => true,
            ],
        );
    }

    /**
     * @return array{state: ?string, code?: string, message?: string, diagnostics?: array<string, mixed>, zone_id?: ?string}
     */
    private function resolveZoneId(CloudflareDnsConfiguration $configuration, string $token, int $timeout): array
    {
        if ($configuration->zoneId !== '') {
            $verify = Http::timeout($timeout)
                ->withToken($token)
                ->acceptJson()
                ->get(HttpCloudflareDnsGateway::API_BASE.'/zones/'.$configuration->zoneId);

            if ($verify->status() === 401) {
                return [
                    'state' => InfrastructurePreflightState::AUTHENTICATION_FAILED,
                    'code' => 'cloudflare_dns_authentication_failed',
                    'message' => 'Authentification Cloudflare refusée.',
                    'diagnostics' => ['http_status' => 401],
                ];
            }

            if ($verify->status() === 403) {
                return [
                    'state' => InfrastructurePreflightState::FORBIDDEN,
                    'code' => 'cloudflare_dns_forbidden',
                    'message' => 'Permissions Cloudflare insuffisantes pour lire la zone.',
                    'diagnostics' => ['http_status' => 403],
                ];
            }

            if ($verify->successful() && ($verify->json('success') ?? false)) {
                return ['state' => null, 'zone_id' => $configuration->zoneId];
            }

            return ['state' => null, 'zone_id' => null];
        }

        $response = Http::timeout($timeout)
            ->withToken($token)
            ->acceptJson()
            ->get(HttpCloudflareDnsGateway::API_BASE.'/zones', [
                'name' => $configuration->zoneName,
            ]);

        if ($response->status() === 401) {
            return [
                'state' => InfrastructurePreflightState::AUTHENTICATION_FAILED,
                'code' => 'cloudflare_dns_authentication_failed',
                'message' => 'Authentification Cloudflare refusée.',
                'diagnostics' => ['http_status' => 401],
            ];
        }

        if ($response->status() === 403) {
            return [
                'state' => InfrastructurePreflightState::FORBIDDEN,
                'code' => 'cloudflare_dns_forbidden',
                'message' => 'Permissions Cloudflare insuffisantes pour lister les zones.',
                'diagnostics' => ['http_status' => 403],
            ];
        }

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            return ['state' => null, 'zone_id' => null];
        }

        $zones = $response->json('result');
        if (! is_array($zones) || $zones === []) {
            return ['state' => null, 'zone_id' => null];
        }

        $first = $zones[0];
        $zoneId = is_array($first) && isset($first['id']) ? (string) $first['id'] : null;

        return ['state' => null, 'zone_id' => $zoneId];
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
        string $capability,
        ?array $diagnostics = null,
    ): InfrastructurePreflightCheckResult {
        return new InfrastructurePreflightCheckResult(
            serviceKey: self::SERVICE_KEY,
            serviceLabel: 'Cloudflare DNS',
            capability: $capability,
            state: $state,
            code: $code,
            operatorMessage: $message,
            provisioningFeatureEnabled: $featureEnabled,
            configuredAndReachable: $reachable,
            diagnostics: $diagnostics,
        );
    }
}
