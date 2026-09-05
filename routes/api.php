<?php

use App\Http\Controllers\Api\Auth\PasskeyTokenAuthController;
use App\Http\Controllers\Api\Auth\TokenAuthController;
use App\Http\Controllers\Api\SecurityReportController;
use Illuminate\Support\Facades\Route;

// Load generic sport route definer
$registerSportRoutes = require base_path('routes/api/sports.php');

Route::prefix('v1')->name('v1.')->group(function () use ($registerSportRoutes) {
    $securityReportThrottle = app()->environment(['local', 'testing'])
        ? 'throttle:10000,1'
        : 'throttle:1000,1';

    Route::post('/security/reports/csp', [SecurityReportController::class, 'csp'])
        ->middleware($securityReportThrottle)
        ->name('security.reports.csp');
    Route::post('/security/reports/integrity', [SecurityReportController::class, 'integrity'])
        ->middleware($securityReportThrottle)
        ->name('security.reports.integrity');

    Route::prefix('auth')->name('auth.')->middleware('v1.auth-api-usage')->group(function (): void {
        Route::post('/login', [TokenAuthController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('login');
        Route::post('/passkeys/options', [PasskeyTokenAuthController::class, 'options'])
            ->middleware('throttle:20,1')
            ->name('passkeys.createOptions');
        Route::post('/passkeys/verify', [PasskeyTokenAuthController::class, 'verify'])
            ->middleware('throttle:10,1')
            ->name('passkeys.verify');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/me', [TokenAuthController::class, 'me'])->name('me');
            Route::post('/logout', [TokenAuthController::class, 'logout'])->name('logout');
            Route::post('/logout-all', [TokenAuthController::class, 'logoutAll'])->name('logout-all');
        });
    });

    Route::middleware('v1.api-usage')->group(function () use ($registerSportRoutes): void {
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
            'cbb-brackets' => 'routes/api/cbb-brackets.php',
            'groups' => 'routes/api/groups.php',
        ] as $prefix => $file) {
            Route::middleware('auth:sanctum')
                ->prefix($prefix)
                ->name("{$prefix}.")
                ->group(base_path($file));
        }
    });

});

require base_path('routes/api-v2.php');
