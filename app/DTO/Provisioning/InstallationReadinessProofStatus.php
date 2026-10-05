<?php

namespace App\DTO\Provisioning;

/**
 * État interne d'une preuve — distinct des statuts ProvisioningRun (TASK 380).
 */
final class InstallationReadinessProofStatus
{
    public const VERIFIED = 'verified';

    public const NOT_VERIFIED = 'not_verified';

    public const NOT_CONFIGURED = 'not_configured';

    public const FAILED = 'failed';

    public const MANUAL_INTERVENTION_REQUIRED = 'manual_intervention_required';

    public const NOT_YET_AUTOMATED = 'not_yet_automated';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VERIFIED,
            self::NOT_VERIFIED,
            self::NOT_CONFIGURED,
            self::FAILED,
            self::MANUAL_INTERVENTION_REQUIRED,
            self::NOT_YET_AUTOMATED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function isSatisfiedForRequired(string $status): bool
    {
        return $status === self::VERIFIED;
    }

    public static function blocksRequiredReady(string $status): bool
    {
        return ! self::isSatisfiedForRequired($status);
    }
}
