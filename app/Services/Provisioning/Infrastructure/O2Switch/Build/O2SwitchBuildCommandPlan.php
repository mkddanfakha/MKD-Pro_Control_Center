<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Build;

final class O2SwitchBuildCommandPlan
{
    /**
     * @param  list<string>  $npmBuildArgv
     * @param  list<string>  $expectedArtifactRelativePaths
     */
    public function __construct(
        public readonly string $workingDirectory,
        public readonly array $npmBuildArgv,
        public readonly array $expectedArtifactRelativePaths,
        public readonly string $buildFingerprint,
    ) {}

    public function safeOperationLabel(): string
    {
        return 'npm_run_build';
    }
}
