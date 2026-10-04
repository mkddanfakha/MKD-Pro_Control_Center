<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Throwable;

#[Signature('app:create-admin-user')]
#[Description('Create the first MKD-Pro Control Center administrator')]
class CreateAdminUser extends Command
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = $this->configuredControlCenterAdminEmail();

        if ($email === null) {
            $this->error('CONTROL_CENTER_ADMIN_EMAIL est absent ou vide. Configurez l’adresse administrateur avant de créer le compte.');

            return self::FAILURE;
        }

        if (User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists()) {
            $this->error('Un utilisateur existe déjà avec l’adresse e-mail administrateur configurée.');

            return self::FAILURE;
        }

        $name = $this->ask('Nom de l’administrateur');

        $password = $this->secret('Mot de passe');
        $passwordConfirmation = $this->secret('Confirmer le mot de passe');

        if ($password !== $passwordConfirmation) {
            $this->error('Les mots de passe ne correspondent pas.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Le mot de passe doit contenir au moins 8 caractères.');

            return self::FAILURE;
        }

        try {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
        } catch (Throwable $exception) {
            $this->auditLogService->record(
                'user.create_failed',
                newValues: [
                    'name' => $name,
                    'email' => $email,
                ],
                result: 'failure',
                errorMessage: 'L’opération de création de l’administrateur a échoué.',
            );

            $this->error('Impossible de créer l’administrateur : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->auditLogService->record(
            'user.created',
            auditable: $user,
            newValues: $this->userCreationAuditSnapshot($user),
            result: 'success',
        );

        $this->newLine();
        $this->info('Administrateur créé avec succès.');
        $this->line("Nom : {$user->name}");
        $this->line("E-mail : {$user->email}");

        return self::SUCCESS;
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function userCreationAuditSnapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    private function configuredControlCenterAdminEmail(): ?string
    {
        $email = config('control_center.admin_email');

        if (! is_string($email)) {
            return null;
        }

        $email = strtolower(trim($email));

        return $email === '' ? null : $email;
    }
}
