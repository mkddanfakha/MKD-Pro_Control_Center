<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Models\Subscription;
use Illuminate\Support\Collection;

class AuditLogAdminPresentation
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'secret',
        'credential',
        'database_host',
        'database_name',
        'remember_token',
        'api_key',
        'authorization',
        'env_',
    ];

    /**
     * @param  array<string, mixed>  $subjectContext
     * @return array<string, mixed>
     */
    public static function serializeEntry(AuditLog $log, array $subjectContext): array
    {
        $oldValues = self::sanitizeValues($log->old_values);
        $newValues = self::sanitizeValues($log->new_values);

        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'action' => (string) $log->action,
            'auditable_type' => $log->auditable_type,
            'auditable_type_label' => self::auditableTypeLabel($log->auditable_type),
            'auditable_id' => $log->auditable_id !== null ? (int) $log->auditable_id : null,
            'user' => $log->user !== null
                ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ]
                : null,
            'result' => $log->result,
            'context_summary' => self::buildContextSummary($log->action, $oldValues, $newValues),
            'subject' => self::buildSubjectPresentation($log, $subjectContext),
            'detail' => [
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'error_message' => $log->error_message,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ],
        ];
    }

    public static function auditableTypeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '—';
        }

        if (str_contains($type, '\\')) {
            $parts = explode('\\', $type);

            return (string) end($parts);
        }

        return $type;
    }

    public static function resolveAuditableTypeFilter(string $input): string
    {
        if (str_contains($input, '\\')) {
            return $input;
        }

        $candidate = 'App\\Models\\'.$input;

        if (class_exists($candidate)) {
            return $candidate;
        }

        return $input;
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, mixed>
     */
    public static function buildSubjectContext(Collection $logs): array
    {
        $idsByType = [];

        foreach ($logs as $log) {
            if ($log->auditable_type === null || $log->auditable_id === null) {
                continue;
            }

            $idsByType[$log->auditable_type][] = (int) $log->auditable_id;
        }

        $subscriptionMorph = (new Subscription)->getMorphClass();
        $paymentMorph = (new Payment)->getMorphClass();
        $installationMorph = (new Installation)->getMorphClass();
        $clientMorph = (new Client)->getMorphClass();
        $provisioningRunMorph = (new ProvisioningRun)->getMorphClass();
        $provisioningRunStepMorph = (new ProvisioningRunStep)->getMorphClass();

        $subscriptionIds = self::uniqueIds($idsByType[$subscriptionMorph] ?? []);
        $paymentIds = self::uniqueIds($idsByType[$paymentMorph] ?? []);
        $installationIds = self::uniqueIds($idsByType[$installationMorph] ?? []);
        $clientIds = self::uniqueIds($idsByType[$clientMorph] ?? []);
        $provisioningRunIds = self::uniqueIds($idsByType[$provisioningRunMorph] ?? []);
        $provisioningRunStepIds = self::uniqueIds($idsByType[$provisioningRunStepMorph] ?? []);

        $existingSubscriptions = $subscriptionIds === []
            ? []
            : Subscription::query()->whereIn('id', $subscriptionIds)->pluck('id')->all();

        $paymentsById = $paymentIds === []
            ? collect()
            : Payment::query()->whereIn('id', $paymentIds)->get(['id', 'subscription_id'])->keyBy('id');

        $existingInstallations = $installationIds === []
            ? []
            : Installation::query()->whereIn('id', $installationIds)->pluck('id')->all();

        $existingClients = $clientIds === []
            ? []
            : Client::query()->whereIn('id', $clientIds)->pluck('id')->all();

        $stepsById = $provisioningRunStepIds === []
            ? collect()
            : ProvisioningRunStep::query()
                ->whereIn('id', $provisioningRunStepIds)
                ->get(['id', 'provisioning_run_id', 'step_key'])
                ->keyBy('id');

        $provisioningRunIdsForLookup = self::uniqueIds(array_merge(
            $provisioningRunIds,
            $stepsById->pluck('provisioning_run_id')->map(fn ($id) => (int) $id)->all(),
        ));

        $existingProvisioningRuns = $provisioningRunIdsForLookup === []
            ? []
            : ProvisioningRun::query()->whereIn('id', $provisioningRunIdsForLookup)->pluck('id')->all();

        return [
            'subscription_morph' => $subscriptionMorph,
            'payment_morph' => $paymentMorph,
            'installation_morph' => $installationMorph,
            'client_morph' => $clientMorph,
            'provisioning_run_morph' => $provisioningRunMorph,
            'provisioning_run_step_morph' => $provisioningRunStepMorph,
            'existing_subscriptions' => array_flip($existingSubscriptions),
            'payments' => $paymentsById,
            'existing_installations' => array_flip($existingInstallations),
            'existing_clients' => array_flip($existingClients),
            'existing_provisioning_runs' => array_flip($existingProvisioningRuns),
            'provisioning_run_steps' => $stepsById,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{url: ?string, label: string, available: bool}
     */
    private static function buildSubjectPresentation(AuditLog $log, array $context): array
    {
        if ($log->auditable_type === null || $log->auditable_id === null) {
            return [
                'url' => null,
                'label' => '—',
                'available' => false,
            ];
        }

        $id = (int) $log->auditable_id;
        $type = $log->auditable_type;

        if ($type === $context['subscription_morph']) {
            if (isset($context['existing_subscriptions'][$id])) {
                return [
                    'url' => route('subscriptions.show', $id),
                    'label' => 'Abonnement #'.$id,
                    'available' => true,
                ];
            }

            return self::unavailableSubject('Abonnement #'.$id);
        }

        if ($type === $context['payment_morph']) {
            /** @var \App\Models\Payment|null $payment */
            $payment = $context['payments']->get($id);

            if ($payment !== null) {
                $subscriptionId = $payment->subscription_id;

                return [
                    'url' => route('payments.index', ['subscription_id' => $subscriptionId]),
                    'label' => 'Paiement #'.$id,
                    'available' => true,
                ];
            }

            return self::unavailableSubject('Paiement #'.$id);
        }

        if ($type === $context['installation_morph']) {
            if (isset($context['existing_installations'][$id])) {
                return [
                    'url' => route('installations.show', $id),
                    'label' => 'Installation #'.$id,
                    'available' => true,
                ];
            }

            return self::unavailableSubject('Installation #'.$id);
        }

        if ($type === $context['client_morph']) {
            if (isset($context['existing_clients'][$id])) {
                return [
                    'url' => route('clients.show', $id),
                    'label' => 'Client #'.$id,
                    'available' => true,
                ];
            }

            return self::unavailableSubject('Client #'.$id);
        }

        if ($type === $context['provisioning_run_morph']) {
            if (isset($context['existing_provisioning_runs'][$id])) {
                return [
                    'url' => route('provisioning-runs.show', $id),
                    'label' => 'Run provisioning #'.$id,
                    'available' => true,
                ];
            }

            return self::unavailableSubject('Run provisioning #'.$id);
        }

        if ($type === $context['provisioning_run_step_morph']) {
            /** @var \App\Models\ProvisioningRunStep|null $step */
            $step = $context['provisioning_run_steps']->get($id);

            if ($step !== null && isset($context['existing_provisioning_runs'][$step->provisioning_run_id])) {
                $label = 'Étape '.$step->step_key.' (run #'.$step->provisioning_run_id.')';

                return [
                    'url' => route('provisioning-runs.show', $step->provisioning_run_id),
                    'label' => $label,
                    'available' => true,
                ];
            }

            return self::unavailableSubject('Étape provisioning #'.$id);
        }

        $label = self::auditableTypeLabel($type).' #'.$id;

        return [
            'url' => null,
            'label' => $label,
            'available' => false,
        ];
    }

    /**
     * @return array{url: null, label: string, available: false}
     */
    private static function unavailableSubject(string $reference): array
    {
        return [
            'url' => null,
            'label' => 'Objet indisponible ('.$reference.')',
            'available' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private static function buildContextSummary(string $action, ?array $oldValues, ?array $newValues): string
    {
        $newValues ??= [];
        $oldValues ??= [];

        if (str_starts_with($action, 'payment.')) {
            $payment = is_array($newValues['payment'] ?? null) ? $newValues['payment'] : $newValues;
            $amount = $payment['amount'] ?? null;
            $currency = $payment['currency'] ?? null;
            $status = $payment['status'] ?? null;

            $parts = array_filter([
                $amount !== null ? 'Montant '.$amount : null,
                $currency !== null ? strtoupper((string) $currency) : null,
                $status !== null ? 'Statut '.$status : null,
            ]);

            if ($parts !== []) {
                return implode(' · ', $parts);
            }
        }

        if ($action === 'subscription.credit_consumed') {
            $consumption = is_array($newValues['consumption'] ?? null) ? $newValues['consumption'] : [];
            $start = $consumption['period_start'] ?? null;
            $end = $consumption['period_end'] ?? null;

            if ($start !== null && $end !== null) {
                return 'Période consommée du '.$start.' au '.$end;
            }
        }

        if ($action === 'subscription.lifecycle_synced') {
            $subscription = is_array($newValues['subscription'] ?? null) ? $newValues['subscription'] : $newValues;
            $status = $subscription['status'] ?? null;

            if ($status !== null) {
                return 'Statut abonnement synchronisé : '.$status;
            }
        }

        if (str_starts_with($action, 'subscription.')) {
            $subscription = is_array($newValues['subscription'] ?? null) ? $newValues['subscription'] : $newValues;
            $status = $subscription['status'] ?? null;

            if ($status !== null) {
                return 'Abonnement · statut '.$status;
            }
        }

        if (str_starts_with($action, 'provisioning.')) {
            $parts = array_filter([
                isset($newValues['provisioning_run_id']) ? 'Run #'.$newValues['provisioning_run_id'] : null,
                isset($newValues['installation_id']) ? 'Installation #'.$newValues['installation_id'] : null,
                isset($newValues['step_key']) ? 'Étape '.$newValues['step_key'] : null,
                isset($newValues['status']) ? 'Statut '.$newValues['status'] : null,
                isset($newValues['error_code']) ? 'Code '.$newValues['error_code'] : null,
            ]);

            if ($parts !== []) {
                return implode(' · ', $parts);
            }
        }

        if (isset($newValues['name']) && is_string($newValues['name'])) {
            return 'Nom : '.$newValues['name'];
        }

        if (isset($oldValues['name'], $newValues['name'])) {
            return 'Nom : '.$oldValues['name'].' → '.$newValues['name'];
        }

        $keys = array_keys($newValues);

        if ($keys !== []) {
            return 'Champs modifiés : '.implode(', ', array_slice($keys, 0, 5));
        }

        return '—';
    }

    /**
     * @param  mixed  $values
     * @return array<string, mixed>|null
     */
    public static function sanitizeValues(mixed $values): ?array
    {
        if ($values === null) {
            return null;
        }

        if (! is_array($values)) {
            return null;
        }

        return self::sanitizeArray($values);
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    private static function sanitizeArray(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $sanitized[$key] = '[masqué]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function uniqueIds(array $ids): array
    {
        return array_values(array_unique($ids));
    }
}
