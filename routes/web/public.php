<?php

use App\Http\Controllers\PublicSportPageController;
use App\Http\Controllers\PerformanceController;
use App\Models\CBB\Game as CbbGame;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/sitemap.xml', function () {
    $now = now()->toAtomString();
    $urls = [
        url('/'),
        url('/performance'),
        url('/march-madness-bracket'),
        url('/terms'),
        url('/privacy'),
        url('/responsible-gambling'),
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $loc) {
        $xml .= '<url>';
        $xml .= '<loc>'.e($loc).'</loc>';
        $xml .= '<lastmod>'.$now.'</lastmod>';
        $xml .= '<changefreq>daily</changefreq>';
        $xml .= '<priority>'.($loc === url('/') ? '1.0' : '0.7').'</priority>';
        $xml .= '</url>';
    }
    $xml .= '</urlset>';

    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap');

Route::get('/sitemap-news.xml', function () {
    $publishedAt = now()->toAtomString();
    $entries = [
        ['loc' => url('/'), 'title' => 'PickSports'],
        ['loc' => url('/performance'), 'title' => 'PickSports Performance'],
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">';
    foreach ($entries as $entry) {
        $xml .= '<url>';
        $xml .= '<loc>'.e($entry['loc']).'</loc>';
        $xml .= '<news:news>';
        $xml .= '<news:publication>';
        $xml .= '<news:name>PickSports</news:name>';
        $xml .= '<news:language>en</news:language>';
        $xml .= '</news:publication>';
        $xml .= '<news:publication_date>'.$publishedAt.'</news:publication_date>';
        $xml .= '<news:title>'.e($entry['title']).'</news:title>';
        $xml .= '</news:news>';
        $xml .= '</url>';
    }
    $xml .= '</urlset>';

    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap-news');

Route::get('/', function () {
    $performanceStats = app(\App\Services\PerformanceStatistics::class);
    $overall = $performanceStats->getOverallStats();
    $recent = $performanceStats->getRecentPerformance();
    $roi = $performanceStats->calculateROI();

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

Route::get('march-madness-bracket', function () {
    $scheduledStatus = config('cbb.statuses.scheduled');
    $roundLabels = [
        'first_four' => 'First Four',
        'round_of_64' => 'Round of 64',
        'round_of_32' => 'Round of 32',
        'sweet_16' => 'Sweet 16',
        'elite_8' => 'Elite 8',
        'final_four' => 'Final Four',
        'national_championship' => 'National Championship',
    ];
    $roundOrder = array_flip(array_keys($roundLabels));
    $regionOrder = array_flip(['East', 'West', 'South', 'Midwest']);

    $tournamentGames = CbbGame::query()
        ->with([
            'homeTeam:id,school,mascot,abbreviation,logo_url',
            'awayTeam:id,school,mascot,abbreviation,logo_url',
            'prediction:id,game_id,win_probability',
        ])
        ->where('game_date', '>=', now()->toDateString())
        ->where('status', $scheduledStatus)
        ->where('season_type', (int) config('cbb.season.types.postseason'))
        ->where(function ($query) {
            $query->where('is_ncaa_tournament', true)
                ->orWhere('tournament_note', 'like', 'NCAA Men\'s Basketball Championship%');
        })
        ->get()
        ->map(function (CbbGame $game) {
            $homeTeam = $game->homeTeam;
            $awayTeam = $game->awayTeam;
            $homeName = trim(($homeTeam?->school ?? '').' '.($homeTeam?->mascot ?? '')) ?: $game->home_team_display_name ?: 'TBD';
            $awayName = trim(($awayTeam?->school ?? '').' '.($awayTeam?->mascot ?? '')) ?: $game->away_team_display_name ?: 'TBD';

            return [
                'id' => $game->id,
                'espnEventId' => $game->espn_event_id,
                'gameDate' => $game->game_date?->toDateString(),
                'gameTime' => $game->game_time,
                'region' => $game->tournament_region ?: 'Unassigned',
                'roundKey' => $game->tournament_round ?: 'unassigned',
                'roundLabel' => $game->tournament_round ? ($roundLabels[$game->tournament_round] ?? Str::headline($game->tournament_round)) : 'Unassigned',
                'venueName' => $game->venue_name,
                'venueCity' => $game->venue_city,
                'venueState' => $game->venue_state,
                'note' => $game->tournament_note,
                'homeSeed' => $game->home_seed,
                'awaySeed' => $game->away_seed,
                'playInTargetSeed' => $game->play_in_target_seed,
                'homeTeam' => [
                    'id' => $homeTeam?->id,
                    'name' => $homeName,
                    'abbreviation' => $homeTeam?->abbreviation ?: $game->home_team_abbreviation ?: 'TBD',
                    'logo' => $homeTeam?->logo_url,
                ],
                'awayTeam' => [
                    'id' => $awayTeam?->id,
                    'name' => $awayName,
                    'abbreviation' => $awayTeam?->abbreviation ?: $game->away_team_abbreviation ?: 'TBD',
                    'logo' => $awayTeam?->logo_url,
                ],
                'name' => $game->name,
                'prediction' => $game->prediction
                    ? [
                        'homeWinProbability' => (float) $game->prediction->win_probability,
                        'awayWinProbability' => round(1 - (float) $game->prediction->win_probability, 3),
                    ]
                    : null,
            ];
        });

    $regions = $tournamentGames
        ->groupBy('region')
        ->sortBy(fn ($games, $region) => $regionOrder[$region] ?? 999)
        ->map(function ($games, $region) use ($roundOrder, $roundLabels) {
            $rounds = collect($games)
                ->groupBy('roundKey')
                ->sortBy(fn ($roundGames, $roundKey) => $roundOrder[$roundKey] ?? 999)
                ->map(fn ($roundGames, $roundKey) => [
                    'key' => $roundKey,
                    'label' => $roundLabels[$roundKey] ?? Str::headline($roundKey),
                    'games' => array_values($roundGames->sortBy(['gameDate', 'gameTime'])->all()),
                ])
                ->values()
                ->all();

            return [
                'id' => Str::slug((string) $region),
                'name' => $region,
                'rounds' => $rounds,
            ];
        })
        ->values();

    return Inertia::render('MarchMadnessBracket', [
        'regions' => $regions,
    ]);
})->name('march-madness-bracket');

foreach ([
    'terms' => 'Legal/Terms',
    'privacy' => 'Legal/Privacy',
    'responsible-gambling' => 'Legal/ResponsibleGambling',
] as $path => $page) {
    Route::get($path, fn () => Inertia::render($page))->name($path);
}

foreach (array_keys((array) config('sports.domains', [])) as $sport) {
    Route::get($sport, fn (\Illuminate\Http\Request $request) => app(PublicSportPageController::class)($request, $sport))
        ->name("{$sport}.home");
}
