<?php

use App\Http\Controllers\Api\SecurityReportController;
use Illuminate\Support\Facades\Route;

// Load generic sport route definer
$registerSportRoutes = require base_path('routes/api/sports.php');

Route::prefix('v1')->group(function () use ($registerSportRoutes) {
    $securityReportThrottle = app()->environment(['local', 'testing'])
        ? 'throttle:10000,1'
        : 'throttle:1000,1';

    Route::post('/security/reports/csp', [SecurityReportController::class, 'csp'])
        ->middleware($securityReportThrottle);
    Route::post('/security/reports/integrity', [SecurityReportController::class, 'integrity'])
        ->middleware($securityReportThrottle);

    // Sport Routes (using generic route definer)
    $sports = (array) config('sports.domains', []);
    foreach ($sports as $sport => $definition) {
        $namespace = (string) ($definition['namespace'] ?? '');
        if ($namespace === '') {
            continue;
        }

        Route::prefix($sport)->name("{$sport}.")->group(fn () => $registerSportRoutes($sport, $namespace));
    }

    foreach ([
        'user-bets' => 'routes/api/user-bets.php',
        'alert-preferences' => 'routes/api/alert-preferences.php',
    ] as $prefix => $file) {
        Route::middleware('auth:sanctum')
            ->prefix($prefix)
            ->name("{$prefix}.")
            ->group(base_path($file));
    }

});
