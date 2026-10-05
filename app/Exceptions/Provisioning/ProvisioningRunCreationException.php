<?php

namespace App\Exceptions\Provisioning;

use RuntimeException;

/**
 * Refus de création d'une demande de provisioning (prérequis ou run existant).
 */
class ProvisioningRunCreationException extends RuntimeException
{
}
