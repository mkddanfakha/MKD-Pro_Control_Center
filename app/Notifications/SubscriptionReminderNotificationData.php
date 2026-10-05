<?php

namespace App\Notifications;

/**
 * Objet de composition d’une notification de rappel (Task 283) — aucun envoi.
 */
readonly class SubscriptionReminderNotificationData
{
    public function __construct(
        public int $reminder_id,
        public int $subscription_id,
        public int $installation_id,
        public string $installation_name,
        public string $reminder_type,
        public int $threshold_days,
        public string $current_period_end,
        public int $days_remaining,
        public string $subscription_status,
        public int $amount,
        public string $currency,
        public SubscriptionReminderRecipient $recipient,
        public string $title,
        public string $body,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reminder_id' => $this->reminder_id,
            'subscription_id' => $this->subscription_id,
            'installation_id' => $this->installation_id,
            'installation_name' => $this->installation_name,
            'type' => $this->reminder_type,
            'threshold_days' => $this->threshold_days,
            'current_period_end' => $this->current_period_end,
            'days_remaining' => $this->days_remaining,
            'subscription_status' => $this->subscription_status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'recipient' => $this->recipient->toArray(),
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
