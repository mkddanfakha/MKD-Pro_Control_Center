<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Database;

/**
 * @internal Résolution de nommage — suffixe UAPI cPanel vs nom complet attendu.
 */
final class O2SwitchDatabaseNamingResolution
{
    public function __construct(
        public readonly string $fullDatabaseName,
        public readonly string $cpanelCreateSuffix,
    ) {}
}
