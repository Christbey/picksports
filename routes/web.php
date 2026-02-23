<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PerformanceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    $performanceStats = app(\App\Services\PerformanceStatistics::class);

    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
        'performance' => [
            'overall' => $performanceStats->getOverallStats(),
            'recent' => $performanceStats->getRecentPerformance(),
            'roi' => $performanceStats->calculateROI(),
        ],
    ]);
})->name('home');

Route::get('performance', PerformanceController::class)->name('performance');

Route::get('terms', function () {
    return Inertia::render('Legal/Terms');
})->name('terms');

Route::get('privacy', function () {
    return Inertia::render('Legal/Privacy');
})->name('privacy');

Route::get('responsible-gambling', function () {
    return Inertia::render('Legal/ResponsibleGambling');
})->name('responsible-gambling');

Route::get('dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::get('my-bets', function () {
    return Inertia::render('MyBets');
})->middleware(['auth', 'verified'])->name('my-bets');

Route::get('nba-predictions', function () {
    return Inertia::render('NBAPredictions');
})->middleware(['auth', 'verified', 'permission:view-nba-predictions'])->name('nba-predictions');

Route::get('cbb-predictions', function () {
    return Inertia::render('CBBPredictions');
})->middleware(['auth', 'verified', 'permission:view-cbb-predictions'])->name('cbb-predictions');

Route::get('wcbb-predictions', function () {
    return Inertia::render('WCBBPredictions');
})->middleware(['auth', 'verified', 'permission:view-wcbb-predictions'])->name('wcbb-predictions');

Route::get('nfl-predictions', function () {
    return Inertia::render('NFLPredictions');
})->middleware(['auth', 'verified', 'permission:view-nfl-predictions'])->name('nfl-predictions');

Route::get('mlb-predictions', function () {
    return Inertia::render('MLBPredictions');
})->middleware(['auth', 'verified', 'permission:view-mlb-predictions'])->name('mlb-predictions');

Route::get('cfb-predictions', function () {
    return Inertia::render('CFBPredictions');
})->middleware(['auth', 'verified', 'permission:view-cfb-predictions'])->name('cfb-predictions');

Route::get('wnba-predictions', function () {
    return Inertia::render('WNBAPredictions');
})->middleware(['auth', 'verified', 'permission:view-wnba-predictions'])->name('wnba-predictions');

Route::get('cbb-team-metrics', function () {
    return Inertia::render('CBBTeamMetrics');
})->middleware(['auth', 'verified'])->name('cbb-team-metrics');

Route::get('wcbb-team-metrics', function () {
    return Inertia::render('WCBBTeamMetrics');
})->middleware(['auth', 'verified'])->name('wcbb-team-metrics');

Route::get('nba-team-metrics', function () {
    return Inertia::render('NBATeamMetrics');
})->middleware(['auth', 'verified'])->name('nba-team-metrics');

Route::get('wnba-team-metrics', function () {
    return Inertia::render('WNBATeamMetrics');
})->middleware(['auth', 'verified'])->name('wnba-team-metrics');

Route::get('mlb-team-metrics', function () {
    return Inertia::render('MLBTeamMetrics');
})->middleware(['auth', 'verified'])->name('mlb-team-metrics');

Route::get('nfl-team-metrics', function () {
    return Inertia::render('NFLTeamMetrics');
})->middleware(['auth', 'verified'])->name('nfl-team-metrics');

Route::get('/nba/teams/{team}', \App\Http\Controllers\NBA\TeamController::class)->middleware(['auth', 'verified']);

Route::get('/nba/players/{player}', \App\Http\Controllers\NBA\PlayerController::class)->middleware(['auth', 'verified']);

Route::get('/nba/games/{game}', \App\Http\Controllers\NBA\GameController::class)->middleware(['auth', 'verified']);

Route::get('/wnba/teams/{team}', \App\Http\Controllers\WNBA\TeamController::class)->middleware(['auth', 'verified']);

Route::get('/wnba/games/{game}', \App\Http\Controllers\WNBA\GameController::class)->middleware(['auth', 'verified']);

Route::get('/cbb/teams/{team}', \App\Http\Controllers\CBB\TeamController::class)->middleware(['auth', 'verified']);

Route::get('/cbb/games/{game}', \App\Http\Controllers\CBB\GameController::class)->middleware(['auth', 'verified']);

Route::get('/wcbb/teams/{team}', \App\Http\Controllers\WCBB\TeamController::class)->middleware(['auth', 'verified']);

Route::get('/wcbb/games/{game}', \App\Http\Controllers\WCBB\GameController::class)->middleware(['auth', 'verified']);

Route::get('/nfl/teams/{team}', \App\Http\Controllers\NFL\TeamController::class)->middleware(['auth', 'verified']);

Route::get('/nfl/games/{game}', \App\Http\Controllers\NFL\GameController::class)->middleware(['auth', 'verified']);

Route::get('/mlb/teams/{team}', \App\Http\Controllers\MLB\TeamController::class)->middleware(['auth', 'verified']);

Route::get('/mlb/games/{game}', \App\Http\Controllers\MLB\GameController::class)->middleware(['auth', 'verified']);

Route::middleware(['auth'])->prefix('subscription')->name('subscription.')->group(function () {
    Route::get('/plans', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'plans'])->name('plans');
    Route::get('/manage', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'manage'])->name('manage');
    Route::post('/cancel', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'cancel'])->name('cancel');
    Route::post('/resume', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'resume'])->name('resume');

    Route::post('/checkout', \App\Http\Controllers\Subscription\CheckoutController::class)->name('checkout');
    Route::get('/success', [\App\Http\Controllers\Subscription\CheckoutController::class, 'success'])->name('success');

    Route::post('/billing-portal', \App\Http\Controllers\Subscription\BillingPortalController::class)->name('billing-portal');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/subscriptions', [\App\Http\Controllers\Admin\SubscriptionController::class, 'index'])->name('subscriptions');
    Route::post('/subscriptions/{user}/sync', [\App\Http\Controllers\Admin\SubscriptionController::class, 'sync'])->name('subscriptions.sync');
    Route::post('/subscriptions/sync-all', [\App\Http\Controllers\Admin\SubscriptionController::class, 'syncAll'])->name('subscriptions.sync-all');

    Route::resource('tiers', \App\Http\Controllers\Admin\TierController::class)->except(['show']);
    Route::resource('notification-templates', \App\Http\Controllers\Admin\NotificationTemplateController::class)->except(['show']);

    Route::get('/permissions', [\App\Http\Controllers\Admin\PermissionController::class, 'index'])->name('permissions');
    Route::get('/healthchecks', [\App\Http\Controllers\Admin\HealthcheckController::class, 'index'])->name('healthchecks');
    Route::post('/healthchecks/run', [\App\Http\Controllers\Admin\HealthcheckController::class, 'run'])->name('healthchecks.run');
    Route::post('/healthchecks/sync', [\App\Http\Controllers\Admin\HealthcheckController::class, 'sync'])->name('healthchecks.sync');
});

Route::post('/stripe/webhook', [\App\Http\Controllers\WebhookController::class, 'handleWebhook'])->name('cashier.webhook');

require __DIR__.'/settings.php';
