<?php

namespace App\Services\Provisioning\Gestion;

/**
 * Catalogue technique des contrôles Health Gestion (config versionnée — TASK 369).
 */
final class GestionHealthCheckCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        /** @var list<array<string, mixed>> $definitions */
        $definitions = config('provisioning.gestion.health_check_definitions', []);

        if (! is_array($definitions)) {
            return [];
        }

        usort(
            $definitions,
            static fn (array $left, array $right): int => ((int) ($left['order'] ?? 0))
                <=> ((int) ($right['order'] ?? 0)),
        );

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public static function checkKeysInOrder(): array
    {
        return array_values(array_map(
            static fn (array $definition): string => (string) ($definition['check_key'] ?? ''),
            self::definitions(),
        ));
    }
}
