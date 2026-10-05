<?php

namespace App\Support;

use App\DTO\Provisioning\ProvisioningContext;
use App\Support\Provisioning\ProvisioningSecretSanitizer;
use App\Models\Client;
use App\Models\Installation;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProvisioningStepRegistry;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sérialisation lecture seule des runs/steps pour l'administration.
 * Les metadata brutes ne sont pas exposées (TASK 350).
 */
final class ProvisioningRunAdminPresentation
{
    /**
     * @return array<string, int>
     */
    public static function stepStatistics(Collection $steps): array
    {
        return [
            'total' => $steps->count(),
            'pending' => $steps->where('status', ProvisioningRunStep::STATUS_PENDING)->count(),
            'running' => $steps->where('status', ProvisioningRunStep::STATUS_RUNNING)->count(),
            'succeeded' => $steps->where('status', ProvisioningRunStep::STATUS_SUCCEEDED)->count(),
            'skipped' => $steps->where('status', ProvisioningRunStep::STATUS_SKIPPED)->count(),
            'failed' => $steps->where('status', ProvisioningRunStep::STATUS_FAILED)->count(),
            'manual_intervention_required' => $steps->where(
                'status',
                ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
            )->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(ProvisioningRun $run): array
    {
        $installation = $run->installation;
        $client = $installation?->client;

        return [
            'id' => $run->id,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'retry_of_run_id' => $run->retry_of_run_id,
            'current_step' => $run->current_step,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
            'target_version' => $run->target_version,
            'target_commit' => $run->target_commit,
            'pipeline_version' => $run->pipeline_version,
            'requested_at' => self::formatDateTimeValue($run->created_at),
            'started_at' => self::formatDateTimeValue($run->started_at),
            'finished_at' => self::formatDateTimeValue($run->finished_at),
            'created_at' => self::formatDateTimeValue($run->created_at),
            'updated_at' => self::formatDateTimeValue($run->updated_at),
            'installation' => $installation === null ? null : [
                'id' => $installation->id,
                'name' => $installation->name,
                'subdomain' => $installation->subdomain,
                'domain' => $installation->domain,
                'status' => $installation->status,
            ],
            'client' => $client === null ? null : [
                'id' => $client->id,
                'company_name' => $client->company_name,
                'contact_name' => $client->contact_name,
                'status' => $client->status,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array{
     *     can_execute: bool,
     *     unavailable_reason: string|null,
     *     button_label: string,
     *     store_url: string
     * }
     */
    public static function describeExecuteAvailability(
        ProvisioningRun $run,
        ProvisioningStepRegistry $registry,
    ): array {
        $storeUrl = route('provisioning-runs.execute', $run);

        $base = [
            'can_execute' => false,
            'unavailable_reason' => null,
            'button_label' => 'Exécuter le provisioning',
            'store_url' => $storeUrl,
        ];

        if ($registry->isEmpty()) {
            return [
                ...$base,
                'unavailable_reason' => 'Aucune étape exécutable n\'est enregistrée dans le moteur de provisioning.',
            ];
        }

        if ($run->status !== ProvisioningRun::STATUS_PENDING) {
            $reason = match ($run->status) {
                ProvisioningRun::STATUS_RUNNING => 'Un provisioning est déjà en cours sur ce run.',
                ProvisioningRun::STATUS_SUCCEEDED => 'Ce run est déjà terminé avec succès.',
                ProvisioningRun::STATUS_FAILED => 'Ce run est en échec ; créez une nouvelle demande avec retry.',
                ProvisioningRun::STATUS_MANUAL_INTERVENTION_REQUIRED => 'Ce run requiert une intervention manuelle ; créez une nouvelle demande si besoin.',
                ProvisioningRun::STATUS_CANCELLED => 'Ce run est annulé.',
                default => 'Seul un run en attente peut être exécuté.',
            };

            return [...$base, 'unavailable_reason' => $reason];
        }

        if ($run->finished_at !== null) {
            return [
                ...$base,
                'unavailable_reason' => 'Ce run est déjà finalisé (finished_at renseigné).',
            ];
        }

        $installation = $run->installation;
        if ($installation === null) {
            return [
                ...$base,
                'unavailable_reason' => 'Installation associée introuvable.',
            ];
        }

        $eligibilityReason = self::installationExecutionBlockReason($installation);
        if ($eligibilityReason !== null) {
            return [...$base, 'unavailable_reason' => $eligibilityReason];
        }

        try {
            ProvisioningContext::fromRun($run);
        } catch (InvalidArgumentException $exception) {
            return [...$base, 'unavailable_reason' => $exception->getMessage()];
        }

        return [
            ...$base,
            'can_execute' => true,
            'unavailable_reason' => null,
        ];
    }

    private static function installationExecutionBlockReason(Installation $installation): ?string
    {
        if ($installation->status === 'terminated' || $installation->terminated_at !== null) {
            return 'Impossible d\'exécuter le provisioning pour une installation terminée.';
        }

        if (trim((string) $installation->name) === '') {
            return 'Le nom de l\'installation est requis pour exécuter le provisioning.';
        }

        if (trim((string) $installation->subdomain) === '') {
            return 'Le sous-domaine de l\'installation est requis pour exécuter le provisioning.';
        }

        $connection = $installation->getConnectionName();

        if (! Client::on($connection)->whereKey($installation->client_id)->exists()) {
            return 'Le client associé à l\'installation est introuvable.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function step(ProvisioningRunStep $step): array
    {
        return [
            'id' => $step->id,
            'step_order' => (int) $step->step_order,
            'step_key' => $step->step_key,
            'status' => $step->status,
            'attempt' => (int) $step->attempt,
            'started_at' => self::formatDateTimeValue($step->started_at),
            'finished_at' => self::formatDateTimeValue($step->finished_at),
            'error_code' => $step->error_code,
            'message' => $step->error_message,
            'output_summary' => self::safePublicSummary($step->output_summary),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    public static function safePublicSummary(?array $summary): ?string
    {
        if ($summary === null || $summary === []) {
            return null;
        }

        $filtered = self::filterForbiddenKeys($summary);

        if ($filtered === []) {
            return null;
        }

        return json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private static function filterForbiddenKeys(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && ProvisioningContext::isForbiddenSecretKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $nested = self::filterForbiddenKeys($value);
                if ($nested !== []) {
                    $result[$key] = $nested;
                }

                continue;
            }

            if (is_string($value)) {
                $result[$key] = ProvisioningSecretSanitizer::redactStringForExposure($value);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function formatDateTimeValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return null;
    }
}
