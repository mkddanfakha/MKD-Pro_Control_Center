<?php

namespace App\Support\Provisioning;

use App\DTO\Provisioning\ProvisioningContext;
use InvalidArgumentException;

/**
 * Filtrage central des secrets provisioning (persistances, audit, messages) — TASK 374.
 */
final class ProvisioningSecretSanitizer
{
    /**
     * @var list<string>
     */
    private const EXACT_FORBIDDEN_KEYS = [
        'app_key',
        'db_password',
        'aws_secret_access_key',
    ];

    /**
     * @var list<string>
     */
    private const SENSITIVE_STRING_FRAGMENTS = [
        'password=',
        'password:',
        'db_password=',
        'app_key=',
        'aws_secret_access_key=',
        'token=',
        'api_key',
        'secret=',
        'credential',
        'authorization:',
        'bearer ',
    ];

    public static function assertSafeAdapterOperatorMessage(string $message): void
    {
        if (self::stringContainsSensitiveExposure($message)) {
            throw new InvalidArgumentException('Message adaptateur : contenu sensible interdit.');
        }
    }

    public static function safeOperatorMessageFromThrowable(string $message, string $emptyFallback): string
    {
        $trimmed = trim($message);

        if ($trimmed === '') {
            return $emptyFallback;
        }

        if (self::stringContainsSensitiveExposure($trimmed)) {
            return 'Erreur interne lors de l\'exécution de l\'étape (détails masqués pour sécurité).';
        }

        foreach (preg_split('/\s+/', $trimmed) ?: [] as $token) {
            if (str_contains($token, '=')) {
                [$key] = explode('=', $token, 2);
                if (ProvisioningContext::isForbiddenSecretKey($key)) {
                    return 'Erreur interne lors de l\'exécution de l\'étape (détails masqués pour sécurité).';
                }
            }
        }

        return $trimmed;
    }

    public static function stringContainsSensitiveExposure(string $value): bool
    {
        $lower = strtolower($value);

        foreach (self::SENSITIVE_STRING_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        if (self::containsUrlEmbeddedCredentials($value)) {
            return true;
        }

        if (preg_match('/authorization\s*:\s*cpanel\s+\S+:\S+/i', $value) === 1) {
            return true;
        }

        return false;
    }

    public static function redactStringForExposure(string $value): string
    {
        if (! self::stringContainsSensitiveExposure($value)) {
            return self::redactUrlEmbeddedCredentials($value);
        }

        return '[filtré]';
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    public static function sanitizeArrayForExposure(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isForbiddenKey($key)) {
                $sanitized[$key] = '[filtré]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArrayForExposure($value);

                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = self::redactStringForExposure($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private static function isForbiddenKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::EXACT_FORBIDDEN_KEYS, true)) {
            return true;
        }

        return ProvisioningContext::isForbiddenSecretKey($key);
    }

    private static function containsUrlEmbeddedCredentials(string $value): bool
    {
        return preg_match('#https?://[^\s/:@]+:[^\s/@]+@#i', $value) === 1;
    }

    private static function redactUrlEmbeddedCredentials(string $value): string
    {
        $redacted = preg_replace(
            '#(https?://)([^\s/:@]+):([^\s/@]+)(@)#i',
            '$1[filtré]:[filtré]$4',
            $value,
        );

        return is_string($redacted) ? $redacted : $value;
    }
}
