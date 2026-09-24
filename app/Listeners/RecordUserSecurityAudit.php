<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;

class RecordUserSecurityAudit
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handlePasswordUpdatedViaController(PasswordUpdatedViaController $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogService->record(
            'user.password_updated',
            auditable: $event->user,
            newValues: $this->userSnapshot($event->user),
            result: 'success',
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogService->record(
            'user.password_reset',
            auditable: $event->user,
            newValues: $this->userSnapshot($event->user),
            result: 'success',
        );
    }

    public function handleTwoFactorAuthenticationEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user->fresh() ?? $event->user;

        $this->auditLogService->record(
            'user.two_factor_enabled',
            auditable: $user,
            newValues: array_merge($this->userSnapshot($user), [
                'two_factor_confirmation_pending' => Fortify::confirmsTwoFactorAuthentication()
                    && $user->two_factor_confirmed_at === null,
            ]),
            result: 'success',
        );
    }

    public function handleTwoFactorAuthenticationConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user->fresh() ?? $event->user;

        $this->auditLogService->record(
            'user.two_factor_confirmed',
            auditable: $user,
            newValues: array_merge($this->userSnapshot($user), [
                'two_factor_confirmed' => $user->two_factor_confirmed_at !== null,
            ]),
            result: 'success',
        );
    }

    public function handleTwoFactorAuthenticationDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogService->record(
            'user.two_factor_disabled',
            auditable: $event->user,
            newValues: $this->userSnapshot($event->user),
            result: 'success',
        );
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogService->record(
            'user.two_factor_recovery_codes_regenerated',
            auditable: $event->user,
            newValues: $this->userSnapshot($event->user),
            result: 'success',
        );
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogService->record(
            'user.two_factor_recovery_code_used',
            auditable: $event->user,
            newValues: array_merge($this->userSnapshot($event->user), [
                'recovery_code_consumed' => true,
            ]),
            result: 'success',
        );
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        if (! $event->user instanceof User || ! $event->passkey instanceof Passkey) {
            return;
        }

        $this->auditLogService->record(
            'user.passkey_created',
            auditable: $event->user,
            newValues: array_merge($this->userSnapshot($event->user), $this->passkeyMetadata($event->passkey)),
            result: 'success',
        );
    }

    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        if (! $event->user instanceof User || ! $event->passkey instanceof Passkey) {
            return;
        }

        $this->auditLogService->record(
            'user.passkey_deleted',
            auditable: $event->user,
            oldValues: array_merge($this->userSnapshot($event->user), $this->passkeyMetadata($event->passkey)),
            result: 'success',
        );
    }

    public function handlePasskeyVerified(PasskeyVerified $event): void
    {
        if (! $event->user instanceof User || ! $event->passkey instanceof Passkey) {
            return;
        }

        if (! $this->isPasskeyConfirmationContext($event->user)) {
            return;
        }

        $this->auditLogService->record(
            'user.passkey_confirmation',
            auditable: $event->user,
            newValues: array_merge($this->userSnapshot($event->user), $this->passkeyMetadata($event->passkey)),
            result: 'success',
        );
    }

    private function isPasskeyConfirmationContext(User $user): bool
    {
        $guard = Auth::guard(config('fortify.guard', 'web'));

        if (! $guard->check()) {
            return false;
        }

        return (int) $guard->id() === (int) $user->getKey();
    }

    /**
     * @return array{id: int|string|null, name: string|null, email: string|null}
     */
    private function userSnapshot(Authenticatable $user): array
    {
        return [
            'id' => $user->getAuthIdentifier(),
            'name' => $user->getAttribute('name'),
            'email' => $user->getAttribute('email'),
        ];
    }

    /**
     * @return array{passkey_id: int, passkey_name: string}
     */
    private function passkeyMetadata(Passkey $passkey): array
    {
        return [
            'passkey_id' => $passkey->id,
            'passkey_name' => $passkey->name,
        ];
    }
}
