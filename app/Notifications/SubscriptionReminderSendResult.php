<?php

namespace App\Notifications;

/**
 * Résultat d’exécution d’un canal de rappel (Task 284) — sans secrets.
 */
readonly class SubscriptionReminderSendResult
{
    /** Résultat interne du canal `prepared` (Task 284). */
    public const STATUS_PREPARED = 'prepared';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISABLED = 'disabled';

    /** Résultats globaux d’orchestration (Task 285). */
    public const STATUS_SENT = 'sent';

    public const STATUS_ALREADY_SENT = 'already_sent';

    public const STATUS_ALREADY_FAILED = 'already_failed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $status,
        public string $channel,
        public string $recipientType,
        public ?int $recipientId,
        public ?string $message = null,
        public ?string $recipientAddress = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'channel' => $this->channel,
            'recipient' => [
                'type' => $this->recipientType,
                'id' => $this->recipientId,
            ],
            'message' => $this->message,
            'recipient_address' => $this->recipientAddress,
        ];
    }
}
