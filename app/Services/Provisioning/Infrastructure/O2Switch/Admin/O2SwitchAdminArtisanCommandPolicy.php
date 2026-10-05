<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Admin;

/**
 * Allowlist stricte — Gestion n'expose pas de commande sûre de création user (TASK 367).
 */
final class O2SwitchAdminArtisanCommandPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_COMMAND_FRAGMENTS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
        'user:delete',
        'user:destroy',
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
        $configured = config('provisioning.gestion.admin_bootstrap_artisan_steps', []);

        return is_array($configured) ? $configured : [];
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
