<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default monthly amount (product default)
    |--------------------------------------------------------------------------
    |
    | Initial monthly amount suggested when creating a subscription if none
    | is provided. This is not the current tariff of existing subscriptions;
    | use Subscription.amount for the live monthly rate per subscription.
    |
    */

    'default_monthly_amount' => 15000,

    /*
    |--------------------------------------------------------------------------
    | Automatic credit renewal (preparation — Task 272)
    |--------------------------------------------------------------------------
    |
    | When disabled (default), SubscriptionAutomaticCreditRenewalService does
    | not consume credit. The lifecycle scheduler is unaffected.
    |
    */

    'automatic_credit_renewal' => [
        'enabled' => env('SUBSCRIPTION_AUTOMATIC_CREDIT_RENEWAL_ENABLED', false),
        'record_audit' => true,
        // Politique métier formalisée au Task 273 (voir docs/architecture/subscription-model.md).
        // Commande : subscriptions:renew-with-credit (planifiée daily ; inactive tant que enabled=false).
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription expiry reminders — detection only (Task 281)
    |--------------------------------------------------------------------------
    |
    | Read-only detection of upcoming current_period_end thresholds. No
    | notifications, persistence, or scheduler until a future task enables them.
    |
    */

    'subscription_reminders' => [
        'enabled' => env('SUBSCRIPTION_REMINDERS_ENABLED', false),
        'days_before' => [7, 3, 1, 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription reminder notifications — channel preparation (Task 284)
    |--------------------------------------------------------------------------
    |
    | Orchestrates composed reminder content through a configurable channel.
    | Default channel "prepared" validates routing without external delivery.
    | Does not update SubscriptionReminder status or sent_at.
    |
    */

    'subscription_reminder_notifications' => [
        'enabled' => env('SUBSCRIPTION_REMINDER_NOTIFICATIONS_ENABLED', false),
        'channel' => env('SUBSCRIPTION_REMINDER_NOTIFICATION_CHANNEL', 'prepared'),
        'central_admin_email' => env('SUBSCRIPTION_REMINDER_ADMIN_EMAIL'),
    ],

];
