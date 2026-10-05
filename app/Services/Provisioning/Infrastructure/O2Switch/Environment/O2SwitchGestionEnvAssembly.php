<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchGestionEnvAssembly
{
    /**
     * @return array{result: ?O2SwitchGestionEnvBuildResult, code: ?string, message: ?string}
     */
    public static function assemble(
        ProvisioningContext $context,
        O2SwitchEnvironmentConfiguration $configuration,
    ): array {
        $violations = O2SwitchGestionEnvSpecification::rejectForbiddenContextConfigurationKeys($context);
        if ($violations !== []) {
            return [
                'result' => null,
                'code' => 'o2switch_environment_forbidden_key',
                'message' => 'Clés de configuration provisioning non autorisées : '.implode(', ', $violations).'.',
            ];
        }

        $envPath = O2SwitchEnvironmentPathResolver::resolveEnvFilePath($context, $configuration);
        if ($envPath === null) {
            return [
                'result' => null,
                'code' => 'o2switch_environment_invalid_path',
                'message' => 'Chemin `.env` distant invalide.',
            ];
        }

        $appUrl = O2SwitchGestionAppUrlResolver::resolve($context, $configuration);
        if ($appUrl === null) {
            return [
                'result' => null,
                'code' => 'o2switch_environment_invalid_app_url',
                'message' => 'APP_URL indéterminée (domain ou subdomain + base domain requis).',
            ];
        }

        if ($context->databaseName === null || trim($context->databaseName) === '') {
            return [
                'result' => null,
                'code' => 'o2switch_environment_not_configured',
                'message' => 'Nom de base (database_name) absent du contexte installation.',
            ];
        }

        $dbHost = $context->databaseHost !== null && trim($context->databaseHost) !== ''
            ? trim($context->databaseHost)
            : 'localhost';

        $dbUsername = $context->externalReferences['database_username'] ?? null;
        if (! is_string($dbUsername) || trim($dbUsername) === '') {
            return [
                'result' => null,
                'code' => 'o2switch_environment_not_configured',
                'message' => 'Identifiant MySQL (externalReferences.database_username) requis.',
            ];
        }

        $appName = self::sanitizeAppName($context->installationName);

        $builder = O2SwitchGestionEnvBuilder::fromVariables([
            'APP_NAME' => $appName,
            'APP_ENV' => $configuration->applicationEnv,
            'APP_KEY' => O2SwitchGestionEnvSpecification::APP_KEY_PENDING_SENTINEL,
            'APP_DEBUG' => $configuration->applicationDebug ? 'true' : 'false',
            'APP_URL' => $appUrl,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $dbHost,
            'DB_PORT' => '3306',
            'DB_DATABASE' => trim($context->databaseName),
            'DB_USERNAME' => trim($dbUsername),
            'DB_PASSWORD' => O2SwitchGestionEnvSpecification::DB_PASSWORD_PENDING_SENTINEL,
            'LOG_LEVEL' => 'warning',
            'SESSION_DRIVER' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'FILESYSTEM_DISK' => 'local',
            'BROADCAST_CONNECTION' => 'log',
            'MAIL_MAILER' => 'log',
        ]);

        return [
            'result' => new O2SwitchGestionEnvBuildResult(
                builder: $builder,
                envFileAbsolutePath: $envPath,
                appUrl: $appUrl,
            ),
            'code' => null,
            'message' => null,
        ];
    }

    private static function sanitizeAppName(string $name): string
    {
        $trimmed = trim($name);

        return $trimmed !== '' ? $trimmed : 'MKD-Pro Gestion';
    }
}
