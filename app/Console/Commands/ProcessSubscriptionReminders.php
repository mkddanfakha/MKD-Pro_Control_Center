<?php

namespace App\Console\Commands;

use App\Exceptions\Subscription\SubscriptionReminderNotificationException;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Notifications\Channels\EmailSubscriptionReminderChannel;
use App\Notifications\Channels\PreparedSubscriptionReminderChannel;
use App\Notifications\SubscriptionReminderSendResult;
use App\Services\SubscriptionReminderNotificationSender;
use App\Services\SubscriptionReminderService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestration quotidienne : détection, persistance idempotente, envoi optionnel (Task 287).
 */
#[Signature('subscriptions:process-reminders {--installation=} {--subscription=} {--dry-run} {--json}')]
#[Description('Traite les rappels d’échéance (détection, persistance, notification si activée)')]
class ProcessSubscriptionReminders extends Command
{
    public function __construct(
        private readonly SubscriptionReminderService $reminderService,
        private readonly SubscriptionReminderNotificationSender $notificationSender,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $remindersEnabled = $this->reminderService->isEnabled();
        $notificationsEnabled = $this->notificationSender->isEnabled();

        $stats = [
            'enabled' => $remindersEnabled,
            'notifications_enabled' => $notificationsEnabled,
            'dry_run' => $dryRun,
            'evaluated' => 0,
            'newly_detected' => 0,
            'already_recorded' => 0,
            'sent' => 0,
            'failed' => 0,
            'ignored' => 0,
            'errors' => 0,
        ];

        $sendResults = [];

        if (! $remindersEnabled && ! $dryRun) {
            $stats['duration_ms'] = $this->durationMs($startedAt);
            $this->renderOutput($stats, $sendResults);

            return self::SUCCESS;
        }

        if ($dryRun && ! $remindersEnabled) {
            $stats['duration_ms'] = $this->durationMs($startedAt);
            $this->renderOutput($stats, $sendResults);

            return self::SUCCESS;
        }

        $now = now();

        $this->buildQuery()
            ->orderBy('subscriptions.id')
            ->chunkById(200, function ($subscriptions) use (
                $now,
                $dryRun,
                $notificationsEnabled,
                &$stats,
                &$sendResults,
            ): void {
                foreach ($subscriptions as $subscription) {
                    $stats['evaluated']++;
                    $this->processSubscription(
                        $subscription,
                        $now,
                        $dryRun,
                        $notificationsEnabled,
                        $stats,
                        $sendResults,
                    );
                }
            });

        $stats['duration_ms'] = $this->durationMs($startedAt);

        Log::info('subscription_reminders.process.completed', [
            'enabled' => $stats['enabled'],
            'notifications_enabled' => $stats['notifications_enabled'],
            'dry_run' => $stats['dry_run'],
            'evaluated' => $stats['evaluated'],
            'newly_detected' => $stats['newly_detected'],
            'already_recorded' => $stats['already_recorded'],
            'sent' => $stats['sent'],
            'failed' => $stats['failed'],
            'ignored' => $stats['ignored'],
            'errors' => $stats['errors'],
            'duration_ms' => $stats['duration_ms'],
        ]);

        $this->renderOutput($stats, $sendResults);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int|bool>  $stats
     * @param  list<array<string, mixed>>  $sendResults
     */
    private function processSubscription(
        Subscription $subscription,
        Carbon $now,
        bool $dryRun,
        bool $notificationsEnabled,
        array &$stats,
        array &$sendResults,
    ): void {
        $assessment = $this->reminderService->assessReminder($subscription, $now);

        if (! $assessment['eligible'] || $assessment['matched_threshold'] === null) {
            return;
        }

        $threshold = (int) $assessment['matched_threshold'];

        if ($dryRun) {
            $this->processDryRun($subscription, $threshold, $notificationsEnabled, $stats);

            return;
        }

        try {
            $record = $this->reminderService->recordDetectedReminder($subscription, $threshold, $now);
        } catch (Throwable $exception) {
            $stats['errors']++;
            $this->logProcessingError($subscription, null, $exception);

            return;
        }

        if ($record['created']) {
            $stats['newly_detected']++;
            $this->dispatchNotificationIfEnabled(
                $record['reminder'],
                $subscription,
                $notificationsEnabled,
                $stats,
                $sendResults,
            );

            return;
        }

        $stats['already_recorded']++;
        $stats['ignored']++;
    }

    /**
     * @param  array<string, int|bool>  $stats
     */
    private function processDryRun(
        Subscription $subscription,
        int $threshold,
        bool $notificationsEnabled,
        array &$stats,
    ): void {
        if ($this->existingReminder($subscription, $threshold) !== null) {
            $stats['already_recorded']++;
            $stats['ignored']++;

            return;
        }

        $stats['newly_detected']++;
    }

    /**
     * @param  array<string, int|bool>  $stats
     * @param  list<array<string, mixed>>  $sendResults
     */
    private function dispatchNotificationIfEnabled(
        SubscriptionReminder $reminder,
        Subscription $subscription,
        bool $notificationsEnabled,
        array &$stats,
        array &$sendResults,
    ): void {
        if (! $notificationsEnabled) {
            return;
        }

        try {
            $result = $this->notificationSender->send($reminder);
        } catch (SubscriptionReminderNotificationException $exception) {
            $stats['errors']++;
            $this->logProcessingError($subscription, $reminder, $exception);

            return;
        } catch (Throwable $exception) {
            $stats['errors']++;
            $this->logProcessingError($subscription, $reminder, $exception);

            return;
        }

        $sendResults[] = [
            'reminder_id' => $reminder->id,
            'subscription_id' => $subscription->id,
            'installation_id' => $subscription->installation_id,
            'status' => $result->status,
            'channel' => $result->channel,
        ];

        if ($result->status === SubscriptionReminderSendResult::STATUS_SENT) {
            $stats['sent']++;
        } elseif ($result->status === SubscriptionReminderSendResult::STATUS_FAILED) {
            $stats['failed']++;
        } elseif ($result->status === SubscriptionReminderSendResult::STATUS_ERROR) {
            $stats['errors']++;
        }
    }

    private function existingReminder(Subscription $subscription, int $threshold): ?SubscriptionReminder
    {
        $scheduledFor = $this->reminderService->resolveScheduledFor($subscription, $threshold);

        return SubscriptionReminder::query()->where([
            'subscription_id' => $subscription->id,
            'reminder_type' => SubscriptionReminder::TYPE_SUBSCRIPTION_EXPIRY,
            'threshold_days' => $threshold,
            'scheduled_for' => $scheduledFor->format('Y-m-d H:i:s'),
        ])->first();
    }

    /**
     * @return Builder<Subscription>
     */
    private function buildQuery(): Builder
    {
        $query = $this->reminderService->querySubscriptionsForReminderAudit(false);

        if ($this->option('subscription') !== null && $this->option('subscription') !== '') {
            $query->whereKey((int) $this->option('subscription'));
        }

        if ($this->option('installation') !== null && $this->option('installation') !== '') {
            $query->where('installation_id', (int) $this->option('installation'));
        }

        return $query;
    }

    private function logProcessingError(
        Subscription $subscription,
        ?SubscriptionReminder $reminder,
        Throwable $exception,
    ): void {
        Log::error('subscription_reminders.process.error', [
            'reminder_id' => $reminder?->id,
            'subscription_id' => $subscription->id,
            'installation_id' => $subscription->installation_id,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<string, int|bool>  $stats
     * @param  list<array<string, mixed>>  $sendResults
     */
    private function renderOutput(array $stats, array $sendResults): void
    {
        if ((bool) $this->option('json')) {
            $payload = $stats;
            if ($sendResults !== []) {
                $payload['send_results'] = $sendResults;
            }
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('Traitement des rappels d’abonnement');
        $this->line('-----------------------------------');
        $this->line('Rappels (détection) : '.($stats['enabled'] ? 'ACTIVÉS' : 'DÉSACTIVÉS'));
        $this->line('Notifications         : '.($stats['notifications_enabled'] ? 'ACTIVÉES' : 'DÉSACTIVÉES'));
        if ($stats['dry_run']) {
            $this->line('Dry-run               : OUI (aucune écriture ni envoi)');
        }
        $this->newLine();
        $this->line('Évalués               : '.$stats['evaluated']);
        $this->line('Nouveaux rappels      : '.$stats['newly_detected']);
        $this->line('Déjà enregistrés      : '.$stats['already_recorded']);
        $this->line('Envoyés               : '.$stats['sent']);
        $this->line('Échecs                : '.$stats['failed']);
        $this->line('Ignorés               : '.$stats['ignored']);
        $this->line('Erreurs techniques    : '.$stats['errors']);
        $this->line('Durée                 : '.$stats['duration_ms'].' ms');

        if ($stats['dry_run'] && $stats['enabled']) {
            $this->newLine();
            $this->line($this->dryRunNotificationHint());
        }
    }

    private function dryRunNotificationHint(): string
    {
        if (! $this->notificationSender->isEnabled()) {
            return 'Notification (simulation) : désactivée';
        }

        $channel = $this->notificationSender->configuredChannelName();

        if ($channel === EmailSubscriptionReminderChannel::CHANNEL_NAME) {
            $emailConfigured = config('subscriptions.subscription_reminder_notifications.central_admin_email');

            if (! is_string($emailConfigured) || trim($emailConfigured) === '') {
                return 'Notification (simulation) : email → échec probable (central_admin non configuré)';
            }

            return 'Notification (simulation) : email → serait envoyée à central_admin';
        }

        if ($channel === PreparedSubscriptionReminderChannel::CHANNEL_NAME) {
            return 'Notification (simulation) : prepared → serait marquée envoyée (sans email externe)';
        }

        return 'Notification (simulation) : canal '.$channel;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
