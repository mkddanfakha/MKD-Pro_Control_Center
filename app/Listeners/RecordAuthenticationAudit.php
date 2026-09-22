<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;

class RecordAuthenticationAudit
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handleLogin(Login $event): void
    {
        if ($event->guard !== config('fortify.guard')) {
            return;
        }

        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogService->record(
            'auth.login',
            auditable: $event->user,
            newValues: $this->userSnapshot($event->user),
            result: 'success',
        );
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard !== config('fortify.guard')) {
            return;
        }

        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogService->record(
            'auth.logout',
            auditable: $event->user,
            oldValues: $this->userSnapshot($event->user),
            newValues: null,
            result: 'success',
        );
    }

    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== config('fortify.guard')) {
            return;
        }

        $email = $this->resolveEmailFromCredentials($event->credentials);

        $user = $event->user instanceof User
            ? $event->user
            : ($email !== null ? User::query()->where('email', $email)->first() : null);

        $newValues = $email !== null ? ['email' => $email] : null;

        $this->auditLogService->record(
            'auth.login_failed',
            auditable: $user,
            newValues: $newValues,
            result: 'failure',
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function resolveEmailFromCredentials(array $credentials): ?string
    {
        $username = config('fortify.username', 'email');
        $email = $credentials[$username] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        if (config('fortify.lowercase_usernames', true)) {
            $email = strtolower($email);
        }

        return $email;
    }

    /**
     * @return array{id: int|string|null, email: string|null}
     */
    private function userSnapshot(Authenticatable $user): array
    {
        return [
            'id' => $user->getAuthIdentifier(),
            'email' => $user->getAttribute('email'),
        ];
    }
}
