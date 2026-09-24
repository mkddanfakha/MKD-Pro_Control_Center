<?php

namespace App\Http\Responses\Fortify;

use App\Services\UserSecurityFailureAuditor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;

class FailedTwoFactorLoginResponse implements FailedTwoFactorLoginResponseContract
{
    public function __construct(
        private readonly UserSecurityFailureAuditor $failureAuditor,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request)
    {
        if (! $request->session()->has('login.id')) {
            $this->failureAuditor->record('auth.two_factor_failed');
        }

        [$key, $message] = $request->filled('recovery_code')
            ? ['recovery_code', __('The provided two factor recovery code was invalid.')]
            : ['code', __('The provided two factor authentication code was invalid.')];

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                $key => [$message],
            ]);
        }

        return redirect()->route('two-factor.login')->withErrors([$key => $message]);
    }
}
