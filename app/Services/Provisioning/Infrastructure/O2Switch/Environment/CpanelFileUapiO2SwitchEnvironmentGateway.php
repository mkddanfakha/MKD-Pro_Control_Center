<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

use App\Contracts\Provisioning\Infrastructure\O2Switch\O2SwitchEnvironmentGateway;
use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\DTO\Provisioning\ProvisioningErrorCategory;
use Illuminate\Support\Facades\Http;

/**
 * Gateway cPanel UAPI Fileman — non enregistré en production par défaut (TASK 361).
 *
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/get_file_content/
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/save_file_content/
 */
final class CpanelFileUapiO2SwitchEnvironmentGateway implements O2SwitchEnvironmentGateway
{
    public const CPANEL_UAPI_FILEMAN_EXECUTE_PATH = '/execute/Fileman';

    public function configureEnvironment(
        ProvisioningContext $context,
        O2SwitchEnvironmentConfiguration $configuration,
        O2SwitchGestionEnvBuildResult $buildResult,
    ): InfrastructureAdapterResult {
        $token = config('provisioning.secrets.o2switch_api_token');
        $cpanelUser = config('provisioning.secrets.o2switch_cpanel_username');
        if (! is_string($token) || $token === '' || ! is_string($cpanelUser) || $cpanelUser === '') {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_environment_not_configured',
                'Identifiants cPanel UAPI absents.',
            );
        }

        [$dir, $file] = $this->splitEnvPath($buildResult->envFileAbsolutePath);
        if ($dir === null || $file === null) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_environment_invalid_path',
                'Chemin `.env` distant invalide pour Fileman.',
            );
        }

        $host = $configuration->cpanelHost;
        $baseUrl = 'https://'.$host.':2083'.self::CPANEL_UAPI_FILEMAN_EXECUTE_PATH;
        $authHeader = ['Authorization' => 'cpanel '.$cpanelUser.':'.$token];

        $readResponse = Http::withHeaders($authHeader)->get($baseUrl.'/get_file_content', [
            'dir' => $dir,
            'file' => $file,
        ]);

        if ($readResponse->status() === 401 || $readResponse->status() === 403) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_environment_access_denied',
                'Accès cPanel UAPI refusé pour lire le `.env` distant.',
            );
        }

        $desiredContent = $buildResult->builder->render();
        $desiredFingerprint = $buildResult->fingerprint();

        if ($readResponse->successful()) {
            $existingContent = $this->extractFileContent($readResponse->json());
            if ($existingContent !== null) {
                $existingFingerprint = $this->fingerprintExistingFile($existingContent);
                if ($existingFingerprint === $desiredFingerprint) {
                    return InfrastructureAdapterResult::succeeded(
                        outputSummary: [
                            'env_file_path' => $buildResult->envFileAbsolutePath,
                            'env_keys_applied' => $buildResult->builder->appliedKeyNames(),
                            'idempotent_replay' => true,
                        ],
                        metadata: [
                            'operation' => 'environment_configuration',
                            'installation_id' => $context->installationId,
                            'idempotent_replay' => true,
                            'configuration_fingerprint' => $desiredFingerprint,
                            ...$configuration->safePublicMetadata(),
                        ],
                    );
                }

                if ($this->detectSensitiveConflict($existingContent, $desiredContent)) {
                    return InfrastructureAdapterResult::manualInterventionRequired(
                        'o2switch_environment_sensitive_conflict',
                        'Le `.env` distant diffère sur des variables sensibles — intervention manuelle requise.',
                    );
                }
            }
        }

        $writeResponse = Http::withHeaders($authHeader)->asForm()->post($baseUrl.'/save_file_content', [
            'dir' => $dir,
            'file' => $file,
            'content' => $desiredContent,
        ]);

        if ($writeResponse->status() === 401 || $writeResponse->status() === 403) {
            return InfrastructureAdapterResult::manualInterventionRequired(
                'o2switch_environment_access_denied',
                'Accès cPanel UAPI refusé pour écrire le `.env` distant.',
            );
        }

        if ($writeResponse->successful()) {
            return InfrastructureAdapterResult::succeeded(
                outputSummary: [
                    'env_file_path' => $buildResult->envFileAbsolutePath,
                    'env_keys_applied' => $buildResult->builder->appliedKeyNames(),
                    'written' => true,
                ],
                metadata: [
                    'operation' => 'environment_configuration',
                    'installation_id' => $context->installationId,
                    'configuration_fingerprint' => $desiredFingerprint,
                    ...$configuration->safePublicMetadata(),
                ],
            );
        }

        return InfrastructureAdapterResult::failed(
            'o2switch_environment_write_failed',
            'Écriture du `.env` via cPanel UAPI en échec.',
            retryable: true,
            category: ProvisioningErrorCategory::Retryable,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitEnvPath(string $absolutePath): array
    {
        if (! str_starts_with($absolutePath, '/') || ! str_ends_with($absolutePath, '/.env')) {
            return [null, null];
        }

        $directory = dirname($absolutePath);
        $file = basename($absolutePath);

        return [$directory, $file];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractFileContent(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $data = $payload['result']['data'] ?? $payload['data'] ?? null;
        if (is_string($data)) {
            return $data;
        }

        if (is_array($data) && isset($data['content']) && is_string($data['content'])) {
            return $data['content'];
        }

        return null;
    }

    private function fingerprintExistingFile(string $content): string
    {
        $variables = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if ($line === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $normalizedKey = strtoupper(trim($key));
            if (! in_array($normalizedKey, O2SwitchGestionEnvSpecification::PROVISIONING_MANAGED_KEYS, true)) {
                continue;
            }

            $variables[$normalizedKey] = trim($value, " \t\"'");
        }

        return O2SwitchGestionEnvBuilder::fromVariables($variables)->nonSensitiveFingerprint();
    }

    private function detectSensitiveConflict(string $existingContent, string $desiredContent): bool
    {
        foreach (O2SwitchGestionEnvSpecification::SENSITIVE_VALUE_KEYS as $key) {
            $existingValue = $this->readKey($existingContent, $key);
            $desiredValue = $this->readKey($desiredContent, $key);

            if ($existingValue === null || $desiredValue === null) {
                continue;
            }

            if ($existingValue !== $desiredValue
                && $existingValue !== O2SwitchGestionEnvSpecification::APP_KEY_PENDING_SENTINEL
                && $existingValue !== O2SwitchGestionEnvSpecification::DB_PASSWORD_PENDING_SENTINEL) {
                return true;
            }
        }

        return false;
    }

    private function readKey(string $content, string $key): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (! str_starts_with($line, $key.'=')) {
                continue;
            }

            return trim(substr($line, strlen($key) + 1), " \t\"'");
        }

        return null;
    }
}
