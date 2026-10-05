<?php

namespace App\Services;

use App\Exceptions\Subscription\SubscriptionReminderNotificationException;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Détection read-only des rappels d’échéance (Task 281) — aucune notification ni mutation.
 */
class SubscriptionReminderService
{
    public const REASON_REMINDERS_DISABLED = 'reminders_disabled';

    public const REASON_TERMINATED = 'subscription_terminated';

    public const REASON_NO_PERIOD_END = 'no_period_end';

    public const REASON_PERIOD_EXPIRED = 'period_expired';

    public const REASON_GRACE_PERIOD = 'grace_period';

    public const REASON_SUSPENDED = 'suspended';

    public const REASON_NO_MATCHING_THRESHOLD = 'no_matching_threshold';

    public const REASON_REMINDER_DUE = 'reminder_due';

    public const TEMPORAL_FUTURE = 'FUTURE';

    public const TEMPORAL_CURRENT = 'CURRENT';

    public const TEMPORAL_DUE_SOON = 'DUE_SOON';

    public const TEMPORAL_EXPIRED = 'EXPIRED';

    public const DELIVERY_OUTCOME_SENT = 'sent';

    public const DELIVERY_OUTCOME_ALREADY_SENT = 'already_sent';

    public const DELIVERY_OUTCOME_ALREADY_FAILED = 'already_failed';

    public const DELIVERY_OUTCOME_FAILED = 'failed';

    public function isEnabled(): bool
    {
        return (bool) config('subscriptions.subscription_reminders.enabled', false);
    }

    /**
     * @return array<int, int>
     */
    public function configuredDaysBefore(): array
    {
        $days = config('subscriptions.subscription_reminders.days_before', [7, 3, 1, 0]);

        if (! is_array($days)) {
            return [7, 3, 1, 0];
        }

        $normalized = array_values(array_unique(array_map('intval', $days)));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return Builder<Subscription>
     */
    public function querySubscriptionsForReminderAudit(bool $includeNonActiveStatuses = false): Builder
    {
        $query = Subscription::query()->whereNotNull('current_period_end');

        if ($includeNonActiveStatuses) {
            $query->where('status', '!=', Subscription::STATUS_TERMINATED);
        } else {
            $query->where('status', Subscription::STATUS_ACTIVE);
        }

        return $query->orderBy('id');
    }

    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     temporal_state: string,
     *     days_remaining: ?int,
     *     matched_threshold: ?int,
     *     reminder_due_today: bool,
     * }
     */
    public function assessReminder(Subscription $subscription, ?Carbon $now = null): array
    {
        $now = $this->normalizeInstant($now ?? now());

        if (! $this->isEnabled()) {
            return $this->result(
                eligible: false,
                reason: self::REASON_REMINDERS_DISABLED,
                temporalState: self::TEMPORAL_CURRENT,
                daysRemaining: $this->daysRemainingUntilPeriodEnd($subscription, $now),
                matchedThreshold: null,
            );
        }

        if ($subscription->isTerminated()) {
            return $this->result(
                eligible: false,
                reason: self::REASON_TERMINATED,
                temporalState: self::TEMPORAL_EXPIRED,
                daysRemaining: null,
                matchedThreshold: null,
            );
        }

        if ($subscription->current_period_end === null) {
            return $this->result(
                eligible: false,
                reason: self::REASON_NO_PERIOD_END,
                temporalState: self::TEMPORAL_CURRENT,
                daysRemaining: null,
                matchedThreshold: null,
            );
        }

        $periodEndDay = $this->periodEndDay($subscription);
        $today = $this->todayUtc($now);

        if ($today->greaterThan($periodEndDay)) {
            return $this->result(
                eligible: false,
                reason: self::REASON_PERIOD_EXPIRED,
                temporalState: self::TEMPORAL_EXPIRED,
                daysRemaining: null,
                matchedThreshold: null,
            );
        }

        if ($subscription->isInGracePeriod()) {
            return $this->result(
                eligible: false,
                reason: self::REASON_GRACE_PERIOD,
                temporalState: self::TEMPORAL_EXPIRED,
                daysRemaining: $this->daysRemainingUntilPeriodEnd($subscription, $now),
                matchedThreshold: null,
            );
        }

        if ($subscription->isSuspended()) {
            return $this->result(
                eligible: false,
                reason: self::REASON_SUSPENDED,
                temporalState: self::TEMPORAL_CURRENT,
                daysRemaining: $this->daysRemainingUntilPeriodEnd($subscription, $now),
                matchedThreshold: null,
            );
        }

        $daysRemaining = $this->daysRemainingUntilPeriodEnd($subscription, $now);

        if ($subscription->current_period_start !== null) {
            $periodStartDay = $this->periodStartDay($subscription);
            if ($today->lessThan($periodStartDay)) {
                return $this->result(
                    eligible: false,
                    reason: self::REASON_NO_MATCHING_THRESHOLD,
                    temporalState: self::TEMPORAL_FUTURE,
                    daysRemaining: $daysRemaining,
                    matchedThreshold: null,
                );
            }
        }

        $matchedThreshold = $this->matchedThresholdForDaysRemaining($daysRemaining);

        if ($matchedThreshold === null) {
            return $this->result(
                eligible: false,
                reason: self::REASON_NO_MATCHING_THRESHOLD,
                temporalState: self::TEMPORAL_CURRENT,
                daysRemaining: $daysRemaining,
                matchedThreshold: null,
            );
        }

        return $this->result(
            eligible: true,
            reason: self::REASON_REMINDER_DUE,
            temporalState: self::TEMPORAL_DUE_SOON,
            daysRemaining: $daysRemaining,
            matchedThreshold: $matchedThreshold,
        );
    }

