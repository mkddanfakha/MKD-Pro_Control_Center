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

,

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

];
