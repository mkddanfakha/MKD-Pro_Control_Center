<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_audited(): void
    {
        $user = User::factory()->create([
            'email' => 'login-audit@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->post(route('login'), [
            'email' => 'login-audit@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(config('fortify.home'));

        $log = AuditLog::query()->where('action', 'auth.login')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertNull($log->old_values);
        $this->assertSame($user->id, $log->new_values['id']);
        $this->assertSame('login-audit@example.com', $log->new_values['email']);
        $this->assertSame(['id', 'email'], array_keys($log->new_values));
        $this->assertDoesNotContainSensitiveAuthData($log);
        $this->assertForbiddenCredentialKeysAbsent($log);
    }

    public function test_logout_is_audited(): void
    {
        $user = User::factory()->create([
            'email' => 'logout-audit@example.com',
        ]);

        $this->actingAs($user);

        $response = $this->post(route('logout'));

        $response->assertRedirect('/');

        $log = AuditLog::query()->where('action', 'auth.logout')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame($user->id, $log->old_values['id']);
        $this->assertSame('logout-audit@example.com', $log->old_values['email']);
        $this->assertNull($log->new_values);
        $this->assertDoesNotContainSensitiveAuthData($log);
    }

    public function test_failed_login_is_audited_without_sensitive_data(): void
    {
        User::factory()->create([
            'email' => 'known-user@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->post(route('login'), [
            'email' => 'known-user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');

        $log = AuditLog::query()->where('action', 'auth.login_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertNull($log->user_id);
        $this->assertSame('known-user@example.com', $log->new_values['email']);
        $this->assertNull($log->old_values);
        $this->assertSame(['email'], array_keys($log->new_values));
        $this->assertDoesNotContainSensitiveAuthData($log);
    }

    public function test_auth_audit_payloads_exclude_forbidden_credential_keys(): void
    {
        $user = User::factory()->create([
            'email' => 'keys-check@example.com',
            'password' => 'secret-password',
        ]);

        $this->post(route('login'), [
            'email' => 'keys-check@example.com',
            'password' => 'secret-password',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('logout'))->assertRedirect();

        $this->post(route('login'), [
            'email' => 'keys-check@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        foreach (AuditLog::query()->get() as $log) {
            $this->assertDoesNotContainSensitiveAuthData($log);
            $this->assertForbiddenCredentialKeysAbsent($log);
        }
    }

    public function test_each_authentication_event_creates_exactly_one_audit_log(): void
    {
        $user = User::factory()->create([
            'email' => 'single-audit@example.com',
            'password' => 'secret-password',
        ]);

        $this->post(route('login'), [
            'email' => 'single-audit@example.com',
            'password' => 'secret-password',
        ])->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login')->count());

        $this->actingAs($user)->post(route('logout'))->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.logout')->count());

        $this->post(route('login'), [
            'email' => 'single-audit@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login_failed')->count());
    }

    public function test_authentication_audit_records_ip_and_user_agent_from_http_request(): void
    {
        User::factory()->create([
            'email' => 'http-context@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_USER_AGENT' => 'AuthenticationAuditTest/1.0',
        ])->post(route('login'), [
            'email' => 'http-context@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect();

        $log = AuditLog::query()->where('action', 'auth.login')->sole();

        $this->assertSame('203.0.113.50', $log->ip_address);
        $this->assertSame('AuthenticationAuditTest/1.0', $log->user_agent);
    }

    private function assertForbiddenCredentialKeysAbsent(AuditLog $log): void
    {
        $forbidden = [
            'password',
            'password_hash',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'token',
            'credentials',
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

    private function assertDoesNotContainSensitiveAuthData(AuditLog $log): void
    {
        $encoded = json_encode([
            $log->old_values,
            $log->new_values,
            $log->error_message,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('secret-password', $encoded);
        $this->assertStringNotContainsString('wrong-password', $encoded);
        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertStringNotContainsString('remember_token', strtolower($encoded));
        $this->assertStringNotContainsString('two_factor_secret', strtolower($encoded));
        $this->assertStringNotContainsString('two_factor_recovery', strtolower($encoded));
        $this->assertStringNotContainsString('credentials', strtolower($encoded));
        $this->assertStringNotContainsString('$2y$', $encoded);
    }
}
