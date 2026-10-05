<?php

namespace App\Support;

use App\Support\Provisioning\ProvisioningSecretSanitizer;

/**
 * Filtre centralisé des payloads d'audit provisioning (aucune metadata adaptateur brute).
 */
final class ProvisioningAuditPayloadSanitizer
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function sanitize(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return ProvisioningSecretSanitizer::sanitizeArrayForExposure($payload);
    }
}
