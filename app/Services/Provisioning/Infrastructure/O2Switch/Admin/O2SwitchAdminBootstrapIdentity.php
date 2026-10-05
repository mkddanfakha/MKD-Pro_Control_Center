<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Admin;

/**
 * Identité publique du futur administrateur — jamais de mot de passe.
 */
final class O2SwitchAdminBootstrapIdentity
{
    public function __construct(
        public readonly int $installationId,
        public readonly string $adminDisplayName,
        public readonly string $adminEmailNormalized,
        public readonly string $identityFingerprint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function safePublicMetadata(): array
    {
        return [
            'admin_bootstrap_installation_id' => $this->installationId,
            'admin_display_name' => $this->adminDisplayName,
            'admin_email_normalized' => $this->adminEmailNormalized,
            'admin_identity_fingerprint' => $this->identityFingerprint,
        ];
    }
}
