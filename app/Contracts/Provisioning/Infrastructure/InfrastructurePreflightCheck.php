<?php

namespace App\Contracts\Provisioning\Infrastructure;

use App\DTO\Provisioning\InfrastructurePreflightCheckResult;

/**
 * Contrôle de préflight non destructif (TASK 375).
 */
interface InfrastructurePreflightCheck
{
    public function serviceKey(): string;

    public function run(): InfrastructurePreflightCheckResult;
}
