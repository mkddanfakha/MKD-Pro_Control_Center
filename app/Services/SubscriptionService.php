<?php

namespace App\Services;

use App\Exceptions\Subscription\SubscriptionLifecycleException;
use App\Exceptions\Subscription\SubscriptionPeriodException;
use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\Carbon;
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
     * Indique si un paiement payé peut encore être utilisé pour renouveler son abonnement.
     */
    public function canRenewFromPayment(Payment $payment): bool
    {
        try {
            $this->assertPaymentEligibleForRenewal($payment);

            return true;
        } catch (SubscriptionRenewalException) {
            return false;
        }
    }

    /**
     * Prévisualise la période suivante après renouvellement (sans persister).
     *
     * @return array{current_period_start: string, current_period_end: string, next_period_start: string, next_period_end: string}
     */
    public function previewRenewalFromPayment(Payment $payment): array
    {
        $subscription = $this->resolveSubscriptionForPayment($payment);
        $this->assertPaymentEligibleForRenewal($payment);

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
     * Renouvelle l'abonnement à partir d'un paiement payé (action explicite, idempotente côté paiement).
     */
    public function renewFromPayment(Payment $payment): Subscription
    {
        return DB::transaction(function () use ($payment) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Subscription $subscription */
            $subscription = Subscription::query()
                ->whereKey($lockedPayment->subscription_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPaymentEligibleForRenewal($lockedPayment, $subscription);

            $renewedSubscription = $this->renew($subscription, $lockedPayment);

            $lockedPayment->renewal_applied_at = now();
            $lockedPayment->save();

            return $renewedSubscription;
        });
    }

    /**
     * Renouvelle l'abonnement pour la période mensuelle suivante après un paiement valide.
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
        $this->assertPaymentPeriodCoversCurrentSubscriptionPeriod($subscription, $payment);

        $nextPeriodStart = Carbon::parse($subscription->current_period_end)->addSecond()->startOfDay();
        $period = $this->calculateNextPeriod($subscription, $nextPeriodStart);

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
    private function assertPaymentEligibleForRenewal(Payment $payment, ?Subscription $subscription = null): void
    {
        $subscription ??= $this->resolveSubscriptionForPayment($payment);

        if ($payment->hasRenewalBeenApplied()) {
            throw new SubscriptionRenewalException('Ce paiement a déjà été utilisé pour renouveler l\'abonnement.');
        }

        $this->assertPaymentValidForRenewal($subscription, $payment);
        $this->assertPaymentPeriodCoversCurrentSubscriptionPeriod($subscription, $payment);
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
     * Le paiement doit couvrir la période courante de l'abonnement.
     *
     * Si period_start et period_end sont renseignés sur le paiement, ils doivent correspondre
     * au calendrier de current_period_start / current_period_end (comparaison au jour près).
     * Si les dates du paiement sont absentes, la période courante de l'abonnement est implicitement acceptée.
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

        $subscriptionStart = Carbon::parse($subscription->current_period_start)->startOfDay();
        $subscriptionEndDay = Carbon::parse($subscription->current_period_end)->startOfDay();
        $paymentStart = Carbon::parse($paymentPeriodStart)->startOfDay();
        $paymentEndDay = Carbon::parse($paymentPeriodEnd)->startOfDay();

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
}
