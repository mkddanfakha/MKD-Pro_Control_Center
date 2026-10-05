<?php

namespace App\Contracts;

use App\Notifications\SubscriptionReminderNotificationData;
use App\Notifications\SubscriptionReminderSendResult;

/**
 * Canal d’envoi abstrait pour les rappels d’échéance (Task 284).
 */
interface SubscriptionReminderNotificationChannel
{
    public function send(SubscriptionReminderNotificationData $data): SubscriptionReminderSendResult;
}
