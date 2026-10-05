<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Admin;

use App\DTO\Provisioning\ProvisioningContext;
use App\Models\ProvisioningRun;

final class O2SwitchAdminBootstrapIdentityResolver
{
    /**
     * @return array{identity: ?O2SwitchAdminBootstrapIdentity, code: ?string, message: ?string}
     */
    public static function resolve(ProvisioningContext $context): array
    {
        if ($context->installation->id !== $context->installationId) {
            return self::invalid('Identifiant installation incohérent dans le contexte.');
        }

        $terminalRunStatuses = [
            ProvisioningRun::STATUS_SUCCEEDED,
            ProvisioningRun::STATUS_CANCELLED,
        ];

        if (in_array($context->provisioningRun->status, $terminalRunStatuses, true)) {
            return self::invalid('Run de provisioning déjà clos — bootstrap admin non éligible.');
        }

        $overrideInstallationId = $context->externalReferences['admin_bootstrap_installation_id']
            ?? $context->externalReferences['target_installation_id']
            ?? null;

        if ($overrideInstallationId !== null && (int) $overrideInstallationId !== $context->installationId) {
            return self::invalid('Référence externe tente de cibler une autre installation.');
        }

        $email = $context->externalReferences['gestion_admin_bootstrap_email'] ?? null;
        if (! is_string($email) || trim($email) === '') {
            return self::invalid('Email administrateur (gestion_admin_bootstrap_email) requis.');
        }

        $emailNormalized = strtolower(trim($email));
        if (! filter_var($emailNormalized, FILTER_VALIDATE_EMAIL)) {
            return self::invalid('Email administrateur invalide.');
        }

        $overrideEmail = $context->externalReferences['admin_email'] ?? null;
        if (is_string($overrideEmail) && trim($overrideEmail) !== ''
            && strtolower(trim($overrideEmail)) !== $emailNormalized) {
            return self::invalid('Référence externe email incohérente.');
        }

        $displayName = $context->externalReferences['gestion_admin_bootstrap_name'] ?? $context->installationName;
        if (! is_string($displayName) || trim($displayName) === '') {
            $displayName = 'Administrateur installation '.$context->installationId;
        }

        $fingerprint = hash('sha256', json_encode([
            'installation_id' => $context->installationId,
            'admin_email' => $emailNormalized,
            'admin_name' => trim($displayName),
        ], JSON_THROW_ON_ERROR));

        return [
            'identity' => new O2SwitchAdminBootstrapIdentity(
                installationId: $context->installationId,
                adminDisplayName: trim($displayName),
                adminEmailNormalized: $emailNormalized,
                identityFingerprint: $fingerprint,
            ),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * @return array{identity: null, code: string, message: string}
     */
    private static function invalid(string $message): array
    {
        return [
            'identity' => null,
            'code' => 'o2switch_admin_identity_invalid',
            'message' => $message,
        ];
    }
}
