<?php

use App\Http\Controllers\BettingRecommendationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PerformanceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    $performanceStats = app(\App\Services\PerformanceStatistics::class);
    $overall = $performanceStats->getOverallStats();
    $recent = $performanceStats->getRecentPerformance();
    $roi = $performanceStats->calculateROI();

    // Use demo data if no real predictions exist yet
    if ($overall['total_predictions'] === 0) {
        $overall = [
            'total_predictions' => 1247,
            'winner_accuracy' => 54.2,
            'avg_spread_error' => 8.3,
            'avg_total_error' => 10.5,
            'win_record' => '676-571',
        ];

        $recent = [
            'overall' => [
                'total_predictions' => 89,
                'winner_accuracy' => 56.2,
                'avg_spread_error' => 7.1,
                'avg_total_error' => 9.2,
                'win_record' => '50-39',
            ],
            'roi' => [
                'total_bets' => 89,
                'total_wins' => 50,
                'total_losses' => 39,
                'total_wagered' => 8900,
                'total_profit' => 1250,
                'roi_percentage' => 14.0,
                'win_percentage' => 56.2,
            ],
        ];

        $roi = [
            'total_bets' => 1247,
            'total_wins' => 676,
            'total_losses' => 571,
            'total_wagered' => 124700,
            'total_profit' => 8450,
            'roi_percentage' => 6.8,
            'win_percentage' => 54.2,
        ];
    }

    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
        'performance' => [
            'overall' => $overall,
            'recent' => $recent,
            'roi' => $roi,
        ],
    ]);
})->name('home');

Route::get('performance', PerformanceController::class)->name('performance');

// Player Props - Betting Recommendations by Sport
Route::get('nba-player-props', [BettingRecommendationsController::class, 'nba'])->name('nba.player-props');
Route::get('mlb-player-props', [BettingRecommendationsController::class, 'mlb'])->name('mlb.player-props');
Route::get('nfl-player-props', [BettingRecommendationsController::class, 'nfl'])->name('nfl.player-props');
Route::get('cbb-player-props', [BettingRecommendationsController::class, 'cbb'])->name('cbb.player-props');

// Legacy route - redirect to NBA
Route::get('betting-recommendations', fn () => redirect()->route('nba.player-props'));

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

Route::get('cbb-player-stats', function () {
    return Inertia::render('CBBPlayerStats');
})->middleware(['auth', 'verified'])->name('cbb-player-stats');

Route::get('wcbb-team-metrics', function () {
    return Inertia::render('WCBBTeamMetrics');
})->middleware(['auth', 'verified'])->name('wcbb-team-metrics');

Route::get('nba-team-metrics', function () {
    return Inertia::render('NBATeamMetrics');
})->middleware(['auth', 'verified'])->name('nba-team-metrics');

Route::get('nba-player-stats', function () {
    return Inertia::render('NBAPlayerStats');
})->middleware(['auth', 'verified'])->name('nba-player-stats');

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

Route::get('/nba/players/{player}', \App\Http\Controllers\NBA\PlayerController::class)
    ->middleware(['auth', 'verified'])
    ->name('nba.player.show');

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
    Route::post('/subscriptions/{user}/assign-tier', [\App\Http\Controllers\Admin\SubscriptionController::class, 'assignTier'])->name('subscriptions.assign-tier');
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
