<?php

use App\Http\Controllers\Subscription\BillingPortalController;
use App\Http\Controllers\Subscription\CheckoutController;
use App\Http\Controllers\Subscription\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('subscription')->name('subscription.')->group(function () {
    foreach ([
        ['get', '/plans', [SubscriptionController::class, 'plans'], 'plans'],
        ['get', '/manage', [SubscriptionController::class, 'manage'], 'manage'],
        ['post', '/cancel', [SubscriptionController::class, 'cancel'], 'cancel'],
        ['post', '/resume', [SubscriptionController::class, 'resume'], 'resume'],
        ['post', '/checkout', CheckoutController::class, 'checkout'],
        ['get', '/success', [CheckoutController::class, 'success'], 'success'],
        ['post', '/billing-portal', BillingPortalController::class, 'billing-portal'],
    ] as [$method, $uri, $action, $name]) {
        Route::$method($uri, $action)->name($name);
    }
});
