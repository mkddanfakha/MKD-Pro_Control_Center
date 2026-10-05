<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\Commercial\OfferController as CommercialOfferController;
use App\Http\Controllers\Commercial\OfferVersionController as CommercialOfferVersionController;
use App\Http\Controllers\Commercial\ProductController as CommercialProductController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\InstallationModuleController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionReminderController;
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
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('can:accessControlCenter')
        ->name('dashboard');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('can:accessControlCenter')
        ->name('audit-logs.index');

    Route::middleware('can:accessControlCenter')->group(function (): void {
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::resource('clients', ClientController::class)->except(['index', 'show']);
        Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');

        Route::get('installations', [InstallationController::class, 'index'])->name('installations.index');
        Route::resource('installations', InstallationController::class)->except(['index', 'show']);
        Route::get('installations/{installation}', [InstallationController::class, 'show'])->name('installations.show');

        Route::resource('modules', ModuleController::class);
        Route::resource('installation-modules', InstallationModuleController::class);
    });

    Route::get('subscriptions', [SubscriptionController::class, 'index'])
        ->middleware('can:accessControlCenter')
        ->name('subscriptions.index');

    Route::post('subscriptions/{subscription}/consume-credit', [SubscriptionController::class, 'consumeCredit'])
        ->middleware('can:accessControlCenter')
        ->name('subscriptions.consume-credit');

    Route::middleware('can:accessControlCenter')->group(function (): void {
        Route::resource('subscriptions', SubscriptionController::class)->except(['index', 'show']);
    });

    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])
        ->middleware('can:accessControlCenter')
        ->name('subscriptions.show');

    Route::get('subscription-reminders', [SubscriptionReminderController::class, 'index'])
        ->middleware('can:viewAny,'.\App\Models\SubscriptionReminder::class)
        ->name('subscription-reminders.index');

    Route::get('subscription-reminders/{subscription_reminder}', [SubscriptionReminderController::class, 'show'])
        ->middleware('can:accessControlCenter')
        ->name('subscription-reminders.show');

    Route::get('payments', [PaymentController::class, 'index'])
        ->middleware('can:accessControlCenter')
        ->name('payments.index');

    Route::post('payments/{payment}/renew-subscription', [PaymentController::class, 'renewSubscription'])
        ->middleware('can:accessControlCenter')
        ->name('payments.renew-subscription');

    Route::middleware('can:accessControlCenter')->group(function (): void {
        Route::resource('payments', PaymentController::class)->except(['index', 'show']);
    });

    Route::get('payments/{payment}', [PaymentController::class, 'show'])
        ->middleware('can:accessControlCenter')
        ->name('payments.show');

    Route::prefix('commercial')->name('commercial.')->middleware('can:accessControlCenter')->group(function () {
        Route::resource('products', CommercialProductController::class)->except(['destroy']);
        Route::resource('offers', CommercialOfferController::class)->except(['destroy']);
        Route::post('offer-versions/{offer_version}/publish', [CommercialOfferVersionController::class, 'publish'])
            ->name('offer-versions.publish');
        Route::post('offer-versions/{offer_version}/retire', [CommercialOfferVersionController::class, 'retire'])
            ->name('offer-versions.retire');
        Route::resource('offer-versions', CommercialOfferVersionController::class)->except(['destroy']);
    });
});
