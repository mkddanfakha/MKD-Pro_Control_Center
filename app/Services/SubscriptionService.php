<?php

namespace App\Services;

use App\Exceptions\Subscription\SubscriptionLifecycleException;
use App\Exceptions\Subscription\SubscriptionPeriodException;
use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    /**
     * Durée de la période de grâce après expiration de la période courante (en jours calendaires).
     */
    public const GRACE_PERIOD_DAYS = 7;

    /**
     * Synchronise le statut de l'abonnement avec la date courante (cycle de vie).
     */
    public function syncLifecycle(Subscription $subscription, ?Carbon $now = null): Subscription
    {
        $now ??= now();

        return DB::transaction(function () use ($subscription, $now) {
            /** @var Subscription $lockedSubscription */
            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->applyLifecycleSync($lockedSubscription, $now);

            if ($lockedSubscription->isDirty()) {
                $lockedSubscription->save();
            }

            return $lockedSubscription->fresh();
        });
    }

    /**
     * Initialise la première période mensuelle lorsque les dates de période ne sont pas encore définies.
     */
    public function createInitialPeriod(Subscription $subscription): Subscription
    {
        if ($subscription->current_period_start !== null || $subscription->current_period_end !== null) {
            throw new SubscriptionPeriodException('Les dates de période sont déjà définies pour cet abonnement.');
        }

        if ($subscription->isTerminated()) {
            throw new SubscriptionPeriodException('Impossible d\'initialiser une période pour un abonnement terminé.');
        }

        if ($subscription->starts_at === null) {
            throw new SubscriptionPeriodException('La date de début commerciale (starts_at) est requise pour initialiser la période.');
        }

        $anchor = Carbon::parse($subscription->starts_at);

        $period = $this->calculateNextPeriod($subscription, $anchor);

        $subscription->current_period_start = $period['start'];
        $subscription->current_period_end = $period['end'];
        $subscription->save();

        return $subscription->fresh();
    }

    /**
     * Calcule une période mensuelle calendaire à partir d'une date de début.
     *
     * La fin de période est le dernier instant avant le même jour d'ancrage le mois suivant
     * (23:59:59). Si le jour d'ancrage n'existe pas le mois suivant (ex. 31 janvier),
     * la période se termine à la fin du dernier jour de ce mois.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function calculateNextPeriod(Subscription $subscription, Carbon $periodStart): array
    {
        $start = $periodStart->copy()->startOfDay();

        if (! $start->isValid()) {
            throw new SubscriptionPeriodException('La date de début de période est invalide.');
        }

        $nextAnchor = $start->copy()->addMonthNoOverflow();

        if ($nextAnchor->day === $start->day) {
            $end = $nextAnchor->copy()->subSecond();
        } else {
            $end = $nextAnchor->copy()->endOfDay();
        }

        if ($end->lessThan($start)) {
            throw new SubscriptionPeriodException('La période calculée est invalide.');
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Calcule les champs de crédit d'un paiement à partir du montant et de l'abonnement.
     *
     * @return array{monthly_unit_amount: int, credit_months_purchased: int}
     *
     * @throws SubscriptionRenewalException
     */
    public function calculatePaymentCreditFields(Subscription $subscription, int $amount, string $currency): array
    {
        $monthlyUnitAmount = (int) $subscription->amount;

        if ($monthlyUnitAmount <= 0) {
            throw new SubscriptionRenewalException('Le tarif mensuel de l\'abonnement est invalide.');
        }

        if ((string) $currency !== (string) $subscription->currency) {
            throw new SubscriptionRenewalException('La devise du paiement ne correspond pas à la devise de l\'abonnement.');
        }

        if ($amount % $monthlyUnitAmount !== 0) {
            throw new SubscriptionRenewalException('Le montant du paiement doit être un multiple entier du tarif mensuel.');
        }

        $creditMonthsPurchased = (int) ($amount / $monthlyUnitAmount);

        if ($creditMonthsPurchased < 1) {
            throw new SubscriptionRenewalException('Le paiement doit financer au moins un mois de crédit.');
        }

        return [
            'monthly_unit_amount' => $monthlyUnitAmount,
            'credit_months_purchased' => $creditMonthsPurchased,
        ];
    }

    /**
     * Renseigne et persiste monthly_unit_amount et credit_months_purchased sur un paiement.
     *
     * @throws SubscriptionRenewalException
     */
    public function applyPaymentCreditFields(Payment $payment, ?Subscription $subscription = null): Payment
    {
        $subscription ??= $this->resolveSubscriptionForPayment($payment);

        $creditFields = $this->calculatePaymentCreditFields(
            $subscription,
            (int) $payment->amount,
            (string) $payment->currency,
        );

        $payment->fill($creditFields);
        $payment->save();

        return $payment->fresh();
    }

    /**
     * Indique si un paiement payé peut consommer un mois de crédit.
     */
    public function canConsumeCreditFromPayment(Payment $payment): bool
    {
        try {
            $this->assertPaymentEligibleForCreditConsumption($payment);

            return true;
        } catch (SubscriptionRenewalException) {
            return false;
        }
    }

    /**
     * Compatibilité : alias historique basé sur le crédit restant.
     */
    public function canRenewFromPayment(Payment $payment): bool
    {
        return $this->canConsumeCreditFromPayment($payment);
    }

    /**
     * Prévisualise la période suivante après consommation d'un mois de crédit (sans persister).
     *
     * @return array{current_period_start: string, current_period_end: string, next_period_start: string, next_period_end: string}
     */
    public function previewRenewalFromPayment(Payment $payment): array
    {
        $subscription = $this->resolveSubscriptionForPayment($payment);
        $this->assertPaymentEligibleForCreditConsumption($payment, $subscription);

        $nextPeriodStart = Carbon::parse($subscription->current_period_end)->addSecond()->startOfDay();
        $nextPeriod = $this->calculateNextPeriod($subscription, $nextPeriodStart);

        return [
            'current_period_start' => Carbon::parse($subscription->current_period_start)->toIso8601String(),
            'current_period_end' => Carbon::parse($subscription->current_period_end)->toIso8601String(),
            'next_period_start' => $nextPeriod['start']->toIso8601String(),
            'next_period_end' => $nextPeriod['end']->toIso8601String(),
        ];
    }

    /**
     * Résumé lecture seule du crédit d'un abonnement (affichage Inertia, sans consommation).
     *
     * @return array{
     *     available_months: int,
     *     payment_count: int,
     *     payments: list<array{
     *         id: int,
     *         amount: int,
     *         currency: string,
     *         paid_at: string|null,
     *         monthly_unit_amount: int|null,
     *         credit_months_purchased: int|null,
     *         credit_months_remaining: int,
     *         consumptions_count: int,
     *         credit_exhausted_at: string|null,
     *         status: string,
     *         is_refunded: bool
     *     }>
     * }
     */
    public function summarizeSubscriptionCreditForDisplay(Subscription $subscription): array
    {
        $payments = Payment::query()
            ->where('subscription_id', $subscription->id)
            ->withCount('consumptions')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        $paymentRows = [];
        $availableMonths = 0;

        foreach ($payments as $payment) {
            $remaining = $this->remainingCreditMonthsForPayment($payment);
            $consumptionsCount = (int) $payment->consumptions_count;

            if ($this->paymentContributesToConsumableCredit($subscription, $payment, $remaining)) {
                $availableMonths += $remaining;
            }

            $paymentRows[] = [
                'id' => $payment->id,
                'amount' => (int) $payment->amount,
                'currency' => (string) $payment->currency,
                'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
                'monthly_unit_amount' => $payment->monthly_unit_amount !== null ? (int) $payment->monthly_unit_amount : null,
                'credit_months_purchased' => $payment->credit_months_purchased !== null ? (int) $payment->credit_months_purchased : null,
                'credit_months_remaining' => $remaining,
                'consumptions_count' => $consumptionsCount,
                'credit_exhausted_at' => $payment->credit_exhausted_at?->format('Y-m-d H:i:s'),
                'status' => (string) $payment->status,
                'is_refunded' => $payment->isRefunded(),
            ];
        }

        return [
            'available_months' => $availableMonths,
            'payment_count' => $payments->count(),
            'payments' => $paymentRows,
        ];
    }

    /**
     * Consomme le prochain mois de crédit disponible pour l'abonnement (FIFO : paid_at, puis id).
     *
     * @throws SubscriptionRenewalException
     */
    public function consumeNextCreditForSubscription(Subscription $subscription): SubscriptionPaymentConsumption
    {
        return DB::transaction(function () use ($subscription) {
            /** @var Subscription $lockedSubscription */
            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSubscription->isTerminated()) {
                throw new SubscriptionRenewalException('Impossible de renouveler un abonnement terminé.');
            }

            $candidate = $this->findNextFifoEligiblePaymentForSubscription($lockedSubscription);

            if ($candidate === null) {
                throw new SubscriptionRenewalException('Aucun crédit disponible pour cet abonnement.');
            }

            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->performCreditConsumption($lockedPayment, $lockedSubscription);
        });
    }

    /**
     * Consomme un mois de crédit du paiement et ouvre la période suivante sur l'abonnement.
     */
    public function consumeCreditFromPayment(Payment $payment): SubscriptionPaymentConsumption
    {
        return DB::transaction(function () use ($payment) {
            /** @var Subscription $lockedSubscription */
            $lockedSubscription = Subscription::query()
                ->whereKey($payment->subscription_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->performCreditConsumption($lockedPayment, $lockedSubscription);
        });
    }

    /**
     * @throws SubscriptionRenewalException
     */
    private function performCreditConsumption(Payment $lockedPayment, Subscription $lockedSubscription): SubscriptionPaymentConsumption
    {
        $this->assertPaymentEligibleForCreditConsumption($lockedPayment, $lockedSubscription);

        $nextPeriod = $this->calculateNextSubscriptionPeriod($lockedSubscription);

        $this->assertSubscriptionPeriodNotYetConsumed($lockedSubscription, $nextPeriod['start'], $nextPeriod['end']);

        $this->advanceSubscriptionToPeriod($lockedSubscription, $nextPeriod);

        try {
            $consumption = SubscriptionPaymentConsumption::query()->create([
                'payment_id' => $lockedPayment->id,
                'subscription_id' => $lockedSubscription->id,
                'period_start' => $nextPeriod['start'],
                'period_end' => $nextPeriod['end'],
                'consumed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            if (! $this->isSubscriptionPeriodUniqueViolation($exception)) {
                throw $exception;
            }

            throw new SubscriptionRenewalException('Cette période d\'abonnement a déjà été financée.');
        }

        $this->refreshPaymentCreditExhaustionState($lockedPayment);

        return $consumption->fresh(['payment', 'subscription']);
    }

    private function remainingCreditMonthsForPayment(Payment $payment): int
    {
        if ($payment->credit_months_purchased === null) {
            return 0;
        }

        $consumedCount = (int) ($payment->consumptions_count ?? $payment->consumptions()->count());

        return max(0, (int) $payment->credit_months_purchased - $consumedCount);
    }

    private function paymentContributesToConsumableCredit(Subscription $subscription, Payment $payment, int $remaining): bool
    {
        if ($subscription->isTerminated()) {
            return false;
        }

        if ($remaining <= 0) {
            return false;
        }

        if (! $payment->isPaid() || $payment->paid_at === null) {
            return false;
        }

        if ($payment->isRefunded()) {
            return false;
        }

        if ($payment->credit_months_purchased === null || $payment->monthly_unit_amount === null) {
            return false;
        }

        if ((int) $payment->monthly_unit_amount <= 0) {
            return false;
        }

        if ((string) $payment->currency !== (string) $subscription->currency) {
            return false;
        }

        return true;
    }

    private function findNextFifoEligiblePaymentForSubscription(Subscription $subscription): ?Payment
    {
        return Payment::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', Payment::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->where('currency', $subscription->currency)
            ->whereNotNull('credit_months_purchased')
            ->whereNotNull('monthly_unit_amount')
            ->whereRaw(
                'credit_months_purchased > (select count(*) from subscription_payment_consumptions as spc where spc.payment_id = payments.id)'
            )
            ->orderBy('paid_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Compatibilité HTTP/historique : délègue à consumeCreditFromPayment().
     */
    public function renewFromPayment(Payment $payment): Subscription
    {
        $consumption = $this->consumeCreditFromPayment($payment);

        return $consumption->subscription->fresh();
    }

    /**
     * Renouvelle l'abonnement pour la période mensuelle suivante (chemin legacy direct).
     *
     * Conservé pour les tests et appels existants exigeant amount === subscription.amount.
     */
    public function renew(Subscription $subscription, ?Payment $payment = null): Subscription
    {
        if ($subscription->isTerminated()) {
            throw new SubscriptionRenewalException('Impossible de renouveler un abonnement terminé.');
        }

        if ($subscription->current_period_end === null) {
            throw new SubscriptionRenewalException('L\'abonnement ne possède pas de fin de période courante.');
        }

        if ($payment === null) {
            throw new SubscriptionRenewalException('Un paiement payé est requis pour renouveler l\'abonnement.');
        }

        $this->assertPaymentValidForRenewal($subscription, $payment);
        $this->assertLegacyRenewalAmountAndCurrencyMatchSubscription($subscription, $payment);
        $this->assertPaymentPeriodCoversCurrentSubscriptionPeriod($subscription, $payment);

        $nextPeriod = $this->calculateNextSubscriptionPeriod($subscription);

        return $this->advanceSubscriptionToPeriod($subscription, $nextPeriod);
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function calculateNextSubscriptionPeriod(Subscription $subscription): array
    {
        if ($subscription->isTerminated()) {
            throw new SubscriptionRenewalException('Impossible de renouveler un abonnement terminé.');
        }

        if ($subscription->current_period_end === null) {
            throw new SubscriptionRenewalException('L\'abonnement ne possède pas de fin de période courante.');
        }

        $nextPeriodStart = Carbon::parse($subscription->current_period_end)->addSecond()->startOfDay();

        return $this->calculateNextPeriod($subscription, $nextPeriodStart);
    }

    /**
     * @param  array{start: Carbon, end: Carbon}  $period
     */
    private function advanceSubscriptionToPeriod(Subscription $subscription, array $period): Subscription
    {
        $subscription->current_period_start = $period['start'];
        $subscription->current_period_end = $period['end'];
        $subscription->grace_period_ends_at = null;
        $subscription->suspended_at = null;
        $subscription->status = Subscription::STATUS_ACTIVE;
        $subscription->save();

        return $subscription->fresh();
    }

    /**
     * @throws SubscriptionRenewalException
     */
    private function assertPaymentEligibleForCreditConsumption(Payment $payment, ?Subscription $subscription = null): void
    {
        $subscription ??= $this->resolveSubscriptionForPayment($payment);

        $this->assertPaymentValidForCreditConsumption($subscription, $payment);
        $this->assertPaymentCreditCurrencyCoherent($subscription, $payment);
        $this->assertPaymentHasRemainingCredit($payment);
    }

    /**
     * @throws SubscriptionRenewalException
     */
    private function assertPaymentValidForCreditConsumption(Subscription $subscription, Payment $payment): void
    {
        if ((int) $payment->subscription_id !== (int) $subscription->id) {
            throw new SubscriptionRenewalException('Le paiement ne correspond pas à cet abonnement.');
        }

        if ($subscription->isTerminated()) {
            throw new SubscriptionRenewalException('Impossible de renouveler un abonnement terminé.');
        }

        if ($payment->isRefunded()) {
            throw new SubscriptionRenewalException('Un paiement remboursé ne peut pas consommer de crédit.');
        }

        if ($payment->isPending()) {
            throw new SubscriptionRenewalException('Seul un paiement au statut payé permet la consommation de crédit.');
        }

        if ($payment->isFailed()) {
            throw new SubscriptionRenewalException('Seul un paiement au statut payé permet la consommation de crédit.');
        }

        if (! $payment->isPaid()) {
            throw new SubscriptionRenewalException('Seul un paiement au statut payé permet la consommation de crédit.');
        }

        if ($payment->paid_at === null) {
            throw new SubscriptionRenewalException('Un paiement payé doit posséder une date de paiement.');
        }

        if ($payment->credit_months_purchased === null || $payment->monthly_unit_amount === null) {
            throw new SubscriptionRenewalException('Le crédit de ce paiement n\'est pas initialisé.');
        }

        if ((int) $payment->monthly_unit_amount <= 0) {
            throw new SubscriptionRenewalException('Le tarif mensuel retenu pour ce paiement est invalide.');
        }
    }

    /**
     * @throws SubscriptionRenewalException
     */
    private function assertPaymentCreditCurrencyCoherent(Subscription $subscription, Payment $payment): void
    {
        if ((string) $payment->currency !== (string) $subscription->currency) {
            throw new SubscriptionRenewalException('La devise du paiement ne correspond pas à la devise de l\'abonnement.');
        }
    }

    /**
     * @throws SubscriptionRenewalException
     */
    private function assertPaymentHasRemainingCredit(Payment $payment): void
    {
        $consumedCount = SubscriptionPaymentConsumption::query()
            ->where('payment_id', $payment->id)
            ->count();

        $remaining = (int) $payment->credit_months_purchased - $consumedCount;

        if ($remaining <= 0) {
            throw new SubscriptionRenewalException('Ce paiement n\'a plus de crédit disponible.');
        }
    }

    private function refreshPaymentCreditExhaustionState(Payment $lockedPayment): void
    {
        $consumedCount = SubscriptionPaymentConsumption::query()
            ->where('payment_id', $lockedPayment->id)
            ->count();

        $remaining = (int) $lockedPayment->credit_months_purchased - $consumedCount;

        if ($remaining <= 0) {
            $lockedPayment->credit_exhausted_at = now();
        } else {
            $lockedPayment->credit_exhausted_at = null;
        }

        $lockedPayment->save();
    }

    /**
     * @throws SubscriptionRenewalException
     */
    private function assertSubscriptionPeriodNotYetConsumed(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): void
    {
        $exists = SubscriptionPaymentConsumption::query()
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $periodStart->format('Y-m-d H:i:s'))
            ->where('period_end', $periodEnd->format('Y-m-d H:i:s'))
            ->exists();

        if ($exists) {
            throw new SubscriptionRenewalException('Cette période d\'abonnement a déjà été financée.');
        }
    }

    /**
     * @throws SubscriptionRenewalException
     */
    private function resolveSubscriptionForPayment(Payment $payment): Subscription
    {
        $subscription = $payment->relationLoaded('subscription')
            ? $payment->subscription
            : $payment->subscription()->first();

        if ($subscription === null) {
            throw new SubscriptionRenewalException('Aucun abonnement n\'est associé à ce paiement.');
        }

        return $subscription;
    }

    /**
     * Legacy renew() : période optionnelle sur le paiement.
     *
     * @throws SubscriptionRenewalException
     */
    private function assertPaymentPeriodCoversCurrentSubscriptionPeriod(Subscription $subscription, Payment $payment): void
    {
        if ($subscription->current_period_start === null || $subscription->current_period_end === null) {
            throw new SubscriptionRenewalException('L\'abonnement ne possède pas de période courante définie.');
        }

        $paymentPeriodStart = $payment->period_start;
        $paymentPeriodEnd = $payment->period_end;

        if ($paymentPeriodStart === null && $paymentPeriodEnd === null) {
            return;
        }

        if ($paymentPeriodStart === null || $paymentPeriodEnd === null) {
            throw new SubscriptionRenewalException('Les dates de période du paiement sont incomplètes.');
        }

        $paymentStart = Carbon::parse($paymentPeriodStart)->startOfDay();
        $paymentEndDay = Carbon::parse($paymentPeriodEnd)->startOfDay();

        if ($paymentEndDay->lessThan($paymentStart)) {
            throw new SubscriptionRenewalException('La période du paiement est incohérente : la date de fin est antérieure à la date de début.');
        }

        $subscriptionStart = Carbon::parse($subscription->current_period_start)->startOfDay();
        $subscriptionEndDay = Carbon::parse($subscription->current_period_end)->startOfDay();

        if (! $paymentStart->equalTo($subscriptionStart) || ! $paymentEndDay->equalTo($subscriptionEndDay)) {
            throw new SubscriptionRenewalException('La période du paiement ne correspond pas à la période courante de l\'abonnement.');
        }
    }

    /**
     * @throws SubscriptionRenewalException
     */
    private function assertPaymentValidForRenewal(Subscription $subscription, Payment $payment): void
    {
        if ((int) $payment->subscription_id !== (int) $subscription->id) {
            throw new SubscriptionRenewalException('Le paiement ne correspond pas à cet abonnement.');
        }

        if (! $payment->isPaid()) {
            throw new SubscriptionRenewalException('Seul un paiement au statut payé permet le renouvellement.');
        }
    }

    /**
     * Legacy renew() : montant total du paiement = tarif mensuel courant.
     *
     * @throws SubscriptionRenewalException
     */
    private function assertLegacyRenewalAmountAndCurrencyMatchSubscription(Subscription $subscription, Payment $payment): void
    {
        if ((int) $payment->amount !== (int) $subscription->amount) {
            throw new SubscriptionRenewalException('Le montant du paiement ne correspond pas au montant de l\'abonnement.');
        }

        if ((string) $payment->currency !== (string) $subscription->currency) {
            throw new SubscriptionRenewalException('La devise du paiement ne correspond pas à la devise de l\'abonnement.');
        }
    }

    /**
     * @throws SubscriptionLifecycleException
     */
    private function applyLifecycleSync(Subscription $subscription, Carbon $now): void
    {
        if ($subscription->isTerminated()) {
            return;
        }

        if ($subscription->isSuspended()) {
            return;
        }

        if ($subscription->isActive()) {
            $this->syncActiveSubscription($subscription, $now);

            return;
        }

        if ($subscription->isInGracePeriod()) {
            $this->syncGracePeriodSubscription($subscription, $now);

            return;
        }

        throw new SubscriptionLifecycleException('Statut d\'abonnement non pris en charge pour la synchronisation.');
    }

    /**
     * @throws SubscriptionLifecycleException
     */
    private function syncActiveSubscription(Subscription $subscription, Carbon $now): void
    {
        if ($subscription->current_period_end === null) {
            throw new SubscriptionLifecycleException('Impossible de synchroniser un abonnement actif sans fin de période courante.');
        }

        $periodEnd = Carbon::parse($subscription->current_period_end);

        if ($now->lessThanOrEqualTo($periodEnd)) {
            return;
        }

        $subscription->status = Subscription::STATUS_GRACE_PERIOD;

        if ($subscription->grace_period_ends_at === null) {
            $subscription->grace_period_ends_at = $this->calculateGracePeriodEndsAt($periodEnd);
        }
    }

    /**
     * @throws SubscriptionLifecycleException
     */
    private function syncGracePeriodSubscription(Subscription $subscription, Carbon $now): void
    {
        if ($subscription->grace_period_ends_at === null) {
            throw new SubscriptionLifecycleException('Impossible de synchroniser un abonnement en période de grâce sans date de fin de grâce.');
        }

        if ($subscription->current_period_end === null) {
            throw new SubscriptionLifecycleException('Impossible de synchroniser un abonnement en période de grâce sans fin de période courante.');
        }

        $graceEndsAt = Carbon::parse($subscription->grace_period_ends_at);
        $periodEnd = Carbon::parse($subscription->current_period_end);

        if ($graceEndsAt->lessThan($periodEnd)) {
            throw new SubscriptionLifecycleException('La date de fin de grâce est antérieure à la fin de période courante.');
        }

        if ($now->lessThanOrEqualTo($graceEndsAt)) {
            return;
        }

        $subscription->status = Subscription::STATUS_SUSPENDED;

        if ($subscription->suspended_at === null) {
            $subscription->suspended_at = $now;
        }
    }

    /**
     * Fin de grâce : dernier instant du N-ième jour après la fin de période (GRACE_PERIOD_DAYS jours complets).
     *
     * Exemple : fin de période 31/10 23:59:59 → fin de grâce 07/11 23:59:59.
     */
    private function calculateGracePeriodEndsAt(Carbon $currentPeriodEnd): Carbon
    {
        return $currentPeriodEnd
            ->copy()
            ->addSecond()
            ->addDays(self::GRACE_PERIOD_DAYS)
            ->subSecond();
    }

    private function isSubscriptionPeriodUniqueViolation(UniqueConstraintViolationException|QueryException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            && str_contains($message, 'subscription_payment_consumptions');
    }
}
