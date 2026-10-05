<?php

namespace App\DTO\Provisioning;

/**
 * Classification documentaire des erreurs provisioning (sans appel externe).
 */
enum ProvisioningErrorCategory: string
{
    case Retryable = 'retryable';

    case Definitive = 'definitive';

    case ManualInterventionRequired = 'manual_intervention_required';
}
