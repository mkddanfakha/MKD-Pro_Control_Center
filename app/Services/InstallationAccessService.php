<?php

namespace App\Services;

use App\Models\Installation;
use App\Models\Subscription;

class InstallationAccessService
{
    public function isAccessible(Installation $installation): bool
    {
        return $this->accessStatus($installation) === 'accessible';
    }

    public function accessStatus(Installation $installation): string
    {
        $subscription = $installation->subscriptions()->latest('id')->first();

        if ($subscription === null) {
            return 'no_subscription';
        }

        return match ($subscription->status) {
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_GRACE_PERIOD => 'accessible',
            Subscription::STATUS_SUSPENDED => 'suspended',
            Subscription::STATUS_TERMINATED => 'terminated',
            default => 'suspended',
        };
    }
}
