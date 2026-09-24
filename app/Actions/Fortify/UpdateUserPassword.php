<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\UserSecurityFailureAuditor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private readonly UserSecurityFailureAuditor $failureAuditor,
    ) {}

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        try {
            Validator::make($input, [
                'current_password' => ['required', 'string', 'current_password:web'],
                'password' => $this->passwordRules(),
            ], [
                'current_password.current_password' => __('The provided password does not match your current password.'),
            ])->validateWithBag('updatePassword');
        } catch (ValidationException $exception) {
            $this->failureAuditor->record(
                'user.password_update_failed',
                auditable: $user,
            );

            throw $exception;
        }

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
