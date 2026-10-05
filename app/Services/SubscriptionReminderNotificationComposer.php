<?php

namespace App\Services;

use App\Exceptions\Subscription\SubscriptionReminderNotificationException;
use App\Models\SubscriptionReminder;
use App\Notifications\SubscriptionReminderNotificationData;
use App\Notifications\SubscriptionReminderRecipient;
use Carbon\Carbon;

/**
 * Composition read-only du contenu de notification pour un SubscriptionReminder (Task 283).
 */
class SubscriptionReminderNotificationComposer
{
    /**
     * @var array<int, string>
     */
    private const THRESHOLD_TITLES = [
        7 => 'Échéance d’abonnement dans 7 jours',
        3 => 'Échéance d’abonnement dans 3 jours',
        1 => 'Échéance d’abonnement demain',
        0 => 'Échéance d’abonnement aujourd’hui',
    ];

    public function compose(SubscriptionReminder $reminder): SubscriptionReminderNotificationData
    {
        if ($reminder->reminder_type !== SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY) {
            throw new SubscriptionReminderNotificationException(
                'Type de rappel non supporté pour la composition : '.$reminder->reminder_type,
            );
        }

        $reminder->loadMissing(['subscription.installation']);

        $subscription = $reminder->subscription;

        if ($subscription === null) {
            throw new SubscriptionReminderNotificationException(
                'Abonnement introuvable pour le rappel #'.$reminder->id.'.',
            );
        }

        $installation = $subscription->installation;

        if ($installation === null) {
            throw new SubscriptionReminderNotificationException(
                'Installation introuvable pour l’abonnement #'.$subscription->id.'.',
            );
        }

        if ($subscription->current_period_end === null) {
            throw new SubscriptionReminderNotificationException(
                'current_period_end manquant pour l’abonnement #'.$subscription->id.'.',
            );
        }

        $thresholdDays = (int) $reminder->threshold_days;
        $title = $this->resolveTitle($thresholdDays);
        $periodEndUtc = Carbon::parse($subscription->current_period_end)->utc();
        $body = $this->buildBody(
            installationName: (string) $installation->name,
            periodEndUtc: $periodEndUtc,
            amount: (int) $subscription->amount,
            currency: (string) $subscription->currency,
            thresholdDays: $thresholdDays,
        );

        return new SubscriptionReminderNotificationData(
            reminder_id: $reminder->id,
            subscription_id: $subscription->id,
            installation_id: $installation->id,
            installation_name: (string) $installation->name,
            reminder_type: $reminder->reminder_type,
            threshold_days: $thresholdDays,
            current_period_end: $periodEndUtc->format('Y-m-d H:i:s'),
            days_remaining: $thresholdDays,
            subscription_status: (string) $subscription->status,
            amount: (int) $subscription->amount,
            currency: (string) $subscription->currency,
            recipient: new SubscriptionReminderRecipient(
                type: SubscriptionReminderRecipient::TYPE_CENTRAL_ADMIN,
                id: null,
            ),
            title: $title,
            body: $body,
        );
    }

    private function resolveTitle(int $thresholdDays): string
    {
        if (! array_key_exists($thresholdDays, self::THRESHOLD_TITLES)) {
            throw new SubscriptionReminderNotificationException(
                'Seuil de rappel non supporté pour le titre : '.$thresholdDays,
            );
        }

        return self::THRESHOLD_TITLES[$thresholdDays];
    }

    private function buildBody(
        string $installationName,
        Carbon $periodEndUtc,
        int $amount,
        string $currency,
        int $thresholdDays,
    ): string {
        $dateLabel = $periodEndUtc->format('d/m/Y');
        $amountLabel = number_format($amount, 0, ',', ' ').' '.$currency;

        $lines = [
            'Votre abonnement MKD-Pro pour '.$installationName.' arrive à échéance le '.$dateLabel.'.',
            'Montant mensuel : '.$amountLabel.'.',
            $this->resolveThresholdSentence($thresholdDays),
        ];

        return implode("\n", $lines);
    }

    private function resolveThresholdSentence(int $thresholdDays): string
    {
        return match ($thresholdDays) {
            0 => 'Votre abonnement arrive à échéance aujourd’hui.',
            1 => 'Il reste 1 jour avant l’échéance.',
            default => 'Il reste '.$thresholdDays.' jours avant l’échéance.',
        };
    }
}
