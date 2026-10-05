<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

final class O2SwitchGestionEnvBuildResult
{
    public function __construct(
        public readonly O2SwitchGestionEnvBuilder $builder,
        public readonly string $envFileAbsolutePath,
        public readonly string $appUrl,
    ) {}

    public function fingerprint(): string
    {
        return $this->builder->nonSensitiveFingerprint();
    }
}
