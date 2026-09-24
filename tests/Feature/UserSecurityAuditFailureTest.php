<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

class UserSecurityAuditFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_validation_failure_is_audited(): void
    {
        $user = User::factory()->create([
            'email' => 'profile-fail@example.com',
        ]);

        $this->actingAs($user)
            ->put(route('user-profile-information.update'), [
                'name' => '',
                'email' => 'not-an-email',
            ])
            ->assertSessionHasErrorsIn('updateProfileInformation');

        $log = AuditLog::query()->where('action', 'user.profile_update_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertNull($log->new_values);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.profile_updated')->count());
    }

    public function test_password_update_validation_failure_is_audited(): void
    {
        $user = User::factory()->create([
            'email' => 'password-fail@example.com',
            'password' => 'CorrectPassword1!',
        ]);

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'WrongPassword1!',
                'password' => 'NewPassword1!',
                'password_confirmation' => 'NewPassword1!',
            ])
            ->assertSessionHasErrorsIn('updatePassword');

        $log = AuditLog::query()->where('action', 'user.password_update_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertNull($log->new_values);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.password_updated')->count());
    }

    public function test_password_reset_failure_is_audited(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-fail@example.com',
        ]);

        $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'ResetPassword1!',
            'password_confirmation' => 'ResetPassword1!',
        ])->assertSessionHasErrors('email');

        $log = AuditLog::query()->where('action', 'user.password_reset_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertNull($log->user_id);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame('reset-fail@example.com', $log->new_values['email']);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.password_reset')->count());
    }

    public function test_password_reset_link_failure_response_is_audited(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-link-fail@example.com',
        ]);

        $request = Request::create('/forgot-password', 'POST', [
            Fortify::email() => $user->email,
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        app(FailedPasswordResetLinkRequestResponse::class, [
            'status' => Password::RESET_THROTTLED,
        ])->toResponse($request);

        $log = AuditLog::query()->where('action', 'user.password_reset_request_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame('reset-link-fail@example.com', $log->new_values['email']);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
    }

    public function test_two_factor_confirmation_failure_is_audited(): void
    {
        $user = User::factory()->create([
            'email' => '2fa-confirm-fail@example.com',
            'password' => 'Password1!',
        ]);

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->post(route('two-factor.enable'))->assertSessionHasNoErrors();

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->post(route('two-factor.confirm'), [
            'code' => '000000',
        ])->assertSessionHasErrorsIn('confirmTwoFactorAuthentication');

        $log = AuditLog::query()->where('action', 'user.two_factor_confirmation_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertNull($log->new_values);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.two_factor_confirmed')->count());
    }

    public function test_two_factor_challenge_failure_is_not_testable_without_two_factor_trait(): void
    {
        $this->markTestSkipped(
            'Le flux two-factor-challenge repose sur TwoFactorAuthenticatable (recoveryCodes, hasEnabledTwoFactorAuthentication). '
            .'L’audit auth.two_factor_failed via TwoFactorAuthenticationFailed est implémenté mais non exécutable end-to-end sans modifier User.php.',
        );
    }

    public function test_passkey_failures_are_not_testable_without_passkey_user_contract(): void
    {
        $this->markTestSkipped(
            'Les routes passkeys exigent PasskeyUser sur User. '
            .'Les audits user.passkey_login_failed et user.passkey_confirmation_failed sont branchés sur ValidationException des routes passkey.login et passkey.confirm.',
        );
    }

    public function test_password_update_failure_does_not_duplicate_auth_login_failed(): void
    {
        $user = User::factory()->create([
            'email' => 'no-dup-fail@example.com',
            'password' => 'CorrectPassword1!',
        ]);

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'WrongPassword1!',
                'password' => 'NewPassword1!',
                'password_confirmation' => 'NewPassword1!',
            ])
            ->assertSessionHasErrorsIn('updatePassword');

        $this->assertSame(1, AuditLog::query()->where('action', 'user.password_update_failed')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'auth.login_failed')->count());
    }

    private function confirmUserPassword(User $user, string $password): void
    {
        $this->actingAs($user)
            ->post(route('password.confirm.store'), [
                'password' => $password,
            ])
            ->assertRedirect();
    }

    private function assertForbiddenSecurityKeysAbsent(AuditLog $log): void
    {
        $forbidden = [
            'password',
            'password_hash',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'credential',
            'challenge',
            'token',
            'current_password',
            'code',
        ];

        foreach ([$log->old_values, $log->new_values] as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach ($forbidden as $key) {
                $this->assertArrayNotHasKey($key, $payload);
            }
        }

        $encoded = json_encode([
            $log->old_values,
            $log->new_values,
            $log->error_message,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('invalid-token', $encoded);
        $this->assertStringNotContainsString('WrongPassword1!', $encoded);
        $this->assertStringNotContainsString('ResetPassword1!', $encoded);
    }

    private function assertDoesNotContainSensitiveSecurityData(AuditLog $log): void
    {
        $encoded = json_encode([
            $log->old_values,
            $log->new_values,
            $log->error_message,
        ], JSON_THROW_ON_ERROR);

        $needles = [
            'password_hash',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'current_password',
            'credential',
            'challenge',
            '$2y$',
            'WrongPassword1!',
            'ResetPassword1!',
        ];

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString(strtolower($needle), strtolower($encoded));
        }
    }
}
