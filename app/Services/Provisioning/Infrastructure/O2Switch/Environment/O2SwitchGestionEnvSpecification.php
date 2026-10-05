<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

use App\DTO\Provisioning\ProvisioningContext;

/**
 * Variables `.env` Gestion autorisées au provisioning (d'après `.env.example` Gestion — TASK 361).
 */
final class O2SwitchGestionEnvSpecification
{
    /** @var list<string> */
    public const PROVISIONING_MANAGED_KEYS = [
        'APP_NAME',
        'APP_ENV',
        'APP_KEY',
        'APP_DEBUG',
        'APP_URL',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'LOG_LEVEL',
        'SESSION_DRIVER',
        'QUEUE_CONNECTION',
        'CACHE_STORE',
        'FILESYSTEM_DISK',
        'BROADCAST_CONNECTION',
        'MAIL_MAILER',
    ];

    /** @var list<string> */
    public const SENSITIVE_VALUE_KEYS = [
        'APP_KEY',
        'DB_PASSWORD',
    ];

    public const APP_KEY_PENDING_SENTINEL = 'base64:PROVISIONING_APP_KEY_PENDING';

    public const DB_PASSWORD_PENDING_SENTINEL = 'PROVISIONING_DB_PASSWORD_PENDING';

    /**
     * @return list<string>
     */
    public static function rejectForbiddenContextConfigurationKeys(ProvisioningContext $context): array
    {
        $violations = [];

        foreach ($context->configuration as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized = strtoupper(trim($key));

            if (ProvisioningContext::isForbiddenSecretKey($key)) {
                $violations[] = $normalized;

                continue;
            }

            if (! in_array($normalized, self::PROVISIONING_MANAGED_KEYS, true)) {
                $violations[] = $normalized;
            }
        }

        return array_values(array_unique($violations));
    }
}
