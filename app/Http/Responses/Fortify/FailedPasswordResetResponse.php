<?php

namespace App\Http\Responses\Fortify;

use App\Services\UserSecurityFailureAuditor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Fortify;

class FailedPasswordResetResponse implements FailedPasswordResetResponseContract
{
    public function __construct(
        protected string $status,
        private readonly UserSecurityFailureAuditor $failureAuditor,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request)
    {
        $email = $request->input(Fortify::email());
        $emailString = is_string($email) ? $email : null;
        $user = $this->failureAuditor->resolveUserByEmail($emailString);

        $this->failureAuditor->record(
            'user.password_reset_failed',
            auditable: $user,
            newValues: $this->failureAuditor->emailContext($emailString),
        );

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                'email' => [trans($this->status)],
            ]);
        }

        return back()
            ->withInput($request->only(Fortify::email()))
            ->withErrors(['email' => trans($this->status)]);
    }
}
