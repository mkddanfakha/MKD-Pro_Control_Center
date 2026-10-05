<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Subscription;

/**
 * Indicateurs UI pour les actions admin destructives (le contrôleur reste l'autorité).
 */
final class AdminActionAvailability
{
    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function client(Client $client): array
    {
        if ($client->installations()->exists()) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : des installations sont encore associées à ce client.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @param  array{installations_count?: int|null}  $counts
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function clientFromInstallationsCount(array $counts): array
    {
        $total = (int) ($counts['installations_count'] ?? 0);

        if ($total > 0) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : des installations sont encore associées à ce client.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function installation(Installation $installation): array
    {
        if ($installation->subscriptions()->exists()) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : un abonnement est encore associé à cette installation.',
            ];
        }

        if ($installation->installationModules()->exists()) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : des modules sont encore affectés à cette installation.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function installationFromExistsFlags(
        bool $hasSubscriptions,
        bool $hasInstallationModules,
    ): array {
        if ($hasSubscriptions) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : un abonnement est encore associé à cette installation.',
            ];
        }

        if ($hasInstallationModules) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : des modules sont encore affectés à cette installation.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function payment(Payment $payment): array
    {
        if ($payment->consumptions()->exists()) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : le crédit de ce paiement a déjà été consommé.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function paymentFromConsumptionsCount(int $consumptionsCount): array
    {
        if ($consumptionsCount > 0) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : le crédit de ce paiement a déjà été consommé.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function subscription(Subscription $subscription): array
    {
        if ($subscription->offerSnapshot()->exists() || $subscription->payments()->exists()) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : cette souscription possède un historique commercial ou des paiements associés.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function subscriptionFromExistsFlags(
        bool $hasOfferSnapshot,
        bool $hasPayments,
    ): array {
        if ($hasOfferSnapshot || $hasPayments) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : cette souscription possède un historique commercial ou des paiements associés.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function module(Module $module): array
    {
        if ($module->installationModules()->exists()) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : ce module est encore affecté à une ou plusieurs installations.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: string|null}
     */
    public static function moduleFromAssignmentsCount(int $installationModulesCount): array
    {
        if ($installationModulesCount > 0) {
            return [
                'can_delete' => false,
                'delete_unavailable_reason' => 'Suppression indisponible : ce module est encore affecté à une ou plusieurs installations.',
            ];
        }

        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{can_delete: bool, delete_unavailable_reason: null}
     */
    public static function installationModule(): array
    {
        return [
            'can_delete' => true,
            'delete_unavailable_reason' => null,
        ];
    }

    /**
     * @param  array{can_delete: bool, delete_unavailable_reason: string|null}  $availability
     * @return array<string, mixed>
     */
    public static function mergeIntoAdminUrls(array $availability, array $urls = []): array
    {
        return array_merge($urls, $availability);
    }
}
