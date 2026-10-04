<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        Gate::define('accessControlCenter', function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            $configuredAdminEmail = config('control_center.admin_email');

            if (! is_string($configuredAdminEmail)) {
                return false;
            }

            $configuredAdminEmail = strtolower(trim($configuredAdminEmail));

            if ($configuredAdminEmail === '') {
                return false;
            }

            return strtolower(trim($user->email)) === $configuredAdminEmail;
        });
    }
}
