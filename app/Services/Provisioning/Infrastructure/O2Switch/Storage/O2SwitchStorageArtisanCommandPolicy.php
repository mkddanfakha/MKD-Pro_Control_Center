<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Storage;

/**
 * Interdit les commandes de cache/optimize (TASK 366) dans le plan storage.
 */
final class O2SwitchStorageArtisanCommandPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_ARTISAN_FRAGMENTS = [
        'optimize',
        'config:cache',
        'route:cache',
        'view:cache',
        'event:cache',
    ];

    /**
     * @param  list<string>  $argv
     */
    public static function containsForbiddenCacheOrOptimizeCommand(array $argv): bool
    {
        $joined = strtolower(implode(' ', $argv));

        foreach (self::FORBIDDEN_ARTISAN_FRAGMENTS as $fragment) {
            if (str_contains($joined, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $argv
     */
    public static function isStorageLinkArgv(array $argv): bool
    {
        return $argv === ['php', 'artisan', 'storage:link'];
    }
}