    public function daysRemainingUntilPeriodEnd(Subscription $subscription, ?Carbon $now = null): ?int
    {
        if ($subscription->current_period_end === null) {
            return null;
        }

        $now = $this->normalizeInstant($now ?? now());
        $today = $this->todayUtc($now);
        $periodEndDay = $this->periodEndDay($subscription);

        if ($today->greaterThan($periodEndDay)) {
            return null;
        }

        return (int) $today->diffInDays($periodEndDay, false);
    }

    public function matchedThresholdForDaysRemaining(?int $daysRemaining): ?int
    {
        if ($daysRemaining === null) {
            return null;
        }

        $thresholds = $this->configuredDaysBefore();

        return in_array($daysRemaining, $thresholds, true) ? $daysRemaining : null;
    }

    /**
     * Date/heure théorique UTC du seuil (calendrier aligné sur days_remaining).
     */
    public function resolveScheduledFor(Subscription $subscription, int $thresholdDays): Carbon
    {
        return $this->periodEndDay($subscription)->copy()->subDays($thresholdDays)->startOfDay();
    }

    /**
     * Persiste un rappel détecté de façon idempotente (Task 282).
     *
     * @return array{
     *     reminder: SubscriptionReminder,
     *     created: bool,
     *     outcome: 'recorded'|'already_recorded',
     * }
     */
    public function recordDetectedReminder(
        Subscription $subscription,
        int $thresholdDays,
        ?Carbon $now = null,
        string $reminderType = SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
    ): array {
        $now = $this->normalizeInstant($now ?? now());
        $scheduledFor = $this->resolveScheduledFor($subscription, $thresholdDays);

        $lookup = [
            'subscription_id' => $subscription->id,
            'reminder_type' => $reminderType,
            'threshold_days' => $thresholdDays,
            'scheduled_for' => $scheduledFor->format('Y-m-d H:i:s'),
        ];

        $existing = SubscriptionReminder::query()->where($lookup)->first();

        if ($existing !== null) {
            return [
                'reminder' => $existing,
                'created' => false,
                'outcome' => 'already_recorded',
            ];
        }

        try {
            $reminder = SubscriptionReminder::query()->create([
                'subscription_id' => $subscription->id,
                'reminder_type' => $reminderType,
                'threshold_days' => $thresholdDays,
                'scheduled_for' => $scheduledFor,
                'detected_at' => $now,
                'sent_at' => null,
                'status' => SubscriptionReminder::STATUS_DETECTED,
            ]);

            return [
                'reminder' => $reminder,
                'created' => true,
                'outcome' => 'recorded',
            ];
        } catch (UniqueConstraintViolationException) {
            $reminder = SubscriptionReminder::query()->where($lookup)->firstOrFail();

            return [
                'reminder' => $reminder,
                'created' => false,
                'outcome' => 'already_recorded',
            ];
        }
    }

