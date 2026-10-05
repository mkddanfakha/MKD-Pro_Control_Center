<?php

namespace App\Services;

use App\Contracts\SubscriptionReminderNotificationChannel;
use App\Exceptions\Subscription\SubscriptionReminderNotificationException;
use App\Models\SubscriptionReminder;
use App\Notifications\Channels\EmailSubscriptionReminderChannel;
use App\Notifications\Channels\PreparedSubscriptionReminderChannel;
use App\Notifications\SubscriptionReminderNotificationData;
use App\Notifications\SubscriptionReminderSendResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestration d’envoi de rappel via canal configuré (Tasks 284–285).
 */
class SubscriptionReminderNotificationSender
{
    public function __construct(
        private readonly SubscriptionReminderNotificationComposer $composer,
        private readonly SubscriptionReminderService $reminderService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('subscriptions.subscription_reminder_notifications.enabled', false);
    }

    public function configuredChannelName(): string
    {
        return (string) config('subscriptions.subscription_reminder_notifications.channel', 'prepared');
    }

    public function send(SubscriptionReminder $reminder, ?string $channelOverride = null): SubscriptionReminderSendResult
    {
        $channelName = $channelOverride !== null && $channelOverride !== ''
            ? $channelOverride
            : $this->configuredChannelName();

        if (! $this->isEnabled()) {
            return new SubscriptionReminderSendResult(
                status: SubscriptionReminderSendResult::STATUS_DISABLED,
                channel: $channelName,
                recipientType: '',
                recipientId: null,
                message: 'Envoi de notifications de rappel désactivé (subscription_reminder_notifications.enabled=false).',
            );
        }

        $reminder->refresh();

        if ($reminder->status === SubscriptionReminder::STATUS_SENT) {
            return $this->resultForTerminalState(
                SubscriptionReminderSendResult::STATUS_ALREADY_SENT,
                $channelName,
                $reminder,
                'Rappel déjà marqué comme envoyé.',
            );
        }

        if ($reminder->status === SubscriptionReminder::STATUS_FAILED) {
            return $this->resultForTerminalState(
                SubscriptionReminderSendResult::STATUS_ALREADY_FAILED,
                $channelName,
                $reminder,
                'Rappel en échec — aucune nouvelle tentative automatique.',
            );
        }

        if ($reminder->status !== SubscriptionReminder::STATUS_DETECTED) {
            throw new SubscriptionReminderNotificationException(
                'Statut de rappel non supporté pour l’envoi : '.$reminder->status,
            );
        }

        try {
            return DB::transaction(function () use ($reminder, $channelName): SubscriptionReminderSendResult {
                $locked = SubscriptionReminder::query()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === SubscriptionReminder::STATUS_SENT) {
                    return $this->resultForTerminalState(
                        SubscriptionReminderSendResult::STATUS_ALREADY_SENT,
                        $channelName,
                        $locked,
                        'Rappel déjà marqué comme envoyé.',
                    );
                }

                if ($locked->status === SubscriptionReminder::STATUS_FAILED) {
                    return $this->resultForTerminalState(
                        SubscriptionReminderSendResult::STATUS_ALREADY_FAILED,
                        $channelName,
                        $locked,
                        'Rappel en échec — aucune nouvelle tentative automatique.',
                    );
                }

                if ($locked->status !== SubscriptionReminder::STATUS_DETECTED) {
                    throw new SubscriptionReminderNotificationException(
                        'Statut de rappel non supporté pour l’envoi : '.$locked->status,
                    );
                }

                $data = $this->composer->compose($locked);
                $channelResult = $this->resolveChannel($channelName)->send($data);

                return $this->finalizeAfterChannel($locked, $channelName, $channelResult, $data);
            });
        } catch (SubscriptionReminderNotificationException $exception) {
            Log::error('subscription_reminder.delivery.error', [
                'reminder_id' => $reminder->id,
                'subscription_id' => $reminder->subscription_id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('subscription_reminder.delivery.error', [
                'reminder_id' => $reminder->id,
                'subscription_id' => $reminder->subscription_id,
                'message' => $exception->getMessage(),
            ]);

            $delivery = $this->reminderService->markAsFailed($reminder, $exception->getMessage());

            return $this->mapDeliveryOutcome(
                $delivery,
                $channelName,
                '',
                null,
                $exception->getMessage(),
                null,
            );
        }
    }

    private function finalizeAfterChannel(
        SubscriptionReminder $locked,
        string $channelName,
        SubscriptionReminderSendResult $channelResult,
        SubscriptionReminderNotificationData $data,
    ): SubscriptionReminderSendResult {
        if ($channelResult->status === SubscriptionReminderSendResult::STATUS_PREPARED) {
            $delivery = $this->reminderService->markAsSent($locked);

            return $this->mapDeliveryOutcome(
                $delivery,
                $channelName,
                $data->recipient->type,
                $data->recipient->id,
                $channelResult->message,
                $channelResult->recipientAddress,
            );
        }

        if (in_array($channelResult->status, [
            SubscriptionReminderSendResult::STATUS_UNAVAILABLE,
            SubscriptionReminderSendResult::STATUS_ERROR,
        ], true)) {
            $delivery = $this->reminderService->markAsFailed($locked, $channelResult->message);

            return $this->mapDeliveryOutcome(
                $delivery,
                $channelName,
                $data->recipient->type,
                $data->recipient->id,
                $channelResult->message,
                $channelResult->recipientAddress,
            );
        }

        $delivery = $this->reminderService->markAsFailed(
            $locked,
            'Statut de canal non supporté : '.$channelResult->status,
        );

        return $this->mapDeliveryOutcome(
            $delivery,
            $channelName,
            $data->recipient->type,
            $data->recipient->id,
            'Statut de canal non supporté.',
            $channelResult->recipientAddress,
        );
    }

    /**
     * @param  array{outcome: string, reminder: SubscriptionReminder}  $delivery
     */
    private function mapDeliveryOutcome(
        array $delivery,
        string $channelName,
        string $recipientType,
        ?int $recipientId,
        ?string $message,
        ?string $recipientAddress,
    ): SubscriptionReminderSendResult {
        $globalStatus = match ($delivery['outcome']) {
            SubscriptionReminderService::DELIVERY_OUTCOME_SENT => SubscriptionReminderSendResult::STATUS_SENT,
            SubscriptionReminderService::DELIVERY_OUTCOME_ALREADY_SENT => SubscriptionReminderSendResult::STATUS_ALREADY_SENT,
            SubscriptionReminderService::DELIVERY_OUTCOME_ALREADY_FAILED => SubscriptionReminderSendResult::STATUS_ALREADY_FAILED,
            SubscriptionReminderService::DELIVERY_OUTCOME_FAILED => SubscriptionReminderSendResult::STATUS_FAILED,
            default => SubscriptionReminderSendResult::STATUS_ERROR,
        };

        return new SubscriptionReminderSendResult(
            status: $globalStatus,
            channel: $channelName,
            recipientType: $recipientType,
            recipientId: $recipientId,
            message: $message,
            recipientAddress: $recipientAddress,
        );
    }

    private function resultForTerminalState(
        string $status,
        string $channelName,
        SubscriptionReminder $reminder,
        string $message,
    ): SubscriptionReminderSendResult {
        Log::info('subscription_reminder.delivery.skipped', [
            'reminder_id' => $reminder->id,
            'subscription_id' => $reminder->subscription_id,
            'result' => $status,
        ]);

        return new SubscriptionReminderSendResult(
            status: $status,
            channel: $channelName,
            recipientType: '',
            recipientId: null,
            message: $message,
        );
    }

    private function resolveChannel(string $channelName): SubscriptionReminderNotificationChannel
    {
        return match ($channelName) {
            PreparedSubscriptionReminderChannel::CHANNEL_NAME => app(PreparedSubscriptionReminderChannel::class),
            EmailSubscriptionReminderChannel::CHANNEL_NAME => app(EmailSubscriptionReminderChannel::class),
            default => throw new SubscriptionReminderNotificationException(
                'Canal de notification de rappel non supporté : '.$channelName,
            ),
        };
    }
}
