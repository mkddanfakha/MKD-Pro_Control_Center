<?php

namespace App\DTO\Provisioning;

/**
 * Niveau d'obligation d'une preuve de readiness (TASK 379 / 380).
 */
final class InstallationReadinessProofLevel
{
    public const REQUIRED = 'required';

    public const RECOMMENDED = 'recommended';

    public const OPTIONAL = 'optional';

    public const OUTSIDE_PROVISIONING = 'outside_provisioning';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::REQUIRED,
            self::RECOMMENDED,
            self::OPTIONAL,
            self::OUTSIDE_PROVISIONING,
        ];
    }

    public static function isValid(string $level): bool
    {
        return in_array($level, self::all(), true);
    }

    public static function preventsReadyWhenUnmet(string $level): bool
    {
        return $level === self::REQUIRED;
    }
}
