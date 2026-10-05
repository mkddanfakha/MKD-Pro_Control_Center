<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agrégations read-only pour le dashboard Control Center (Task 293).
 * Seuils d’échéance alignés sur SubscriptionReminderService (UTC, jours calendaires).
 */
class ControlCenterDashboardStatisticsService
{
    public function __construct(
        private readonly SubscriptionReminderService $reminderService,
    ) {}

    /**
     * @return array{
     *     reference_date_utc: string,
     *     future: int,
     *     due_in_seven_days: int,
     *     due_in_three_days: int,
     *     due_tomorrow: int,
     *     due_today: int,
     *     period_expired: int,
     * }
     */
    public function activeSubscriptionDueStatistics(?Carbon $now = null): array
    {
        $today = $this->referenceTodayUtc($now);
        $todayString = $today->format('Y-m-d');
        $daysRemaining = $this->sqlDaysRemainingUntilPeriodEnd($todayString);

        $row = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('current_period_end')
            ->selectRaw('SUM(CASE WHEN '.$daysRemaining.' > 0 AND '.$daysRemaining.' NOT IN (7, 3, 1, 0) THEN 1 ELSE 0 END) as future_due')
            ->selectRaw('SUM(CASE WHEN '.$daysRemaining.' = 7 THEN 1 ELSE 0 END) as due_in_seven_days')
            ->selectRaw('SUM(CASE WHEN '.$daysRemaining.' = 3 THEN 1 ELSE 0 END) as due_in_three_days')
            ->selectRaw('SUM(CASE WHEN '.$daysRemaining.' = 1 THEN 1 ELSE 0 END) as due_tomorrow')
            ->selectRaw('SUM(CASE WHEN '.$daysRemaining.' = 0 THEN 1 ELSE 0 END) as due_today')
            ->selectRaw('SUM(CASE WHEN '.$daysRemaining.' < 0 THEN 1 ELSE 0 END) as period_expired')
            ->first();

        return [
            'reference_date_utc' => $todayString,
            'future' => (int) ($row->future_due ?? 0),
            'due_in_seven_days' => (int) ($row->due_in_seven_days ?? 0),
            'due_in_three_days' => (int) ($row->due_in_three_days ?? 0),
            'due_tomorrow' => (int) ($row->due_tomorrow ?? 0),
            'due_today' => (int) ($row->due_today ?? 0),
            'period_expired' => (int) ($row->period_expired ?? 0),
        ];
    }

    /**
     * @return array{detected: int, sent: int, failed: int, total: int}
     */
    public function subscriptionReminderStatistics(): array
    {
        $row = SubscriptionReminder::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as detected', [SubscriptionReminder::STATUS_DETECTED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as sent', [SubscriptionReminder::STATUS_SENT])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [SubscriptionReminder::STATUS_FAILED])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'detected' => (int) ($row->detected ?? 0),
            'sent' => (int) ($row->sent ?? 0),
            'failed' => (int) ($row->failed ?? 0),
        ];
    }

    public function referenceTodayUtc(?Carbon $now = null): Carbon
    {
        $instant = ($now ?? now())->copy()->utc();

        return $instant->startOfDay();
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     grace_period: int,
     *     suspended: int,
     *     terminated: int,
     *     reference_date_utc: string,
     *     future: int,
     *     due_in_seven_days: int,
     *     due_in_three_days: int,
     *     due_tomorrow: int,
     *     due_today: int,
     *     period_expired: int,
     * }
     */
    public function subscriptionAdminIndicators(?Carbon $now = null): array
    {
        $statusRow = Subscription::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active', [Subscription::STATUS_ACTIVE])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as grace_period', [Subscription::STATUS_GRACE_PERIOD])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as suspended', [Subscription::STATUS_SUSPENDED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as terminated_count', [Subscription::STATUS_TERMINATED])
            ->first();

        $due = $this->activeSubscriptionDueStatistics($now);

        return [
            'total' => (int) ($statusRow->total ?? 0),
            'active' => (int) ($statusRow->active ?? 0),
            'grace_period' => (int) ($statusRow->grace_period ?? 0),
            'suspended' => (int) ($statusRow->suspended ?? 0),
            'terminated' => (int) ($statusRow->terminated_count ?? 0),
            'reference_date_utc' => $due['reference_date_utc'],
            'future' => $due['future'],
            'due_in_seven_days' => $due['due_in_seven_days'],
            'due_in_three_days' => $due['due_in_three_days'],
            'due_tomorrow' => $due['due_tomorrow'],
            'due_today' => $due['due_today'],
            'period_expired' => $due['period_expired'],
        ];
    }

    /**
     * Jours calendaires UTC entre la date du jour et DATE(current_period_end) (négatif si échu).
     */
    public function calendarDaysUntilPeriodEnd(?DateTimeInterface $periodEnd, ?Carbon $now = null): ?int
    {
        if ($periodEnd === null) {
            return null;
        }

        $today = $this->referenceTodayUtc($now);
        $endDay = Carbon::parse($periodEnd)->utc()->startOfDay();

        return (int) $today->diffInDays($endDay, false);
    }

    /**
     * @return 'future'|'due_7'|'due_3'|'due_1'|'due_0'|'expired'|null
     */
    public function resolvePeriodDueState(?int $calendarDaysUntilPeriodEnd): ?string
    {
        if ($calendarDaysUntilPeriodEnd === null) {
            return null;
        }

        return match (true) {
            $calendarDaysUntilPeriodEnd < 0 => 'expired',
            $calendarDaysUntilPeriodEnd === 0 => 'due_0',
            $calendarDaysUntilPeriodEnd === 1 => 'due_1',
            $calendarDaysUntilPeriodEnd === 3 => 'due_3',
            $calendarDaysUntilPeriodEnd === 7 => 'due_7',
            $calendarDaysUntilPeriodEnd > 0 => 'future',
            default => null,
        };
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    public function applySubscriptionPeriodFilter(Builder $query, string $period, ?Carbon $now = null): void
    {
        $todayString = $this->referenceTodayUtc($now)->format('Y-m-d');
        $daysRemaining = $this->sqlDaysRemainingUntilPeriodEnd($todayString);

        $query->whereNotNull('current_period_end');

        match ($period) {
            'future' => $query
                ->whereRaw($daysRemaining.' > 0')
                ->whereRaw($daysRemaining.' NOT IN (7, 3, 1, 0)'),
            'due_7' => $query->whereRaw($daysRemaining.' = 7'),
            'due_3' => $query->whereRaw($daysRemaining.' = 3'),
            'due_1' => $query->whereRaw($daysRemaining.' = 1'),
            'due_0' => $query->whereRaw($daysRemaining.' = 0'),
            'expired' => $query->whereRaw($daysRemaining.' < 0'),
            default => null,
        };
    }

    /**
     * Expression SQL du nombre de jours calendaires UTC jusqu’à DATE(current_period_end),
     * alignée sur SubscriptionReminderService::daysRemainingUntilPeriodEnd().
     */
    private function sqlDaysRemainingUntilPeriodEnd(string $utcDate): string
    {
        $escaped = str_replace("'", "''", $utcDate);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "cast(julianday(date(current_period_end)) - julianday('{$escaped}') as integer)",
            default => "DATEDIFF(DATE(current_period_end), '{$escaped}')",
        };
    }
}
