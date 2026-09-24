<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\UserSecurityFailureAuditor;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication as FortifyConfirmTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class ConfirmTwoFactorAuthentication extends FortifyConfirmTwoFactorAuthentication
{
    public function __construct(
        TwoFactorAuthenticationProvider $provider,
        private readonly UserSecurityFailureAuditor $failureAuditor,
    ) {
        parent::__construct($provider);
    }

    /**
     * @param  mixed  $user
     */
    public function __invoke($user, $code): void
    {
        try {
            parent::__invoke($user, $code);
        } catch (ValidationException $exception) {
            if ($user instanceof User) {
                $this->failureAuditor->record(
                    'user.two_factor_confirmation_failed',
                    auditable: $user,
                );
            }

            throw $exception;
        }
    }
}
