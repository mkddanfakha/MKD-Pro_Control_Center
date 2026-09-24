<?php

namespace App\Services;

use App\Models\Installation;
use App\Models\Subscription;

class InstallationAccessService
{
    /**
     * @var list<string>
     */
    private const NON_TERMINATED_STATUSES = [
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_GRACE_PERIOD,
        Subscription::STATUS_SUSPENDED,
    ];

    public function isAccessible(Installation $installation): bool
    {
        return $this->accessStatus($installation) === 'accessible';
    }

    public function accessStatus(Installation $installation): string
    {
        return $this->accessStatusForSubscription($this->latestSubscription($installation));
    }

    /**
     * @return array{accessible: bool, status: string, subscription_status: string|null}
     */
    public function accessSummary(Installation $installation): array
    {
        $subscription = $this->latestSubscription($installation);
        $status = $this->accessStatusForSubscription($subscription);

        return [
            'accessible' => $status === 'accessible',
            'status' => $status,
            'subscription_status' => $subscription?->status,
        ];
    }

    private function accessStatusForSubscription(?Subscription $subscription): string
    {
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

    public function latestSubscription(Installation $installation): ?Subscription
    {
        if ($installation->relationLoaded('subscriptions')) {
            return $installation->subscriptions
                ->whereIn('status', self::NON_TERMINATED_STATUSES)
                ->sortByDesc('id')
                ->first();
        }

        return $installation->subscriptions()
            ->whereIn('status', self::NON_TERMINATED_STATUSES)
            ->latest('id')
            ->first();
    }
}
