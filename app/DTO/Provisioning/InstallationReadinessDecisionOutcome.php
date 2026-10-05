<?php

namespace App\DTO\Provisioning;

/**
 * Résultat de décision readiness — interne, non persisté comme statut de run (TASK 380).
 */
final class InstallationReadinessDecisionOutcome
{
    public const READY = 'ready';

    public const NOT_READY = 'not_ready';

    public const MANUAL_INTERVENTION_REQUIRED = 'manual_intervention_required';

    public const FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::READY,
            self::NOT_READY,
            self::MANUAL_INTERVENTION_REQUIRED,
            self::FAILED,
        ];
    }

    public static function isValid(string $outcome): bool
    {
        return in_array($outcome, self::all(), true);
    }
}
