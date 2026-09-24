<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\UserSecurityFailureAuditor;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly UserSecurityFailureAuditor $failureAuditor,
    ) {}
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        try {
            Validator::make($input, [
                'name' => ['required', 'string', 'max:255'],

                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id),
                ],
            ])->validateWithBag('updateProfileInformation');
        } catch (ValidationException $exception) {
            $this->failureAuditor->record(
                'user.profile_update_failed',
                auditable: $user,
            );

            throw $exception;
        }

        $oldValues = $this->profileAuditSnapshot($user);

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }

        $this->auditLogService->record(
            'user.profile_updated',
            auditable: $user->fresh() ?? $user,
            oldValues: $oldValues,
            newValues: $this->profileAuditSnapshot($user->fresh() ?? $user),
            result: 'success',
        );
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }

    /**
     * @return array{id: int|null, name: string|null, email: string|null}
     */
    private function profileAuditSnapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
