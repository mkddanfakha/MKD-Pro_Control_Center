<?php

namespace Tests\Feature\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CreateAdminUserAuditTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_NAME_QUESTION = 'Nom de l’administrateur';

    private const ADMIN_EMAIL_QUESTION = 'Adresse e-mail';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance('request');
    }

    protected function tearDown(): void
    {
        User::flushEventListeners();

        parent::tearDown();
    }

    public function test_successful_admin_creation_is_audited(): void
    {
        $this->artisan('app:create-admin-user')
            ->expectsQuestion(self::ADMIN_NAME_QUESTION, 'Admin Audit')
            ->expectsQuestion(self::ADMIN_EMAIL_QUESTION, 'admin-audit@example.com')
            ->expectsQuestion('Mot de passe', 'cli-test-pass-8')
            ->expectsQuestion('Confirmer le mot de passe', 'cli-test-pass-8')
            ->assertSuccessful();

        $user = User::query()->where('email', 'admin-audit@example.com')->firstOrFail();

        $log = AuditLog::query()->where('action', 'user.created')->sole();

        $this->assertSame('success', $log->result);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertNull($log->old_values);
        $this->assertSame($user->id, $log->new_values['id']);
        $this->assertSame('Admin Audit', $log->new_values['name']);
        $this->assertSame('admin-audit@example.com', $log->new_values['email']);
        $this->assertSame(['id', 'name', 'email'], array_keys($log->new_values));
        $this->assertNull($log->user_id);
        $this->assertNull($log->ip_address);
        $this->assertNull($log->user_agent);
        $this->assertDoesNotContainSensitiveUserData($log);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.created')->count());
    }

    public function test_duplicate_email_does_not_create_user_audit(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->artisan('app:create-admin-user')
            ->expectsQuestion(self::ADMIN_NAME_QUESTION, 'Autre Admin')
            ->expectsQuestion(self::ADMIN_EMAIL_QUESTION, 'existing@example.com')
            ->assertFailed();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_persistence_failure_creates_user_create_failed_audit(): void
    {
        User::creating(function (): void {
            throw new RuntimeException('Échec simulé de persistance');
        });

        $this->artisan('app:create-admin-user')
            ->expectsQuestion(self::ADMIN_NAME_QUESTION, 'Admin Échec')
            ->expectsQuestion(self::ADMIN_EMAIL_QUESTION, 'fail-audit@example.com')
            ->expectsQuestion('Mot de passe', 'cli-test-pass-8')
            ->expectsQuestion('Confirmer le mot de passe', 'cli-test-pass-8')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'fail-audit@example.com')->count());

        $log = AuditLog::query()->where('action', 'user.create_failed')->sole();

        $this->assertSame('failure', $log->result);
        $this->assertSame('Admin Échec', $log->new_values['name']);
        $this->assertSame('fail-audit@example.com', $log->new_values['email']);
        $this->assertSame(['name', 'email'], array_keys($log->new_values));
        $this->assertSame('Échec simulé de persistance', $log->error_message);
        $this->assertNull($log->old_values);
        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertDoesNotContainSensitiveUserData($log);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.created')->count());
    }

    private function assertDoesNotContainSensitiveUserData(AuditLog $log): void
    {
        $encoded = json_encode([
            $log->old_values,
            $log->new_values,
            $log->error_message,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('cli-test-pass-8', $encoded);
        $this->assertStringNotContainsString('$2y$', $encoded);
        $this->assertStringNotContainsString('remember_token', strtolower($encoded));
        $this->assertStringNotContainsString('two_factor_secret', strtolower($encoded));
        $this->assertStringNotContainsString('two_factor_recovery', strtolower($encoded));
        $this->assertStringNotContainsString('credentials', strtolower($encoded));

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
}
