<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\UserSecurityFailureAuditor;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Gate;
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

        try {
            $this->assertControlCenterProfileEmailChangeIsAllowed($user, $input['email']);
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

    /**
     * Mono-administrateur : empêche l’élévation via l’e-mail configuré et la perte
     * accidentelle de l’unique compte administrateur Control Center.
     *
     * @throws ValidationException
     */
    private function assertControlCenterProfileEmailChangeIsAllowed(User $user, string $newEmail): void
    {
        $configuredAdminEmail = $this->normalizedConfiguredAdminEmail();

        if ($configuredAdminEmail === null) {
            return;
        }

        $newEmailNormalized = strtolower(trim($newEmail));
        $currentEmailNormalized = strtolower(trim($user->email));

        if ($newEmailNormalized === $currentEmailNormalized) {
            return;
        }

        $isControlCenterAdmin = Gate::forUser($user)->allows('accessControlCenter');

        if (! $isControlCenterAdmin && $newEmailNormalized === $configuredAdminEmail) {
            throw ValidationException::withMessages([
                'email' => 'Cette adresse e-mail est réservée à l’administrateur du Control Center.',
            ])->errorBag('updateProfileInformation');
        }

        if ($isControlCenterAdmin && $newEmailNormalized !== $configuredAdminEmail) {
            throw ValidationException::withMessages([
                'email' => 'L’adresse e-mail de l’administrateur du Control Center ne peut pas être modifiée depuis l’application.',
            ])->errorBag('updateProfileInformation');
        }
    }

    private function normalizedConfiguredAdminEmail(): ?string
    {
        $email = config('control_center.admin_email');

        if (! is_string($email)) {
            return null;
        }

        $email = strtolower(trim($email));

        return $email === '' ? null : $email;
    }
}
