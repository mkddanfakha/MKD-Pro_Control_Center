<?php

namespace App\Services\Provisioning\Infrastructure\Preflight;

use App\Contracts\Provisioning\Infrastructure\InfrastructurePreflightCheck;
use App\DTO\Provisioning\InfrastructurePreflightCheckResult;
use App\DTO\Provisioning\InfrastructurePreflightState;

final class O2SwitchSshPreflightCheck implements InfrastructurePreflightCheck
{
    public const SERVICE_KEY = 'o2switch_ssh';

    public function serviceKey(): string
    {
        return self::SERVICE_KEY;
    }

    public function run(): InfrastructurePreflightCheckResult
    {
        return new InfrastructurePreflightCheckResult(
            serviceKey: self::SERVICE_KEY,
            serviceLabel: 'SSH',
            capability: 'remote_shell',
            state: InfrastructurePreflightState::UNSUPPORTED,
            code: 'o2switch_ssh_not_required',
            operatorMessage: 'SSH : aucune gateway provisioning réelle — non requis pour le préflight actuel.',
            provisioningFeatureEnabled: false,
            configuredAndReachable: false,
            diagnostics: ['requirement' => 'not_required'],
        );
    }
}
