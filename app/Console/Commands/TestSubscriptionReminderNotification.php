<?php

namespace App\Console\Commands;

use App\Exceptions\Subscription\SubscriptionReminderNotificationException;
use App\Models\SubscriptionReminder;
use App\Notifications\Channels\EmailSubscriptionReminderChannel;
use App\Notifications\SubscriptionReminderNotificationData;
use App\Notifications\SubscriptionReminderSendResult;
use App\Services\SubscriptionReminderNotificationComposer;
use App\Services\SubscriptionReminderNotificationSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Test du canal de notification de rappel (Tasks 284–286).
 */
#[Signature('subscriptions:reminder-notification-test {reminder : ID du SubscriptionReminder} {--channel=} {--json}')]
#[Description('Teste le canal de notification de rappel (prepared ou email)')]
class TestSubscriptionReminderNotification extends Command
{
    public function __construct(
        private readonly SubscriptionReminderNotificationSender $sender,
        private readonly SubscriptionReminderNotificationComposer $composer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reminderId = (int) $this->argument('reminder');
        $channelOverride = $this->option('channel');
        $channelOverride = is_string($channelOverride) && $channelOverride !== '' ? $channelOverride : null;

        if ($reminderId <= 0) {
            $this->renderError('Identifiant de rappel invalide.', $reminderId);

            return self::FAILURE;
        }

        $reminder = SubscriptionReminder::query()->find($reminderId);

        if ($reminder === null) {
            $this->renderError('Rappel #'.$reminderId.' introuvable.', $reminderId);

            return self::FAILURE;
        }

        $effectiveChannel = $channelOverride ?? $this->sender->configuredChannelName();

        if (! $this->sender->isEnabled()) {
            $result = new SubscriptionReminderSendResult(
                status: SubscriptionReminderSendResult::STATUS_DISABLED,
                channel: $effectiveChannel,
                recipientType: '',
                recipientId: null,
                message: 'Fonctionnalité désactivée — aucune exécution du canal.',
            );

            $this->renderOutput($reminder, null, $result, $effectiveChannel);

            return self::SUCCESS;
        }

        try {
            $result = $this->sender->send($reminder, $channelOverride);
            $reminder->refresh()->loadMissing(['subscription.installation']);
            $data = $result->status === SubscriptionReminderSendResult::STATUS_SENT
                ? $this->composer->compose($reminder)
                : null;
        } catch (SubscriptionReminderNotificationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Erreur lors du test : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->renderOutput($reminder, $data, $result, $effectiveChannel);

        return self::SUCCESS;
    }

    private function renderError(string $message, int $reminderId): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'reminder_id' => $reminderId,
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->error($message);
    }

    private function renderOutput(
        SubscriptionReminder $reminder,
        ?SubscriptionReminderNotificationData $data,
        SubscriptionReminderSendResult $result,
        string $effectiveChannel,
    ): void {
        $externalSent = $effectiveChannel === EmailSubscriptionReminderChannel::CHANNEL_NAME
            && $result->status === SubscriptionReminderSendResult::STATUS_SENT;

        if ((bool) $this->option('json')) {
            $payload = [
                'reminder_id' => $reminder->id,
                'subscription_id' => $reminder->subscription_id,
                'channel' => $result->channel,
                'reminder_status' => $reminder->fresh()->status,
                'sent_at' => $reminder->fresh()->sent_at?->format('Y-m-d H:i:s'),
                'notifications_enabled' => $this->sender->isEnabled(),
                'send_result' => $result->toArray(),
                'title' => $data?->title,
                'external_notification_sent' => $externalSent,
            ];

            if ($data !== null) {
                $payload['notification'] = $data->toArray();
            }

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        $installationName = $reminder->subscription?->installation?->name ?? '?';

        $this->line('Test notification de rappel');
        $this->line('-------------------------');
        $this->line('Reminder       : #'.$reminder->id.' ('.$reminder->fresh()->status.')');
        $this->line('Subscription   : #'.$reminder->subscription_id);
        $this->line('Installation   : '.$installationName);
        $this->line('Canal          : '.$result->channel);
        $this->line('Résultat       : '.$result->status);

        if ($result->recipientType !== '') {
            $this->line('Destinataire   : '.$result->recipientType);
        }

        if ($result->recipientAddress !== null && $result->recipientAddress !== '') {
            $this->line('Email          : '.$result->recipientAddress);
        }

        if ($result->message !== null && $result->message !== '') {
            $this->line('Message        : '.$result->message);
        }

        if ($data !== null) {
            $this->newLine();
            $this->line('Titre :');
            $this->line($data->title);
            $this->newLine();
            $this->line('Contenu :');
            $this->line($data->body);
        }

        $this->newLine();

        if ($externalSent) {
            $this->line('Email transmis via le transport Laravel (canal email).');
        } else {
            $this->line('Aucune notification externe n’a été envoyée.');
        }
    }
}
