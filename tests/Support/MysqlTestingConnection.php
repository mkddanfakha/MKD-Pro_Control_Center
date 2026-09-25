<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

class MysqlTestingConnection
{
    /**
     * Bascule la connexion par défaut vers MySQL à partir du fichier .env du projet.
     */
    public static function applyFromProjectEnv(): bool
    {
        if (! extension_loaded('pdo_mysql')) {
            return false;
        }

        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            return false;
        }

        $variables = self::parseEnvFile($envPath);

        if (($variables['DB_CONNECTION'] ?? '') !== 'mysql') {
            return false;
        }

        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL'] as $key) {
            if (! array_key_exists($key, $variables)) {
                continue;
            }

            $value = $variables[$key];
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $mysqlConfig = array_merge(
            config('database.connections.mysql', []),
            [
                'driver' => 'mysql',
                'url' => $variables['DB_URL'] ?? null,
                'host' => $variables['DB_HOST'] ?? '127.0.0.1',
                'port' => $variables['DB_PORT'] ?? '3306',
                'database' => $variables['DB_DATABASE'] ?? '',
                'username' => $variables['DB_USERNAME'] ?? 'root',
                'password' => $variables['DB_PASSWORD'] ?? '',
            ],
        );

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => $mysqlConfig,
        ]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        try {
            DB::connection('mysql')->getPdo();
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Rétablit la connexion par défaut SQLite (phpunit.xml) sans recréer la connexion sqlite existante.
     */
    public static function restorePhpunitTestingConnection(): void
    {
        foreach ([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        config(['database.default' => 'sqlite']);

        DB::purge('mysql');
        DB::setDefaultConnection('sqlite');
    }

    /**
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        $variables = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $variables[$name] = $value;
        }

        return $variables;
    }
}
