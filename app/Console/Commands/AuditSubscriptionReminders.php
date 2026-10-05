<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Détection et persistance idempotente des rappels d’échéance (Tasks 281–282).
 */
#[Signature('subscriptions:reminder-audit {--installation=} {--subscription=} {--all} {--dry-run} {--json}')]
#[Description('Audit des rappels d’échéance (persistance idempotente si enabled, sans envoi)')]
class AuditSubscriptionReminders extends Command
{
    public function __construct(
        private readonly SubscriptionReminderService $reminderService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $includeAll = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');
        $persist = $this->reminderService->isEnabled() && ! $dryRun;
        $rows = [];
        $persistence = [
            'recorded' => 0,
            'already_recorded' => 0,
            'errors' => 0,
        ];

        $this->buildQuery($includeAll)
            ->with(['installation:id,name'])
            ->orderBy('subscriptions.id')
            ->chunkById(200, function ($subscriptions) use ($now, $persist, &$rows, &$persistence): void {
                foreach ($subscriptions as $subscription) {
                    $rows[] = $this->analyzeSubscription($subscription, $now, $persist, $persistence);
                }
            });

        $summary = $this->buildSummary($rows, $persistence, $dryRun);
        $displayRows = $includeAll
            ? $rows
            : array_values(array_filter($rows, fn (array $row): bool => $row['eligible']));

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'environment' => (string) config('app.env'),
                'reminders_enabled' => $this->reminderService->isEnabled(),
                'dry_run' => $dryRun,
                'today' => $this->todayLabel($now),
                'evaluated' => $summary['evaluated'],
                'due_soon' => $summary['due_soon'],
                'recorded' => $summary['recorded'],
                'already_recorded' => $summary['already_recorded'],
                'errors' => $summary['errors'],
                'subscriptions' => $displayRows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderTextHeader($now, $summary, $dryRun);
        $this->renderRows($displayRows, $includeAll, $dryRun);
        $this->renderPersistenceSummary($summary, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return Builder<Subscription>
     */
    private function buildQuery(bool $includeAll): Builder
    {
        $query = $this->reminderService->querySubscriptionsForReminderAudit($includeAll);

        if ($this->option('subscription') !== null && $this->option('subscription') !== '') {
            $query->whereKey((int) $this->option('subscription'));
        }

        if ($this->option('installation') !== null && $this->option('installation') !== '') {
            $query->where('installation_id', (int) $this->option('installation'));
        }

        return $query;
    }

    /**
     * @param  array{recorded: int, already_recorded: int, errors: int}  $persistence
     * @return array<string, mixed>
     */
    private function analyzeSubscription(
        Subscription $subscription,
        Carbon $now,
        bool $persist,
        array &$persistence,
    ): array {
        $assessment = $this->reminderService->assessReminder($subscription, $now);

        $row = [
            'subscription_id' => $subscription->id,
            'installation_id' => $subscription->installation_id,
            'installation_name' => $subscription->installation?->name,
            'status' => (string) $subscription->status,
            'current_period_end' => $subscription->current_period_end?->format('Y-m-d H:i:s'),
            'temporal_state' => $assessment['temporal_state'],
            'days_remaining' => $assessment['days_remaining'],
            'matched_threshold' => $assessment['matched_threshold'],
            'eligible' => $assessment['eligible'],
            'reason' => $assessment['reason'],
            'reminder_due_today' => $assessment['reminder_due_today'],
            'persistence_outcome' => null,
            'reminder_id' => null,
        ];

        if (! $assessment['eligible'] || $assessment['matched_threshold'] === null) {
            return $row;
        }

        if (! $persist) {
            $row['persistence_outcome'] = $this->reminderService->isEnabled() ? 'would_record' : null;

            return $row;
        }

        try {
            $result = $this->reminderService->recordDetectedReminder(
                $subscription,
                (int) $assessment['matched_threshold'],
                $now,
            );

            $row['reminder_id'] = $result['reminder']->id;
            $row['persistence_outcome'] = $result['outcome'];

            if ($result['outcome'] === 'recorded') {
                $persistence['recorded']++;
            } else {
                $persistence['already_recorded']++;
            }
        } catch (Throwable) {
            $persistence['errors']++;
            $row['persistence_outcome'] = 'error';
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{recorded: int, already_recorded: int, errors: int}  $persistence
     * @return array{
     *     evaluated: int,
     *     due_soon: int,
     *     recorded: int,
     *     already_recorded: int,
     *     errors: int,
     *     would_record: int,
     * }
     */
    private function buildSummary(array $rows, array $persistence, bool $dryRun): array
    {
        $dueSoon = 0;
        $wouldRecord = 0;

        foreach ($rows as $row) {
            if ($row['eligible']) {
                $dueSoon++;
            }

            if ($dryRun && $row['persistence_outcome'] === 'would_record') {
                $wouldRecord++;
            }
        }

        return [
            'evaluated' => count($rows),
            'due_soon' => $dueSoon,
            'recorded' => $persistence['recorded'],
            'already_recorded' => $persistence['already_recorded'],
            'errors' => $persistence['errors'],
            'would_record' => $wouldRecord,
        ];
    }

    /**
     * @param  array{
     *     evaluated: int,
     *     due_soon: int,
     *     recorded: int,
     *     already_recorded: int,
     *     errors: int,
     *     would_record: int,
     * }  $summary
     */
    private function renderTextHeader(Carbon $now, array $summary, bool $dryRun): void
    {
        $enabled = $this->reminderService->isEnabled();

        $this->line('Rappels d’échéance des abonnements');
        $this->line('-----------------------------------');
        $this->line('Automatic reminders : '.($enabled ? 'ENABLED' : 'DISABLED'));
        if ($dryRun) {
            $this->line('Dry-run             : YES (aucune persistance)');
        }
        $this->line('Today               : '.$this->todayLabel($now));
        $this->line('Evaluated           : '.$summary['evaluated']);
        $this->line('Due soon            : '.$summary['due_soon']);
        $this->newLine();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderRows(array $rows, bool $includeAll, bool $dryRun): void
    {
        foreach ($rows as $row) {
            if ($includeAll && ! $row['eligible']) {
                $this->line(sprintf(
                    '#%d  installation=%d  eligible=false reason=%s',
                    $row['subscription_id'],
                    $row['installation_id'],
                    $row['reason'],
                ));

                continue;
            }

            $suffix = '';
            if ($row['persistence_outcome'] === 'would_record') {
                $suffix = '  [would_record]';
            } elseif ($row['persistence_outcome'] === 'recorded') {
                $suffix = '  [recorded]';
            } elseif ($row['persistence_outcome'] === 'already_recorded') {
                $suffix = '  [already_recorded]';
            }

            $this->line(sprintf(
                '#%d  installation=%d  end=%s  days=%s  reminder=%s%s',
                $row['subscription_id'],
                $row['installation_id'],
                $row['current_period_end'] ?? '?',
                $row['days_remaining'] ?? '-',
                $row['matched_threshold'] ?? '-',
                $suffix,
            ));
        }

        if ($rows === []) {
            $this->line('(aucun abonnement dans le périmètre affiché)');
        }

        $this->newLine();
    }

    /**
     * @param  array{
     *     evaluated: int,
     *     due_soon: int,
     *     recorded: int,
     *     already_recorded: int,
     *     errors: int,
     *     would_record: int,
     * }  $summary
     */
    private function renderPersistenceSummary(array $summary, bool $dryRun): void
    {
        if (! $this->reminderService->isEnabled()) {
            return;
        }

        if ($dryRun) {
            $this->line('Would record        : '.$summary['would_record']);

            return;
        }

        $this->line('Detected            : '.$summary['recorded']);
        $this->line('Already recorded    : '.$summary['already_recorded']);
        $this->line('Errors              : '.$summary['errors']);
    }

    private function todayLabel(Carbon $now): string
    {
        return $now->copy()->utc()->format('Y-m-d');
    }
}
