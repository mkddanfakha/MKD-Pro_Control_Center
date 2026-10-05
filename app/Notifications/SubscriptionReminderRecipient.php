<?php

namespace App\Notifications;

/**
 * Destinataire logique d’un rappel d’échéance (Task 283).
 *
 * Le gestionnaire central MKD-Pro n’est pas encore modélisé comme entité User dédiée :
 * routage abstrait via {@see TYPE_CENTRAL_ADMIN} sans recipient_id.
 */
readonly class SubscriptionReminderRecipient
{
    public const TYPE_CENTRAL_ADMIN = 'central_admin';

    public function __construct(
        public string $type,
        public ?int $id = null,
    ) {}

    /**
     * @return array{type: string, id: ?int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
        ];
    }
}
