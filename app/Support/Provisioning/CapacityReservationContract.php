<?php

namespace App\Support\Provisioning;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;
use App\Support\Provisioning\ProvisioningSecretSanitizer;
use InvalidArgumentException;

/**
 * Contrat public de l'étape `reserve` — TASK 373.
 *
 * Aucun secret ; sorties persistées via ProvisioningRunStep::output_summary uniquement.
 */
final class CapacityReservationContract
{
    public const STEP_KEY = 'reserve';

    public const SCOPE_LOGICAL = 'logical';

    public const SCOPE_INFRASTRUCTURE = 'infrastructure';

    public const OUTPUT_RESERVATION_ID = 'reservation_id';

    public const OUTPUT_RESERVATION_SCOPE = 'reservation_scope';

    public const OUTPUT_DEPLOY_RELATIVE_PATH = 'deploy_relative_path';

    public const OUTPUT_INSTALLATION_ID = 'installation_id';

    public const OUTPUT_IDEMPOTENT_REPLAY = 'idempotent_replay';

    public const AUDIT_ACTION_STEP_PREFIX = 'provisioning.step_';

    /**
     * Chemin relatif par défaut consommé par {@see \App\Services\Provisioning\Infrastructure\O2Switch\Deploy\O2SwitchDeployPathResolver}.
     */
    public static function defaultDeployRelativePath(int $installationId): string
    {
        return 'mkd_gestion/installation_'.$installationId;
    }

    /**
     * Identifiant stable de réservation logique Control Center (une installation = un slot).
     */
    public static function logicalReservationId(int $installationId): string
    {
        return 'cc-logical-installation-'.$installationId;
    }

    /**
     * @return array<string, mixed>
     */
    public static function logicalSucceededOutputSummary(
        int $installationId,
        bool $idempotentReplay = false,
    ): array {
        $summary = [
            self::OUTPUT_RESERVATION_ID => self::logicalReservationId($installationId),
            self::OUTPUT_RESERVATION_SCOPE => self::SCOPE_LOGICAL,
            self::OUTPUT_INSTALLATION_ID => $installationId,
            self::OUTPUT_DEPLOY_RELATIVE_PATH => self::defaultDeployRelativePath($installationId),
        ];

        if ($idempotentReplay) {
            $summary[self::OUTPUT_IDEMPOTENT_REPLAY] = true;
        }

        return self::sanitizePublicOutputSummary($summary);
    }

    /**
     * @param  array<string, mixed>  $outputSummary
     * @return array<string, mixed>
     */
    public static function sanitizePublicOutputSummary(array $outputSummary): array
    {
        $sanitized = [];

        foreach ($outputSummary as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (ProvisioningContext::isForbiddenSecretKey($key)) {
                throw new InvalidArgumentException('Clé interdite dans le résumé de réservation : '.$key);
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizePublicOutputSummary($value);

                continue;
            }

            if (is_string($value) && ProvisioningSecretSanitizer::stringContainsSensitiveExposure($value)) {
                throw new InvalidArgumentException('Valeur interdite dans le résumé de réservation.');
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $outputSummary
     */
    public static function assertLogicalSucceededShape(array $outputSummary): void
    {
        foreach ([
            self::OUTPUT_RESERVATION_ID,
            self::OUTPUT_RESERVATION_SCOPE,
            self::OUTPUT_INSTALLATION_ID,
            self::OUTPUT_DEPLOY_RELATIVE_PATH,
        ] as $requiredKey) {
            if (! array_key_exists($requiredKey, $outputSummary)) {
                throw new InvalidArgumentException('Résumé de réservation logique incomplet : '.$requiredKey);
            }
        }

        if ($outputSummary[self::OUTPUT_RESERVATION_SCOPE] !== self::SCOPE_LOGICAL) {
            throw new InvalidArgumentException('reservation_scope inattendu pour une réservation logique.');
        }

        self::sanitizePublicOutputSummary($outputSummary);
    }

    public static function infrastructureAdapterUnavailable(
        int $installationId,
    ): InfrastructureAdapterResult {
        return InfrastructureAdapterResult::manualInterventionRequired(
            'capacity_reservation_unavailable',
            sprintf(
                'La réservation de capacité n\'a pas d\'implémentation opérationnelle (installation #%d).',
                $installationId,
            ),
            outputSummary: [
                'implementation_state' => 'contract_only',
                'contract' => 'capacity_reservation',
                self::OUTPUT_RESERVATION_SCOPE => self::SCOPE_INFRASTRUCTURE,
            ],
            metadata: [
                'implementation_state' => 'contract_only',
                'requires_manual_intervention' => true,
                'operation' => 'capacity_reservation',
            ],
        );
    }

}
