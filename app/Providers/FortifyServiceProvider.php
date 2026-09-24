<?php

namespace App\Providers;

use App\Actions\Fortify\ConfirmTwoFactorAuthentication;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\Fortify\FailedPasswordResetLinkRequestResponse as AppFailedPasswordResetLinkRequestResponse;
use App\Http\Responses\Fortify\FailedPasswordResetResponse as AppFailedPasswordResetResponse;
use App\Http\Responses\Fortify\FailedTwoFactorLoginResponse as AppFailedTwoFactorLoginResponse;
use Illuminate\Contracts\Container\Container;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication as FortifyConfirmTwoFactorAuthentication;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FortifyConfirmTwoFactorAuthentication::class, ConfirmTwoFactorAuthentication::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });

        $this->registerSecurityFailureResponseBindings();
    }

    private function registerSecurityFailureResponseBindings(): void
    {
        $this->app->bind(FailedPasswordResetResponseContract::class, function (Container $app, array $parameters) {
            return new AppFailedPasswordResetResponse(
                $parameters['status'] ?? '',
                $app->make(\App\Services\UserSecurityFailureAuditor::class),
            );
        });

        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, function (Container $app, array $parameters) {
            return new AppFailedPasswordResetLinkRequestResponse(
                $parameters['status'] ?? '',
                $app->make(\App\Services\UserSecurityFailureAuditor::class),
            );
        });

        $this->app->singleton(FailedTwoFactorLoginResponseContract::class, AppFailedTwoFactorLoginResponse::class);
    }
}
