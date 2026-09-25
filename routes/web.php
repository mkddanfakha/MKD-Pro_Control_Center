<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\InstallationModuleController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/login', function () {
    return Inertia::render('Auth/Login');
})->name('login');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::resource('clients', ClientController::class);

    Route::resource('installations', InstallationController::class);

    Route::post('subscriptions/{subscription}/consume-credit', [SubscriptionController::class, 'consumeCredit'])
        ->name('subscriptions.consume-credit');

    Route::resource('subscriptions', SubscriptionController::class);

    Route::post('payments/{payment}/renew-subscription', [PaymentController::class, 'renewSubscription'])
        ->name('payments.renew-subscription');

    Route::resource('payments', PaymentController::class);

    Route::resource('modules', ModuleController::class);

    Route::resource('installation-modules', InstallationModuleController::class);
});