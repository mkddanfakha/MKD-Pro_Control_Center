<?php

namespace App\Policies;

use App\Models\SubscriptionReminder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Consultation read-only des rappels d’échéance (Control Center).
 */
class SubscriptionReminderPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('accessControlCenter');
    }

    public function view(User $user, SubscriptionReminder $subscriptionReminder): bool
    {
        return $this->viewAny($user);
    }
}
