<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function controlCenterAdminUser(array $attributes = []): User
    {
        return User::factory()->controlCenterAdmin()->create($attributes);
    }

    protected function nonControlCenterUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'other-user@example.test',
        ], $attributes));
    }
}
