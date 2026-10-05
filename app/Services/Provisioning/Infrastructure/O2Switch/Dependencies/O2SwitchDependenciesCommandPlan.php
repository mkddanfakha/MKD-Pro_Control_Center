<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Dependencies;

/**
 * Plan d'exécution distant — argv fixes sans secret (TASK 362).
 */
final class O2SwitchDependenciesCommandPlan
{
    /**
     * @param  list<string>  $composerArgv
     * @param  list<string>|null  $npmArgv
     */
    public function __construct(
        public readonly string $workingDirectory,
        public readonly array $composerArgv,
        public readonly ?array $npmArgv,
        public readonly string $lockFingerprint,
    ) {}

    /**
     * @return list<string>
     */
    public function safeOperationLabels(): array
    {
        $labels = ['composer_install'];

        if ($this->npmArgv !== null) {
            $labels[] = 'npm_ci';
        }

        return $labels;
    }
}
