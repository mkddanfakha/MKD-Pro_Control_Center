<?php

namespace App\Support;

use App\Support\Provisioning\ProvisioningSecretSanitizer;
use Throwable;

/**
 * Messages et codes d'erreur sûrs pour la persistance provisioning (sans secrets).
 */
final class ProvisioningExecutionDiagnostics
{
    private const MAX_MESSAGE_LENGTH = 2000;

    public static function safeErrorCode(Throwable $throwable): string
    {
        $normalized = str_replace('\\', '_', $throwable::class);

        return 'uncaught_'.$normalized;
    }

    public static function safeOperatorMessage(Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        $safe = ProvisioningSecretSanitizer::safeOperatorMessageFromThrowable(
            $message,
            'Erreur interne lors de l\'exécution de l\'étape.',
        );

        if (mb_strlen($safe) > self::MAX_MESSAGE_LENGTH) {
            return mb_substr($safe, 0, self::MAX_MESSAGE_LENGTH).'…';
        }

        return $safe;
    }
}
