<?php

use App\Http\Controllers\Admin\HealthcheckController as AdminHealthcheckController;
use App\Http\Controllers\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Admin\NotificationTemplateController as AdminNotificationTemplateController;
use App\Http\Controllers\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\TierController as AdminTierController;
use Illuminate\Support\Facades\Route;

Route::post('/impersonation/stop', [AdminImpersonationController::class, 'stop'])
    ->middleware(['auth'])
    ->name('impersonation.stop');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    foreach ([
        ['get', '/subscriptions', [AdminSubscriptionController::class, 'index'], 'subscriptions'],
        ['post', '/subscriptions/{user}/sync', [AdminSubscriptionController::class, 'sync'], 'subscriptions.sync'],
        ['post', '/subscriptions/{user}/assign-tier', [AdminSubscriptionController::class, 'assignTier'], 'subscriptions.assign-tier'],
        ['post', '/subscriptions/sync-all', [AdminSubscriptionController::class, 'syncAll'], 'subscriptions.sync-all'],
        ['post', '/impersonation/{user}', [AdminImpersonationController::class, 'start'], 'impersonation.start'],
        ['get', '/permissions', [AdminPermissionController::class, 'index'], 'permissions'],
        ['patch', '/permissions/tiers/{tier}', [AdminPermissionController::class, 'updateTierPermissions'], 'permissions.tiers.update'],
        ['get', '/healthchecks', [AdminHealthcheckController::class, 'index'], 'healthchecks'],
        ['post', '/healthchecks/run', [AdminHealthcheckController::class, 'run'], 'healthchecks.run'],
        ['post', '/healthchecks/sync', [AdminHealthcheckController::class, 'sync'], 'healthchecks.sync'],
        ['patch', '/notification-templates/defaults', [AdminNotificationTemplateController::class, 'updateDefaults'], 'notification-templates.defaults'],
        ['post', '/notification-templates/ensure-daily-summary', [AdminNotificationTemplateController::class, 'ensureDailySummaryTemplate'], 'notification-templates.ensure-daily-summary'],
        ['post', '/notification-templates/preview', [AdminNotificationTemplateController::class, 'preview'], 'notification-templates.preview'],
    ] as [$method, $uri, $action, $name]) {
        Route::$method($uri, $action)->name($name);
    }

    Route::resource('tiers', AdminTierController::class)->except(['show']);
    Route::resource('notification-templates', AdminNotificationTemplateController::class)->except(['show']);
});
