<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Health;

/**
 * Allowlist stricte — commandes Gestion en lecture seule pour health (TASK 369).
 */
final class O2SwitchHealthArtisanCommandPolicy
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
    public static function allowedReadonlyArtisanSteps(): array
    {
        $steps = [];

        /** @var list<string> $migrateStatus */
        $migrateStatus = config('provisioning.gestion.migration_status_artisan_argv', []);
        if ($migrateStatus !== []) {
            $steps[] = $migrateStatus;
        }

        /** @var list<string> $rbacStatus */
        $rbacStatus = config('provisioning.gestion.modules_status_artisan_argv', []);
        if ($rbacStatus !== []) {
            $steps[] = $rbacStatus;
        }

        return $steps;
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

        foreach (self::allowedReadonlyArtisanSteps() as $allowed) {
            if ($allowed === $argv) {
                return true;
            }
        }

        return false;
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
