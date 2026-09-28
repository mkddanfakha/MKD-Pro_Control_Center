<?php

namespace App\Services;

use App\Models\Installation;
use App\Models\Subscription;
use Carbon\Carbon;

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

        if ($this->isSubscriptionEffectivelyAccessible($subscription)) {
            return 'accessible';
        }

        if ($subscription->status === Subscription::STATUS_SUSPENDED) {
            return 'suspended';
        }

        return 'suspended';
    }

    private function isSubscriptionEffectivelyAccessible(Subscription $subscription): bool
    {
        return match ($subscription->status) {
            Subscription::STATUS_ACTIVE => $this->isActivePeriodStillValid($subscription),
            Subscription::STATUS_GRACE_PERIOD => $this->isGracePeriodStillValid($subscription),
            Subscription::STATUS_SUSPENDED => false,
            Subscription::STATUS_TERMINATED => false,
            default => false,
        };
    }

    private function isActivePeriodStillValid(Subscription $subscription): bool
    {
        if ($subscription->current_period_end === null) {
            return true;
        }

        return Carbon::now()->lessThanOrEqualTo(Carbon::parse($subscription->current_period_end));
    }

    private function isGracePeriodStillValid(Subscription $subscription): bool
    {
        if ($subscription->grace_period_ends_at === null) {
            return true;
        }

        return Carbon::now()->lessThanOrEqualTo(Carbon::parse($subscription->grace_period_ends_at));
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
