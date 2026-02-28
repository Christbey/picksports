<?php

use App\Http\Controllers\BettingRecommendationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\Admin\HealthcheckController as AdminHealthcheckController;
use App\Http\Controllers\Admin\NotificationTemplateController as AdminNotificationTemplateController;
use App\Http\Controllers\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\TierController as AdminTierController;
use App\Http\Controllers\Subscription\BillingPortalController;
use App\Http\Controllers\Subscription\CheckoutController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\WebhookController;
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
foreach (['nba', 'mlb', 'nfl', 'cbb'] as $sport) {
    Route::get("{$sport}-player-props", [BettingRecommendationsController::class, $sport])
        ->name("{$sport}.player-props");
}

// Legacy route - redirect to NBA
Route::get('betting-recommendations', fn () => redirect()->route('nba.player-props'));

foreach ([
    'terms' => 'Legal/Terms',
    'privacy' => 'Legal/Privacy',
    'responsible-gambling' => 'Legal/ResponsibleGambling',
] as $path => $page) {
    Route::get($path, fn () => Inertia::render($page))->name($path);
}

Route::get('dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::get('my-bets', function () {
    return Inertia::render('MyBets');
})->middleware(['auth', 'verified'])->name('my-bets');

foreach ([
    'nba' => 'NBAPredictions',
    'cbb' => 'CBBPredictions',
    'wcbb' => 'WCBBPredictions',
    'nfl' => 'NFLPredictions',
    'mlb' => 'MLBPredictions',
    'cfb' => 'CFBPredictions',
    'wnba' => 'WNBAPredictions',
] as $sport => $page) {
    Route::get("{$sport}-predictions", fn () => Inertia::render($page))
        ->middleware(['auth', 'verified', "permission:view-{$sport}-predictions"])
        ->name("{$sport}-predictions");
}

foreach ([
    'cbb-team-metrics' => ['page' => 'CBBTeamMetrics', 'sport' => 'cbb'],
    'cbb-player-stats' => ['page' => 'CBBPlayerStats', 'sport' => 'cbb'],
    'wcbb-team-metrics' => ['page' => 'WCBBTeamMetrics', 'sport' => 'wcbb'],
    'nba-team-metrics' => ['page' => 'NBATeamMetrics', 'sport' => 'nba'],
    'nba-player-stats' => ['page' => 'NBAPlayerStats', 'sport' => 'nba'],
    'wnba-team-metrics' => ['page' => 'WNBATeamMetrics', 'sport' => 'wnba'],
    'mlb-team-metrics' => ['page' => 'MLBTeamMetrics', 'sport' => 'mlb'],
    'nfl-team-metrics' => ['page' => 'NFLTeamMetrics', 'sport' => 'nfl'],
] as $path => $config) {
    Route::get($path, fn () => Inertia::render($config['page']))
        ->middleware(['auth', 'verified', "permission:view-{$config['sport']}-predictions"])
        ->name($path);
}

foreach ([
    'nba' => [
        'team' => \App\Http\Controllers\NBA\TeamController::class,
        'game' => \App\Http\Controllers\NBA\GameController::class,
        'player' => \App\Http\Controllers\NBA\PlayerController::class,
    ],
    'wnba' => [
        'team' => \App\Http\Controllers\WNBA\TeamController::class,
        'game' => \App\Http\Controllers\WNBA\GameController::class,
    ],
    'cbb' => [
        'team' => \App\Http\Controllers\CBB\TeamController::class,
        'game' => \App\Http\Controllers\CBB\GameController::class,
    ],
    'wcbb' => [
        'team' => \App\Http\Controllers\WCBB\TeamController::class,
        'game' => \App\Http\Controllers\WCBB\GameController::class,
    ],
    'nfl' => [
        'team' => \App\Http\Controllers\NFL\TeamController::class,
        'game' => \App\Http\Controllers\NFL\GameController::class,
    ],
    'mlb' => [
        'team' => \App\Http\Controllers\MLB\TeamController::class,
        'game' => \App\Http\Controllers\MLB\GameController::class,
    ],
] as $sport => $controllers) {
    $sportDetailMiddleware = ['auth', 'verified', "permission:view-{$sport}-predictions"];

    Route::get("/{$sport}/teams/{team}", $controllers['team'])->middleware($sportDetailMiddleware);
    Route::get("/{$sport}/games/{game}", $controllers['game'])->middleware($sportDetailMiddleware);

    if (isset($controllers['player'])) {
        Route::get("/{$sport}/players/{player}", $controllers['player'])
            ->middleware($sportDetailMiddleware)
            ->name("{$sport}.player.show");
    }
}

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

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    foreach ([
        ['get', '/subscriptions', [AdminSubscriptionController::class, 'index'], 'subscriptions'],
        ['post', '/subscriptions/{user}/sync', [AdminSubscriptionController::class, 'sync'], 'subscriptions.sync'],
        ['post', '/subscriptions/{user}/assign-tier', [AdminSubscriptionController::class, 'assignTier'], 'subscriptions.assign-tier'],
        ['post', '/subscriptions/sync-all', [AdminSubscriptionController::class, 'syncAll'], 'subscriptions.sync-all'],
        ['get', '/permissions', [AdminPermissionController::class, 'index'], 'permissions'],
        ['get', '/healthchecks', [AdminHealthcheckController::class, 'index'], 'healthchecks'],
        ['post', '/healthchecks/run', [AdminHealthcheckController::class, 'run'], 'healthchecks.run'],
        ['post', '/healthchecks/sync', [AdminHealthcheckController::class, 'sync'], 'healthchecks.sync'],
    ] as [$method, $uri, $action, $name]) {
        Route::$method($uri, $action)->name($name);
    }

    Route::resource('tiers', AdminTierController::class)->except(['show']);
    Route::resource('notification-templates', AdminNotificationTemplateController::class)->except(['show']);
});

Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])->name('cashier.webhook');

require __DIR__.'/settings.php';
