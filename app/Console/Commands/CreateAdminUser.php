<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('app:create-admin-user')]
#[Description('Create the first MKD-Pro Control Center administrator')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $name = $this->ask('Nom de l’administrateur');
        $email = $this->ask('Adresse e-mail');

        if (User::where('email', $email)->exists()) {
            $this->error('Un utilisateur existe déjà avec cette adresse e-mail.');

            return self::FAILURE;
        }

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

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->newLine();
        $this->info('Administrateur créé avec succès.');
        $this->line("Nom : {$user->name}");
        $this->line("E-mail : {$user->email}");

        return self::SUCCESS;
    }
}