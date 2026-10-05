<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructureAdapterResult;
use App\DTO\Provisioning\ProvisioningContext;

/**
 * Réservation de capacité pour une installation — étape `reserve` (TASK 373).
 *
 * Sémantique :
 * - **Réservation logique (Control Center)** : engagement qu'une installation possède un slot
 *   de provisioning unique (noms dérivés stables, pas de second run actif). Obligatoire.
 * - **Réservation infrastructurelle (o2switch / futur)** : claim externe optionnel lorsque
 *   une gateway sera branchée ; DNS, base et déploiement restent aux étapes ultérieures.
 *
 * Sortie `outputSummary` en succès (clés canoniques — voir CapacityReservationContract) :
 * - reservation_id, reservation_scope, installation_id, deploy_relative_path
 * - idempotent_replay (bool) si rejeu sans double allocation
 *
 * Aucun secret dans outputSummary / metadata.
 */
interface CapacityReservationAdapter
{
    public function reserve(ProvisioningContext $context): InfrastructureAdapterResult;
}
