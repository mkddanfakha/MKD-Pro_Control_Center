<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Modules;

/**
 * Allowlist stricte — commandes Gestion liées aux modules (TASK 368).
 */
final class O2SwitchModulesArtisanCommandPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_COMMAND_FRAGMENTS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
        'optimize:clear',
        'cache:clear',
        'eval',
        'bash -c',
        'sh -c',
        'php -r',
    ];

    /**
     * @return list<list<string>>
     */
    public static function allowedArtisanSteps(): array
    {
        /** @var list<list<string>> $configured */
        $configured = config('provisioning.gestion.modules_provisioning_artisan_steps', []);

        return is_array($configured) ? $configured : [];
    }

    /**
     * @return list<list<string>>
     */
    public static function statusArtisanStep(): array
    {
        /** @var list<string> $argv */
        $argv = config('provisioning.gestion.modules_status_artisan_argv', []);

        return is_array($argv) ? [$argv] : [];
    }

    /**
     * @return list<list<string>>
     */
    public static function catalogDryRunArtisanStep(): array
    {
        /** @var list<string> $argv */
        $argv = config('provisioning.gestion.modules_dry_run_artisan_argv', []);

        return is_array($argv) ? [$argv] : [];
    }

    /**
     * @param  list<string>  $argv
     */
    public static function isAllowedStep(array $argv): bool
    {
        if ($argv === [] || ! self::isStandardArtisanInvocation($argv)) {
            return false;
        }

        if (self::containsForbiddenFragment($argv)) {
            return false;
        }

        foreach (self::allowedArtisanSteps() as $allowed) {
            if ($allowed === $argv) {
                return true;
            }
        }

        foreach (self::statusArtisanStep() as $allowed) {
            if ($allowed === $argv) {
                return true;
            }
        }

        foreach (self::catalogDryRunArtisanStep() as $allowed) {
            if ($allowed === $argv) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<list<string>>  $steps
     */
    public static function assertPlanArtisanSteps(array $steps): bool
    {
        foreach ($steps as $step) {
            if (! self::isAllowedStep($step)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $argv
     */
    public static function containsForbiddenFragment(array $argv): bool
    {
        $joined = strtolower(implode(' ', $argv));

        foreach (self::FORBIDDEN_COMMAND_FRAGMENTS as $fragment) {
            if (str_contains($joined, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $argv
     */
    private static function isStandardArtisanInvocation(array $argv): bool
    {
        return count($argv) >= 3
            && ($argv[0] ?? '') === 'php'
            && ($argv[1] ?? '') === 'artisan'
            && is_string($argv[2] ?? null)
            && $argv[2] !== '';
    }
}
