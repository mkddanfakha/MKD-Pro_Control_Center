<?php

namespace App\Services\Provisioning\Infrastructure\Cloudflare;

use App\Contracts\Provisioning\Infrastructure\Cloudflare\CloudflareDnsGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use Illuminate\Support\Facades\Http;

/**
 * Gateway Cloudflare API v4 — non enregistré en production par défaut (TASK 358).
 *
 * @see https://developers.cloudflare.com/api/operations/dns-records-for-a-zone-list-dns-records
 * @see https://developers.cloudflare.com/api/operations/dns-records-for-a-zone-create-dns-record
 */
final class HttpCloudflareDnsGateway implements CloudflareDnsGateway
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function configureDns(
        ProvisioningContext $context,
        CloudflareDnsConfiguration $configuration,
    ): InfrastructureAdapterResult {
        $token = $configuration->apiToken();
        if ($token === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'cloudflare_dns_not_configured',
                'Token API Cloudflare absent.',
            );
        }

        $recordName = CloudflareDnsRecordNaming::recordFqdn($context, $configuration);
        if ($recordName === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'cloudflare_dns_context_incomplete',
                'Nom d\'enregistrement DNS indéterminé.',
            );
        }

        $zoneId = $this->resolveZoneId($configuration, $token);
        if ($zoneId === null) {
            return InfrastructureAdapterResult::failed(
                'cloudflare_dns_zone_not_found',
                'Zone Cloudflare introuvable pour la configuration fournie.',
                retryable: false,
                category: ProvisioningErrorCategory::Definitive,
                metadata: [
                    'operation' => 'dns',
                    'installation_id' => $context->installationId,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        $existing = $this->findExistingRecord($zoneId, $recordName, $configuration, $token);
        if ($existing === false) {
            return InfrastructureAdapterResult::failed(
                'cloudflare_dns_list_failed',
                'Impossible de lister les enregistrements DNS Cloudflare.',
                retryable: true,
                category: ProvisioningErrorCategory::Retryable,
            );
        }

        if ($existing !== null) {
            $expectedContent = config('provisioning.cloudflare.dns.record_content');
            $currentContent = (string) ($existing['content'] ?? '');
            if (
                is_string($expectedContent)
                && trim($expectedContent) !== ''
                && $currentContent !== ''
                && trim($expectedContent) !== $currentContent
            ) {
                return InfrastructureAdapterResult::failed(
                    'cloudflare_dns_conflict',
                    'Un enregistrement DNS incompatible existe déjà pour ce nom.',
                    retryable: false,
                    category: ProvisioningErrorCategory::Definitive,
                    outputSummary: ['record_name' => $recordName],
                );
            }

            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'record_name' => $recordName,
                    'record_type' => $existing['type'] ?? $configuration->recordType,
                    'idempotent_replay' => true,
                ],
                metadata: [
                    'operation' => 'dns',
                    'provider' => $configuration->provider,
                    'installation_id' => $context->installationId,
                    'zone_id' => $zoneId,
                ],
            );
        }

        $targetContent = config('provisioning.cloudflare.dns.record_content');
        if (! is_string($targetContent) || trim($targetContent) === '') {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'cloudflare_dns_record_content_pending',
                'Cible DNS (contenu enregistrement) non configurée : création réelle reportée.',
                outputSummary: ['record_name' => $recordName],
                metadata: [
                    'operation' => 'dns',
                    'installation_id' => $context->installationId,
                    'zone_id' => $zoneId,
                ],
            );
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(self::API_BASE.'/zones/'.$zoneId.'/dns_records', [
                'type' => $configuration->recordType,
                'name' => $recordName,
                'content' => trim($targetContent),
                'proxied' => false,
            ]);

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            return InfrastructureAdapterResult::failed(
                'cloudflare_dns_create_failed',
                'Échec de création de l\'enregistrement DNS Cloudflare.',
                retryable: true,
                category: ProvisioningErrorCategory::Retryable,
            );
        }

        return InfrastructureAdapterResult::succeeded(
            outputSummary: [
                'record_name' => $recordName,
                'record_type' => $configuration->recordType,
            ],
            metadata: [
                'operation' => 'dns',
                'provider' => $configuration->provider,
                'installation_id' => $context->installationId,
                'zone_id' => $zoneId,
            ],
        );
    }

    private function resolveZoneId(CloudflareDnsConfiguration $configuration, string $token): ?string
    {
        if ($configuration->zoneId !== '') {
            return $configuration->zoneId;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->get(self::API_BASE.'/zones', [
                'name' => $configuration->zoneName,
            ]);

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            return null;
        }

        $zones = $response->json('result');
        if (! is_array($zones) || $zones === []) {
            return null;
        }

        $first = $zones[0];

        return is_array($first) && isset($first['id']) ? (string) $first['id'] : null;
    }

    /**
     * @return array<string, mixed>|null|null Existe déjà, null si absent, false si erreur API
     */
    private function findExistingRecord(
        string $zoneId,
        string $recordName,
        CloudflareDnsConfiguration $configuration,
        string $token,
    ): array|false|null {
        $response = Http::withToken($token)
            ->acceptJson()
            ->get(self::API_BASE.'/zones/'.$zoneId.'/dns_records', [
                'name' => $recordName,
                'type' => $configuration->recordType,
            ]);

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            return false;
        }

        $records = $response->json('result');
        if (! is_array($records) || $records === []) {
            return null;
        }

        return is_array($records[0]) ? $records[0] : null;
    }
}
