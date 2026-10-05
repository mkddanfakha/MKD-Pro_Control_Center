<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioning\Infrastructure\InfrastructurePreflightCheck;
use App\DTO\Provisioning\InfrastructurePreflightReport;
use App\Services\Provisioning\Infrastructure\Preflight\CloudflareDnsPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchDatabasePreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchFilemanPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchGitPreflightCheck;
use App\Services\Provisioning\Infrastructure\Preflight\O2SwitchSshPreflightCheck;

/**
 * Orchestrateur de préflight infrastructure non destructif (TASK 375).
 */
final class ProvisioningInfrastructurePreflight
{
    /**
     * @var list<string>
     */
    public const MANDATORY_SERVICE_KEYS = [
        CloudflareDnsPreflightCheck::SERVICE_KEY,
        O2SwitchDatabasePreflightCheck::SERVICE_KEY,
        O2SwitchGitPreflightCheck::SERVICE_KEY,
        O2SwitchFilemanPreflightCheck::SERVICE_KEY,
    ];

    /**
     * @param  iterable<InfrastructurePreflightCheck>  $checks
     */
    public function __construct(
        private readonly iterable $checks,
    ) {}

    public function run(): InfrastructurePreflightReport
    {
        $results = [];

        foreach ($this->checks as $check) {
            $results[] = $check->run();
        }

        return InfrastructurePreflightReport::fromChecks($results, self::MANDATORY_SERVICE_KEYS);
    }
}
