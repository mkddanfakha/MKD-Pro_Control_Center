<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Deploy;

use App\DTO\Provisioning\ProvisioningContext;

/**
 * Référence Git contrôlée — commit SHA, tag ou branche explicitement fournis (TASK 360).
 *
 * Aucune valeur par défaut implicite type `main` : seul `default_git_ref` configuré peut compléter le run.
 */
final class O2SwitchDeployRevision
{
    public function __construct(
        public readonly string $reference,
        public readonly string $referenceKind,
    ) {}

    public static function resolve(
        ProvisioningContext $context,
        O2SwitchDeployConfiguration $configuration,
    ): ?self {
        if ($context->targetCommit !== null && $context->targetCommit !== '') {
            $commit = strtolower(trim($context->targetCommit));
            if (preg_match('/^[a-f0-9]{40}$/', $commit) === 1) {
                return new self($commit, 'commit');
            }
        }

        if ($context->targetVersion !== null && trim($context->targetVersion) !== '') {
            $version = trim($context->targetVersion);
            if (self::isSafeGitRef($version)) {
                return new self($version, 'version');
            }
        }

        if ($configuration->defaultGitRef !== '') {
            return new self($configuration->defaultGitRef, 'configured_default');
        }

        return null;
    }

    private static function isSafeGitRef(string $ref): bool
    {
        if ($ref === '' || strlen($ref) > 256) {
            return false;
        }

        if (preg_match('/[\0\r\n]/', $ref) === 1) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._\\/\\-]+$/', $ref) === 1;
    }
}