    /**
     * Finalise un rappel détecté comme envoyé (Task 285).
     *
     * @return array{outcome: string, reminder: SubscriptionReminder}
     */
    public function markAsSent(SubscriptionReminder $reminder, ?Carbon $sentAt = null): array
    {
        $sentAt = $this->normalizeInstant($sentAt ?? now());

        return DB::transaction(function () use ($reminder, $sentAt): array {
            $locked = SubscriptionReminder::query()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SubscriptionReminder::STATUS_SENT) {
                return [
                    'outcome' => self::DELIVERY_OUTCOME_ALREADY_SENT,
                    'reminder' => $locked,
                ];
            }

            if ($locked->status === SubscriptionReminder::STATUS_FAILED) {
                return [
                    'outcome' => self::DELIVERY_OUTCOME_ALREADY_FAILED,
                    'reminder' => $locked,
                ];
            }

            if ($locked->status !== SubscriptionReminder::STATUS_DETECTED) {
                throw new SubscriptionReminderNotificationException(
                    'Transition vers sent impossible depuis le statut '.$locked->status.'.',
                );
            }

            $locked->status = SubscriptionReminder::STATUS_SENT;
            $locked->sent_at = $sentAt;
            $locked->save();

            Log::info('subscription_reminder.delivery.sent', [
                'reminder_id' => $locked->id,
                'subscription_id' => $locked->subscription_id,
                'sent_at' => $locked->sent_at?->toIso8601String(),
            ]);

            return [
                'outcome' => self::DELIVERY_OUTCOME_SENT,
                'reminder' => $locked->fresh(),
            ];
        });
    }

    /**
     * Finalise un rappel détecté comme échoué (Task 285).
     *
     * @return array{outcome: string, reminder: SubscriptionReminder}
     */
    public function markAsFailed(SubscriptionReminder $reminder, ?string $reason = null): array
    {
        if ($reason !== null && $reason !== '') {
            Log::warning('subscription_reminder.delivery.failed', [
                'reminder_id' => $reminder->id,
                'subscription_id' => $reminder->subscription_id,
                'reason' => $reason,
            ]);
        }

        return DB::transaction(function () use ($reminder): array {
            $locked = SubscriptionReminder::query()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SubscriptionReminder::STATUS_SENT) {
                return [
                    'outcome' => self::DELIVERY_OUTCOME_ALREADY_SENT,
                    'reminder' => $locked,
                ];
            }

            if ($locked->status === SubscriptionReminder::STATUS_FAILED) {
                return [
                    'outcome' => self::DELIVERY_OUTCOME_ALREADY_FAILED,
                    'reminder' => $locked,
                ];
            }

            if ($locked->status !== SubscriptionReminder::STATUS_DETECTED) {
                throw new SubscriptionReminderNotificationException(
                    'Transition vers failed impossible depuis le statut '.$locked->status.'.',
                );
            }

            $locked->status = SubscriptionReminder::STATUS_FAILED;
            $locked->sent_at = null;
            $locked->save();

            return [
                'outcome' => self::DELIVERY_OUTCOME_FAILED,
                'reminder' => $locked->fresh(),
            ];
        });
    }

    private function normalizeInstant(Carbon $instant): Carbon
    {
        return $instant->copy()->utc();
    }

    private function todayUtc(Carbon $now): Carbon
    {
        return $now->copy()->utc()->startOfDay();
    }

    private function periodEndDay(Subscription $subscription): Carbon
    {
        return Carbon::parse($subscription->current_period_end)->utc()->startOfDay();
    }

    private function periodStartDay(Subscription $subscription): Carbon
    {
        return Carbon::parse($subscription->current_period_start)->utc()->startOfDay();
    }

    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     temporal_state: string,
     *     days_remaining: ?int,
     *     matched_threshold: ?int,
     *     reminder_due_today: bool,
     * }
     */
    private function result(
        bool $eligible,
        string $reason,
        string $temporalState,
        ?int $daysRemaining,
        ?int $matchedThreshold,
    ): array {
        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'temporal_state' => $temporalState,
            'days_remaining' => $daysRemaining,
            'matched_threshold' => $matchedThreshold,
            'reminder_due_today' => $eligible && $matchedThreshold !== null,
        ];
    }
}
