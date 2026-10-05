<?php

namespace App\Services;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrateur du renouvellement automatique par crédit (Tasks 272–273).
 *
 * Désactivé par défaut. N'implémente pas de logique de consommation propre :
 * délègue à {@see SubscriptionService::consumeNextCreditForSubscription()} →
 * {@see SubscriptionService::performCreditConsumption()} (FIFO, un mois par appel).
 *
 * Politique métier (Task 273) :
 * - Échéance : consommation possible seulement si l'instant courant est **strictement après**
 *   {@see Subscription::$current_period_end}
 *   (à l'instant exact de fin de période, la période est encore considérée valide — cohérent avec InstallationAccessService).
 * - {@see Subscription::STATUS_GRACE_PERIOD} : consommation auto autorisée (Option 1) ; effet = même moteur que manuel
 *   ({@see Subscription::STATUS_ACTIVE}, {@see Subscription::$grace_period_ends_at} effacé).
 * - {@see Subscription::STATUS_SUSPENDED} : consommation auto autorisée lorsque crédit prépayé disponible (parité
 *   consommation manuelle) ; le scheduler lifecycle ne réactive jamais seul — seule cette commande / ce service le fait.
 * - {@see Subscription::STATUS_TERMINATED} : toujours interdit.
 * - Sans crédit : aucune consommation ; le lifecycle existant continue (grâce → suspendu).
 * - Retard : un appel = au plus un mois calendaire via {@see SubscriptionService::calculateNextSubscriptionPeriod()},
 *   jamais de rattrapage multi-mois en une exécution.
 */
class SubscriptionAutomaticCreditRenewalService
{
    /**
     * Consommation automatique pendant grace_period (période commerciale expirée, crédit disponible).
     */
    public const POLICY_ALLOW_AUTOMATIC_RENEWAL_DURING_GRACE_PERIOD = true;

    /**
     * Consommation automatique sur suspended (réactivation via le moteur de crédit, pas via sync lifecycle).
     */
    public const POLICY_ALLOW_AUTOMATIC_RENEWAL_WHILE_SUSPENDED = true;

    public const OUTCOME_DISABLED = 'disabled';

    public const OUTCOME_NOT_ELIGIBLE = 'not_eligible';

    public const OUTCOME_CONSUMED = 'consumed';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_WOULD_CONSUME = 'would_consume';

    public const REASON_TERMINATED = 'subscription_terminated';

    public const REASON_MISSING_PERIOD_END = 'missing_current_period_end';

    public const REASON_PERIOD_NOT_EXPIRED = 'period_not_expired';

    public const REASON_NO_CONSUMABLE_CREDIT = 'no_consumable_credit';

    public const REASON_STATUS_EXCLUDED_BY_POLICY = 'status_excluded_by_policy';

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('subscriptions.automatic_credit_renewal.enabled', false);
    }

    /**
     * @return array{eligible: bool, reason: ?string}
     */
    public function assessEligibility(Subscription $subscription, ?Carbon $now = null): array
    {
        $now ??= now();

        if ($subscription->isTerminated()) {
            return ['eligible' => false, 'reason' => self::REASON_TERMINATED];
        }

        if (! $this->subscriptionStatusAllowedByRenewalPolicy($subscription)) {
            return ['eligible' => false, 'reason' => self::REASON_STATUS_EXCLUDED_BY_POLICY];
        }

        if ($subscription->current_period_end === null) {
            return ['eligible' => false, 'reason' => self::REASON_MISSING_PERIOD_END];
        }

        $periodEnd = Carbon::parse($subscription->current_period_end);

        if ($now->lessThanOrEqualTo($periodEnd)) {
            return ['eligible' => false, 'reason' => self::REASON_PERIOD_NOT_EXPIRED];
        }

        $summary = $this->subscriptionService->summarizeSubscriptionCreditForDisplay($subscription);

        if ((int) ($summary['available_months'] ?? 0) <= 0) {
            return ['eligible' => false, 'reason' => self::REASON_NO_CONSUMABLE_CREDIT];
        }

        return ['eligible' => true, 'reason' => null];
    }

    /**
     * Filtre explicite de statut (Task 273). Terminated est traité avant cet appel.
     */
    public function subscriptionStatusAllowedByRenewalPolicy(Subscription $subscription): bool
    {
        if ($subscription->status === Subscription::STATUS_GRACE_PERIOD) {
            return self::POLICY_ALLOW_AUTOMATIC_RENEWAL_DURING_GRACE_PERIOD;
        }

        if ($subscription->status === Subscription::STATUS_SUSPENDED) {
            return self::POLICY_ALLOW_AUTOMATIC_RENEWAL_WHILE_SUSPENDED;
        }

        return true;
    }

    /**
     * Périmètre CLI/cron : abonnements dont la période commerciale est expirée et évaluables via
     * {@see assessEligibility()} (sans pré-filtrer le crédit — refus géré par le service).
     *
     * @return Builder<Subscription>
     */
    public function querySubscriptionsForRenewalEvaluation(?Carbon $now = null): Builder
    {
        $now ??= now();

        $query = Subscription::query()
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', $now)
            ->where('status', '!=', Subscription::STATUS_TERMINATED);

        if (! self::POLICY_ALLOW_AUTOMATIC_RENEWAL_DURING_GRACE_PERIOD) {
            $query->where('status', '!=', Subscription::STATUS_GRACE_PERIOD);
        }

        if (! self::POLICY_ALLOW_AUTOMATIC_RENEWAL_WHILE_SUSPENDED) {
            $query->where('status', '!=', Subscription::STATUS_SUSPENDED);
        }

        return $query->orderBy('id');
    }

    /**
     * Simule un renouvellement automatique sans écriture (Task 277 — dry-run).
     *
     * @return array{
     *     outcome: string,
     *     reason: ?string,
     *     payment_id: ?int,
     *     remaining_months_before: ?int,
     *     simulated_period_start: ?string,
     *     simulated_period_end: ?string,
     *     simulated_status: ?string
     * }
     */
    public function simulateAutomaticRenewal(Subscription $subscription, ?Carbon $now = null): array
    {
        $assessment = $this->assessEligibility($subscription, $now);

        if (! $assessment['eligible']) {
            return [
                'outcome' => self::OUTCOME_NOT_ELIGIBLE,
                'reason' => $assessment['reason'],
                'payment_id' => null,
                'remaining_months_before' => null,
                'simulated_period_start' => null,
                'simulated_period_end' => null,
                'simulated_status' => null,
            ];
        }

        $preview = $this->subscriptionService->previewNextFifoCreditConsumption($subscription);

        if ($preview === null) {
            return [
                'outcome' => self::OUTCOME_NOT_ELIGIBLE,
                'reason' => self::REASON_NO_CONSUMABLE_CREDIT,
                'payment_id' => null,
                'remaining_months_before' => null,
                'simulated_period_start' => null,
                'simulated_period_end' => null,
                'simulated_status' => null,
            ];
        }

        return [
            'outcome' => self::OUTCOME_WOULD_CONSUME,
            'reason' => null,
            'payment_id' => $preview['payment_id'],
            'remaining_months_before' => $preview['remaining_months_before'],
            'simulated_period_start' => $preview['period_start'],
            'simulated_period_end' => $preview['period_end'],
            'simulated_status' => Subscription::STATUS_ACTIVE,
        ];
    }

    /**
     * Tente une consommation FIFO automatique lorsque la fonctionnalité est activée.
     *
     *
     * @return array{
     *     outcome: string,
     *     reason: ?string,
     *     consumption_id: ?int,
     *     payment_id: ?int
     * }
     */
    public function attemptAutomaticRenewal(Subscription $subscription, ?Carbon $now = null): array
    {
        if (! $this->isEnabled()) {
            return [
                'outcome' => self::OUTCOME_DISABLED,
                'reason' => 'feature_disabled',
                'consumption_id' => null,
                'payment_id' => null,
            ];
        }

        $assessment = $this->assessEligibility($subscription, $now);

        if (! $assessment['eligible']) {
            return [
                'outcome' => self::OUTCOME_NOT_ELIGIBLE,
                'reason' => $assessment['reason'],
                'consumption_id' => null,
                'payment_id' => null,
            ];
        }

        $subscriptionBefore = $subscription->fresh() ?? $subscription;

        try {
            $consumption = DB::transaction(function () use ($subscription) {
                return $this->subscriptionService->consumeNextCreditForSubscription($subscription);
            });
        } catch (SubscriptionRenewalException) {
            return [
                'outcome' => self::OUTCOME_FAILED,
                'reason' => 'consumption_rejected',
                'consumption_id' => null,
                'payment_id' => null,
            ];
        }

        $consumption = $consumption->fresh(['payment']);
        $subscriptionAfter = $subscription->fresh();

        if ($this->shouldRecordAutomaticRenewalAudit()) {
            $this->recordAutomaticConsumptionAudit($subscriptionBefore, $subscriptionAfter, $consumption);
        }

        return [
            'outcome' => self::OUTCOME_CONSUMED,
            'reason' => null,
            'consumption_id' => $consumption->id,
            'payment_id' => $consumption->payment_id,
        ];
    }

    private function shouldRecordAutomaticRenewalAudit(): bool
    {
        return (bool) config('subscriptions.automatic_credit_renewal.record_audit', true);
    }

    private function recordAutomaticConsumptionAudit(
        Subscription $subscriptionBefore,
        ?Subscription $subscriptionAfter,
        SubscriptionPaymentConsumption $consumption,
    ): void {
        $this->auditLogService->record(
            'subscription.credit_consumed',
            auditable: $subscriptionAfter ?? $subscriptionBefore,
            oldValues: [
                'subscription' => $this->subscriptionAuditSnapshot($subscriptionBefore),
                'renewal_trigger' => 'automatic_credit_renewal',
            ],
            newValues: [
                'subscription' => $subscriptionAfter !== null
                    ? $this->subscriptionAuditSnapshot($subscriptionAfter)
                    : null,
                'consumption' => [
                    'id' => $consumption->id,
                    'payment_id' => $consumption->payment_id,
                    'period_start' => $consumption->period_start?->format('Y-m-d H:i:s'),
                    'period_end' => $consumption->period_end?->format('Y-m-d H:i:s'),
                ],
                'renewal_trigger' => 'automatic_credit_renewal',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionAuditSnapshot(Subscription $subscription): array
    {
        $snapshot = ['id' => $subscription->id];

        foreach ([
            'status',
            'current_period_start',
            'current_period_end',
            'grace_period_ends_at',
            'suspended_at',
        ] as $attribute) {
            $value = $subscription->getAttribute($attribute);

            if ($value instanceof \DateTimeInterface) {
                $snapshot[$attribute] = $value->format('Y-m-d H:i:s');
            } else {
                $snapshot[$attribute] = $value;
            }
        }

        return $snapshot;
    }
}
