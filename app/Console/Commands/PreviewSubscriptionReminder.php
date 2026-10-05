<?php

namespace App\Console\Commands;

use App\Exceptions\Subscription\SubscriptionReminderNotificationException;
use App\Models\SubscriptionReminder;
use App\Notifications\SubscriptionReminderNotificationData;
use App\Services\SubscriptionReminderNotificationComposer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Aperçu read-only d’une notification de rappel (Task 283) — aucun envoi.
 */
#[Signature('subscriptions:reminder-preview {reminder : ID du SubscriptionReminder} {--json}')]
#[Description('Prévisualise le contenu d’une notification de rappel d’échéance sans envoi')]
class PreviewSubscriptionReminder extends Command
{
    public function __construct(
        private readonly SubscriptionReminderNotificationComposer $composer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reminderId = (int) $this->argument('reminder');

        if ($reminderId <= 0) {
            $this->error('Identifiant de rappel invalide.');

            return self::FAILURE;
        }

        $reminder = SubscriptionReminder::query()->find($reminderId);

        if ($reminder === null) {
            $this->error('Rappel #'.$reminderId.' introuvable.');

            return self::FAILURE;
        }

        try {
            $data = $this->composer->compose($reminder);
        } catch (SubscriptionReminderNotificationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Erreur lors de la composition : '.$exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($data->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->line('Aucune notification n’a été envoyée.');

            return self::SUCCESS;
        }

        $this->renderTextPreview($reminder, $data);

        return self::SUCCESS;
    }

    private function renderTextPreview(SubscriptionReminder $reminder, SubscriptionReminderNotificationData $data): void
    {
        $thresholdLabel = $data->threshold_days === 0
            ? '0 (jour J)'
            : $data->threshold_days.' jours';

        $this->line('Aperçu du rappel');
        $this->line('----------------');
        $this->line('Reminder       : #'.$data->reminder_id.' ('.$reminder->status.')');
        $this->line('Subscription   : #'.$data->subscription_id);
        $this->line('Installation   : '.$data->installation_name);
        $this->line('Type           : '.$data->reminder_type);
        $this->line('Threshold      : '.$thresholdLabel);
        $this->line('Period end     : '.$data->current_period_end.' UTC');
        $this->newLine();
        $this->line('Destinataire   : '.$data->recipient->type);
        $this->newLine();
        $this->line('Titre :');
        $this->line($data->title);
        $this->newLine();
        $this->line('Message :');
        $this->line($data->body);
        $this->newLine();
        $this->line('AUCUNE notification n’a été envoyée.');
    }
}
