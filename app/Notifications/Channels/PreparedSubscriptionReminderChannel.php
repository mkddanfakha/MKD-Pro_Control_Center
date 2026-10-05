<?php

namespace App\Notifications\Channels;

use App\Contracts\SubscriptionReminderNotificationChannel;
use App\Notifications\SubscriptionReminderNotificationData;
use App\Notifications\SubscriptionReminderSendResult;

/**
 * Canal local de préparation (Task 284) — valide le routage sans envoi externe.
 */
class PreparedSubscriptionReminderChannel implements SubscriptionReminderNotificationChannel
{
    public const CHANNEL_NAME = 'prepared';

    public function send(SubscriptionReminderNotificationData $data): SubscriptionReminderSendResult
    {
        return new SubscriptionReminderSendResult(
            status: SubscriptionReminderSendResult::STATUS_PREPARED,
            channel: self::CHANNEL_NAME,
            recipientType: $data->recipient->type,
            recipientId: $data->recipient->id,
            message: 'Contenu prêt pour envoi ; aucun canal externe configuré.',
        );
    }
}
