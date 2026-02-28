<?php

use App\Http\Controllers\Settings\AlertPreferenceController;
use App\Http\Controllers\Settings\OnboardingController;
use App\Http\Controllers\Settings\OddsApiTeamMappingController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\WebPushSubscriptionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/subscription', function () {
        $user = request()->user();

        if (! $user->subscribed()) {
            return redirect()->route('subscription.plans');
        }

        $subscription = $user->subscription();
        $tier = $user->subscriptionTier();

        return Inertia::render('settings/Subscription', [
            'subscription' => [
                'tier' => $tier?->name,
                'status' => $subscription->stripe_status,
                'current_period_end' => $subscription->ends_at,
                'cancel_at_period_end' => $subscription->ends_at !== null,
            ],
        ]);
    })->name('subscription.settings');

    Route::get('settings/alert-preferences', [AlertPreferenceController::class, 'edit'])
        ->name('alert-preferences.edit');

    Route::patch('settings/alert-preferences', [AlertPreferenceController::class, 'update'])
        ->name('alert-preferences.update');

    Route::post('settings/alert-preferences/check-alerts', [AlertPreferenceController::class, 'checkAlerts'])
        ->name('alert-preferences.check');

    Route::get('settings/web-push/config', [WebPushSubscriptionController::class, 'config'])
        ->name('web-push.config');
    Route::post('settings/web-push/subscriptions', [WebPushSubscriptionController::class, 'store'])
        ->name('web-push.subscriptions.store');
    Route::delete('settings/web-push/subscriptions', [WebPushSubscriptionController::class, 'destroy'])
        ->name('web-push.subscriptions.destroy');
    Route::post('settings/web-push/test', [WebPushSubscriptionController::class, 'sendTest'])
        ->name('web-push.test');

    Route::get('settings/onboarding', OnboardingController::class)->name('settings.onboarding');

    Route::get('settings/admin', function () {
        $user = request()->user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        return Inertia::render('settings/Admin');
    })->name('admin.settings');

    Route::middleware(['admin'])->group(function () {
        Route::get('settings/team-mappings', [OddsApiTeamMappingController::class, 'index'])->name('team-mappings.index');
        Route::patch('settings/team-mappings/{mapping}', [OddsApiTeamMappingController::class, 'update'])->name('team-mappings.update');
        Route::delete('settings/team-mappings/{mapping}', [OddsApiTeamMappingController::class, 'destroy'])->name('team-mappings.destroy');
    });
});
