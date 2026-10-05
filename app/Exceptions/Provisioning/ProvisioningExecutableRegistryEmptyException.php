<?php

namespace App\Exceptions\Provisioning;

use RuntimeException;

/**
 * Aucune implémentation ProvisioningStep enregistrée — exécution persistée refusée.
 */
final class ProvisioningExecutableRegistryEmptyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Exécution refusée : aucune étape provisioning exécutable n\'est enregistrée en production.',
        );
    }
}
