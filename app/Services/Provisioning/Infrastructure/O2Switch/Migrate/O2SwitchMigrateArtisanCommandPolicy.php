<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Migrate;

/**
 * Politique de commandes Artisan Gestion (inspection composer.json / scripts — TASK 364).
 */
final class O2SwitchMigrateArtisanCommandPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_MIGRATE_SUBCOMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    /**
     * @return list<string>
     */
    public static function productionMigrateArgv(): array
    {
        /** @var list<string> $configured */
        $configured = config('provisioning.gestion.migration_artisan_argv', [
            'php',
            'artisan',
            'migrate',
            '--force',
        ]);

        return $configured;
    }

    /**
     * @param  list<string>  $argv
     */
    public static function assertProductionMigrateArgv(array $argv): bool
    {
        if ($argv !== self::productionMigrateArgv()) {
            return false;
        }

        return ! self::containsForbiddenSubcommand($argv);
    }

    /**
     * @param  list<string>  $argv
     */
    public static function containsForbiddenSubcommand(array $argv): bool
    {
        $joined = strtolower(implode(' ', $argv));

        foreach (self::FORBIDDEN_MIGRATE_SUBCOMMANDS as $forbidden) {
            if (str_contains($joined, $forbidden)) {
                return true;
            }
        }

        foreach ($argv as $segment) {
            if (! is_string($segment)) {
                continue;
            }

            $lower = strtolower($segment);
            if (in_array($lower, ['fresh', 'refresh', 'reset', 'wipe'], true)) {
                return true;
            }
        }

        return false;
    }
}
