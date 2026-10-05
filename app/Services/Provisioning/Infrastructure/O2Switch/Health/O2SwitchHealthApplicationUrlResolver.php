<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Health;

use App\DTO\Provisioning\ProvisioningContext;

final class O2SwitchHealthApplicationUrlResolver
{
    /**
     * @return array{
     *     url: string|null,
     *     code: string|null,
     *     message: string|null
     * }
     */
    public static function resolve(ProvisioningContext $context): array
    {
        $explicit = $context->externalReferences['gestion_application_public_base_url'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return [
                'url' => self::normalizeBaseUrl($explicit),
                'code' => null,
                'message' => null,
            ];
        }

        if ($context->domain !== null && trim($context->domain) !== '') {
            return [
                'url' => self::normalizeBaseUrl('https://'.trim($context->domain)),
                'code' => null,
                'message' => null,
            ];
        }

        $baseDomain = trim((string) config('provisioning.o2switch.environment.gestion_app_base_domain', ''));
        if ($baseDomain !== '' && $context->subdomain !== '') {
            return [
                'url' => self::normalizeBaseUrl(
                    'https://'.trim($context->subdomain).'.'.ltrim($baseDomain, '.'),
                ),
                'code' => null,
                'message' => null,
            ];
        }

        return [
            'url' => null,
            'code' => 'o2switch_health_target_url_pending',
            'message' => 'URL publique Gestion indéterminée (gestion_application_public_base_url ou domaine).',
        ];
    }

    private static function normalizeBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}
