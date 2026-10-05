<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Modules;

use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchModulesModuleSelectionResolver
{
    /**
     * @return array{
     *     module_ids: list<string>|null,
     *     code: string|null,
     *     message: string|null
     * }
     */
    public static function resolve(ProvisioningContext $context): array
    {
        if (! array_key_exists('gestion_modules_requested', $context->externalReferences)) {
            return [
                'module_ids' => [],
                'code' => null,
                'message' => null,
            ];
        }

        $raw = $context->externalReferences['gestion_modules_requested'];

        if ($raw === null) {
            return [
                'module_ids' => [],
                'code' => null,
                'message' => null,
            ];
        }

        if (! is_array($raw)) {
            return [
                'module_ids' => null,
                'code' => 'o2switch_modules_selection_invalid',
                'message' => 'gestion_modules_requested doit être une liste d’identifiants de module.',
            ];
        }

        $moduleIds = [];

        foreach ($raw as $item) {
            if (! is_string($item) || trim($item) === '') {
                return [
                    'module_ids' => null,
                    'code' => 'o2switch_modules_selection_invalid',
                    'message' => 'Chaque entrée de gestion_modules_requested doit être une chaîne non vide.',
                ];
            }

            $moduleIds[] = trim($item);
        }

        $moduleIds = array_values(array_unique($moduleIds));
        sort($moduleIds);

        return [
            'module_ids' => $moduleIds,
            'code' => null,
            'message' => null,
        ];
    }
}
