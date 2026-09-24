<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->renderable(function (\Illuminate\Validation\ValidationException $exception, Request $request) {
            if (! $request->routeIs('passkey.login', 'passkey.confirm')) {
                return null;
            }

            $auditor = app(\App\Services\UserSecurityFailureAuditor::class);
            $user = $request->user();
            $userModel = $user instanceof \App\Models\User ? $user : null;

            if ($request->routeIs('passkey.login')) {
                $auditor->record('user.passkey_login_failed', auditable: $userModel);
            } elseif ($request->routeIs('passkey.confirm')) {
                $auditor->record('user.passkey_confirmation_failed', auditable: $userModel);
            }

            return null;
        });
    })->create();
