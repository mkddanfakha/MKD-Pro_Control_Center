<?php

namespace App\Services\Provisioning\Gestion;

/**
 * Lecture seule du catalogue technique Gestion (config versionnée — TASK 368).
 *
 * Ne confond pas avec le catalogue commercial Control Center (models Module / InstallationModule).
 */
final class GestionModulesCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function entries(): array
    {
        /** @var array<string, array<string, mixed>> $catalog */
        $catalog = config('provisioning.gestion.modules_catalog', []);

        return is_array($catalog) ? $catalog : [];
    }

    /**
     * @return list<string>
     */
    public static function knownModuleIds(): array
    {
        return array_keys(self::entries());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $moduleId): ?array
    {
        $entries = self::entries();

        return $entries[$moduleId] ?? null;
    }

    public static function exists(string $moduleId): bool
    {
        return self::find($moduleId) !== null;
    }

    public static function isActivatableViaProvisioning(string $moduleId): bool
    {
        $entry = self::find($moduleId);

        return $entry !== null && ($entry['activatable_via_provisioning'] ?? false) === true;
    }

    public static function acceptsNoopRequest(string $moduleId): bool
    {
        $entry = self::find($moduleId);

        return $entry !== null && ($entry['accept_as_noop_request'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    public static function dependsOnExternalRefs(string $moduleId): array
    {
        $entry = self::find($moduleId);

        if ($entry === null) {
            return [];
        }

        /** @var mixed $deps */
        $deps = $entry['depends_on_external_refs'] ?? [];

        if (! is_array($deps)) {
            return [];
        }

        return array_values(array_filter($deps, is_string(...)));
    }

    public static function classification(string $moduleId): ?string
    {
        $entry = self::find($moduleId);

        if ($entry === null) {
            return null;
        }

        $classification = $entry['classification'] ?? null;

        return is_string($classification) ? $classification : null;
    }
}
