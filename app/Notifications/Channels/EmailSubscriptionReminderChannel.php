<?php

namespace App\Notifications\Channels;

use App\Contracts\SubscriptionReminderNotificationChannel;
use App\Mail\SubscriptionReminderMail;
use App\Notifications\SubscriptionReminderNotificationData;
use App\Notifications\SubscriptionReminderSendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Canal email Laravel pour les rappels d’échéance (Task 286).
 */
class EmailSubscriptionReminderChannel implements SubscriptionReminderNotificationChannel
{
    public const CHANNEL_NAME = 'email';

    public const REASON_CENTRAL_ADMIN_EMAIL_NOT_CONFIGURED = 'central_admin_email_not_configured';

    public function send(SubscriptionReminderNotificationData $data): SubscriptionReminderSendResult
    {
        $recipientEmail = $this->centralAdminEmail();

        if ($recipientEmail === null) {
            return new SubscriptionReminderSendResult(
                status: SubscriptionReminderSendResult::STATUS_UNAVAILABLE,
                channel: self::CHANNEL_NAME,
                recipientType: $data->recipient->type,
                recipientId: $data->recipient->id,
                message: self::REASON_CENTRAL_ADMIN_EMAIL_NOT_CONFIGURED,
            );
        }

        try {
            Mail::to($recipientEmail)->send(new SubscriptionReminderMail($data));

            Log::info('subscription_reminder.email.sent', [
                'reminder_id' => $data->reminder_id,
                'subscription_id' => $data->subscription_id,
                'installation_id' => $data->installation_id,
                'recipient_type' => $data->recipient->type,
            ]);

            return new SubscriptionReminderSendResult(
                status: SubscriptionReminderSendResult::STATUS_PREPARED,
                channel: self::CHANNEL_NAME,
                recipientType: $data->recipient->type,
                recipientId: $data->recipient->id,
                message: 'Email transmis au transport Laravel.',
                recipientAddress: $recipientEmail,
            );
        } catch (Throwable $exception) {
            Log::error('subscription_reminder.email.error', [
                'reminder_id' => $data->reminder_id,
                'subscription_id' => $data->subscription_id,
                'installation_id' => $data->installation_id,
                'message' => $exception->getMessage(),
            ]);

            return new SubscriptionReminderSendResult(
                status: SubscriptionReminderSendResult::STATUS_ERROR,
                channel: self::CHANNEL_NAME,
                recipientType: $data->recipient->type,
                recipientId: $data->recipient->id,
                message: 'Échec d’envoi email.',
                recipientAddress: $recipientEmail,
            );
        }
    }

    private function centralAdminEmail(): ?string
    {
        $email = config('subscriptions.subscription_reminder_notifications.central_admin_email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
    }
}
