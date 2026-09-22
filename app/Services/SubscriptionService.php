<?php

namespace App\Services;

use App\Exceptions\Subscription\SubscriptionPeriodException;
use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\Carbon;

class SubscriptionService
{
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

        $anchor = $subscription->starts_at !== null
            ? Carbon::parse($subscription->starts_at)
            : now();

        $period = $this->calculateNextPeriod($subscription, $anchor);

        if ($subscription->starts_at === null) {
            $subscription->starts_at = $period['start'];
        }

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
    private function assertPaymentValidForRenewal(Subscription $subscription, Payment $payment): void
    {
        if ((int) $payment->subscription_id !== (int) $subscription->id) {
            throw new SubscriptionRenewalException('Le paiement ne correspond pas à cet abonnement.');
        }

        if (! $payment->isPaid()) {
            throw new SubscriptionRenewalException('Seul un paiement au statut payé permet le renouvellement.');
        }
    }
}
