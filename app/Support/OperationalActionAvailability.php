<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Subscription;
use App\Services\SubscriptionService;

/**
 * Éligibilité UI des actions opérationnelles souscription / paiement (le service reste l'autorité).
 */
final class OperationalActionAvailability
{
    /**
     * @return array{
     *     enabled: bool
     * }
     */
    public static function automaticCreditRenewalState(): array
    {
        return [
            'enabled' => (bool) config('subscriptions.automatic_credit_renewal.enabled', false),
        ];
    }

    /**
     * @return array{
     *     can_consume_credit: bool,
     *     consume_credit_url: string,
     *     unavailable_reason: string|null,
     *     fifo_help: string
     * }
     */
    public static function forSubscription(Subscription $subscription): array
    {
        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);

        $fifoHelp = 'Le prochain mois est prélevé automatiquement sur le paiement payé le plus ancien (ordre paid_at, puis identifiant). Aucun choix manuel de paiement n’est proposé.';

        if ($subscription->isTerminated()) {
            return [
                'can_consume_credit' => false,
                'consume_credit_url' => route('subscriptions.consume-credit', $subscription),
                'unavailable_reason' => 'Aucune action de crédit disponible : l’abonnement est terminé.',
                'fifo_help' => $fifoHelp,
            ];
        }

        $preview = $service->previewNextFifoCreditConsumption($subscription);

        return [
            'can_consume_credit' => $preview !== null,
            'consume_credit_url' => route('subscriptions.consume-credit', $subscription),
            'unavailable_reason' => $preview === null
                ? 'Aucun crédit consommable disponible actuellement sur cet abonnement.'
                : null,
            'fifo_help' => $fifoHelp,
        ];
    }

    /**
     * @return array{
     *     can_renew_from_payment: bool,
     *     renew_subscription_url: string,
     *     unavailable_reason: string|null
     * }
     */
    public static function forPayment(Payment $payment): array
    {
        $payment->loadMissing('subscription');
        $subscription = $payment->subscription;

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);

        if ($subscription === null) {
            return [
                'can_renew_from_payment' => false,
                'renew_subscription_url' => route('payments.renew-subscription', $payment),
                'unavailable_reason' => 'Aucun abonnement associé à ce paiement.',
            ];
        }

        if ($subscription->isTerminated()) {
            return [
                'can_renew_from_payment' => false,
                'renew_subscription_url' => route('payments.renew-subscription', $payment),
                'unavailable_reason' => 'Aucun renouvellement disponible : l’abonnement est terminé.',
            ];
        }

        $canRenew = $service->canRenewFromPayment($payment);

        return [
            'can_renew_from_payment' => $canRenew,
            'renew_subscription_url' => route('payments.renew-subscription', $payment),
            'unavailable_reason' => $canRenew
                ? null
                : 'Ce paiement ne peut pas être utilisé pour un renouvellement (statut, crédit épuisé ou remboursé).',
        ];
    }
}
