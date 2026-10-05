<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Cache;

/**
 * Commandes Artisan de cache autorisées pour Gestion (inspection Laravel 12 — TASK 366).
 */
final class O2SwitchCacheArtisanCommandPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_COMMAND_FRAGMENTS = [
        'optimize:clear',
        'optimize',
        'cache:clear',
        'route:clear',
        'config:clear',
        'view:clear',
        'event:clear',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
        'storage:link',
    ];

    /**
     * @return list<list<string>>
     */
    public static function productionCacheWarmupSteps(): array
    {
        /** @var list<list<string>> $configured */
        $configured = config('provisioning.gestion.cache_warmup_artisan_steps', []);

        return $configured;
    }

    /**
     * @return list<string>
     */
    public static function excludedArtisanCommandNames(): array
    {
        /** @var list<string> $excluded */
        $excluded = config('provisioning.gestion.cache_excluded_artisan_commands', []);

        return is_array($excluded) ? $excluded : [];
    }

    /**
     * @param  list<string>  $argv
     */
    public static function isAllowedProductionStep(array $argv): bool
    {
        if ($argv === [] || ! self::isStandardArtisanInvocation($argv)) {
            return false;
        }

        if (self::containsForbiddenFragment($argv)) {
            return false;
        }

        $commandName = self::extractArtisanCommandName($argv);
        if ($commandName === null) {
            return false;
        }

        if (in_array($commandName, self::excludedArtisanCommandNames(), true)) {
            return false;
        }

        foreach (self::productionCacheWarmupSteps() as $allowedStep) {
            if ($allowedStep === $argv) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<list<string>>  $steps
     */
    public static function assertProductionPlan(array $steps): bool
    {
        if ($steps === []) {
            return false;
        }

        foreach ($steps as $step) {
            if (! self::isAllowedProductionStep($step)) {
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

    /**
     * @param  list<string>  $argv
     */
    private static function extractArtisanCommandName(array $argv): ?string
    {
        if (! self::isStandardArtisanInvocation($argv)) {
            return null;
        }

        return $argv[2];
    }
}
