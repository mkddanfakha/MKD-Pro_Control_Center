<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class UserSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_is_audited_on_success(): void
    {
        $user = User::factory()->create([
            'name' => 'Avant Nom',
            'email' => 'profile-audit@example.com',
        ]);

        $this->actingAs($user)
            ->put(route('user-profile-information.update'), [
                'name' => 'Après Nom',
                'email' => 'profile-audit@example.com',
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'user.profile_updated')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame('Avant Nom', $log->old_values['name']);
        $this->assertSame('Après Nom', $log->new_values['name']);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
    }

    public function test_password_update_is_audited_on_success(): void
    {
        $user = User::factory()->create([
            'email' => 'password-update-audit@example.com',
            'password' => 'OldPassword1!',
        ]);

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'OldPassword1!',
                'password' => 'NewPassword1!',
                'password_confirmation' => 'NewPassword1!',
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'user.password_updated')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame($user->id, $log->new_values['id']);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
    }

    public function test_password_reset_is_audited_on_success(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-audit@example.com',
        ]);

        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ResetPassword1!',
            'password_confirmation' => 'ResetPassword1!',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'user.password_reset')->sole();

        $this->assertSame('success', $log->result);
        $this->assertNull($log->user_id);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame($user->email, $log->new_values['email']);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
    }

    public function test_two_factor_enable_is_audited_on_success(): void
    {
        $user = User::factory()->create([
            'email' => '2fa-enable-audit@example.com',
            'password' => 'Password1!',
        ]);

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->post(route('two-factor.enable'))->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'user.two_factor_enabled')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertTrue($log->new_values['two_factor_confirmation_pending']);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
    }

    public function test_two_factor_confirm_is_audited_on_success(): void
    {
        $user = User::factory()->create([
            'email' => '2fa-confirm-audit@example.com',
            'password' => 'Password1!',
        ]);

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->post(route('two-factor.enable'))->assertSessionHasNoErrors();

        $user->refresh();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->post(route('two-factor.confirm'), [
            'code' => $code,
        ])->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'user.two_factor_confirmed')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertTrue($log->new_values['two_factor_confirmed']);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
    }

    public function test_two_factor_disable_is_audited_on_success(): void
    {
        $user = User::factory()->create([
            'email' => '2fa-disable-audit@example.com',
            'password' => 'Password1!',
        ]);

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->post(route('two-factor.enable'))->assertSessionHasNoErrors();

        $user->refresh();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => $code])->assertSessionHasNoErrors();

        AuditLog::query()->delete();

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->delete(route('two-factor.disable'))->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'user.two_factor_disabled')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
    }

    public function test_recovery_codes_regeneration_is_audited_on_success(): void
    {
        $user = User::factory()->create([
            'email' => '2fa-recovery-audit@example.com',
            'password' => 'Password1!',
        ]);

        $this->enableAndConfirmTwoFactor($user, 'Password1!');

        AuditLog::query()->delete();

        $this->confirmUserPassword($user, 'Password1!');
        $this->actingAs($user)->post(route('two-factor.regenerate-recovery-codes'))->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'user.two_factor_recovery_codes_regenerated')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertForbiddenSecurityKeysAbsent($log);
        $this->assertDoesNotContainSensitiveSecurityData($log);
    }

    public function test_recovery_code_consumption_is_not_testable_without_two_factor_trait_on_user(): void
    {
        $this->markTestSkipped(
            'RecoveryCodeReplaced est émis via User::replaceRecoveryCode() (trait TwoFactorAuthenticatable). '
            .'Le modèle User n’utilise pas ce trait dans cette tâche ; l’audit user.two_factor_recovery_code_used '
            .'est implémenté sur l’événement mais non exécutable end-to-end ici.',
        );
    }

    public function test_passkey_operations_are_not_testable_without_passkey_user_contract(): void
    {
        $this->markTestSkipped(
            'Les routes passkeys exigent que User implémente Laravel\\Passkeys\\Contracts\\PasskeyUser. '
            .'User.php n’est pas modifiable dans cette tâche ; création, suppression et confirmation passkey '
            .'ne sont pas testables end-to-end.',
        );
    }

    public function test_successful_login_does_not_create_user_password_updated_audit(): void
    {
        $user = User::factory()->create([
            'email' => 'no-dup-audit@example.com',
            'password' => 'Password1!',
        ]);

        $this->post(route('login'), [
            'email' => 'no-dup-audit@example.com',
            'password' => 'Password1!',
        ])->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'user.password_updated')->count());
    }

    private function confirmUserPassword(User $user, string $password): void
    {
        $this->actingAs($user)
            ->post(route('password.confirm.store'), [
                'password' => $password,
            ])
            ->assertRedirect();
    }

    private function enableAndConfirmTwoFactor(User $user, string $password): void
    {
        $this->confirmUserPassword($user, $password);
        $this->actingAs($user)->post(route('two-factor.enable'))->assertSessionHasNoErrors();

        $user->refresh();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->confirmUserPassword($user, $password);
        $this->actingAs($user)->post(route('two-factor.confirm'), [
            'code' => $code,
        ])->assertSessionHasNoErrors();
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
            'OldPassword1!',
            'NewPassword1!',
            'ResetPassword1!',
        ];

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString(strtolower($needle), strtolower($encoded));
        }
    }
}
