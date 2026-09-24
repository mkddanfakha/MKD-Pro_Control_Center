<?php

namespace App\Services;

use App\Models\User;

class UserSecurityFailureAuditor
{
    /**
     * @var array<string, string>
     */
    private const GENERIC_MESSAGES = [
        'user.profile_update_failed' => 'La mise à jour du profil a échoué.',
        'user.password_update_failed' => 'Le changement de mot de passe a échoué.',
        'user.password_reset_request_failed' => 'La demande de réinitialisation du mot de passe a échoué.',
        'user.password_reset_failed' => 'La réinitialisation du mot de passe a échoué.',
        'user.two_factor_confirmation_failed' => 'La confirmation de l’authentification à deux facteurs a échoué.',
        'auth.two_factor_failed' => 'La vérification en deux étapes a échoué.',
        'user.passkey_login_failed' => 'La connexion par passkey a échoué.',
        'user.passkey_confirmation_failed' => 'La confirmation par passkey a échoué.',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(string $action, ?User $auditable = null, ?array $newValues = null): void
    {
        $this->auditLogService->record(
            $action,
            auditable: $auditable,
            newValues: $newValues,
            result: 'failure',
            errorMessage: self::GENERIC_MESSAGES[$action] ?? 'L’opération de sécurité a échoué.',
        );
    }

    /**
     * @return array{email: string}|null
     */
    public function emailContext(?string $email): ?array
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return ['email' => strtolower(trim($email))];
    }

    public function resolveUserByEmail(?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return User::query()->where('email', strtolower(trim($email)))->first();
    }
}
