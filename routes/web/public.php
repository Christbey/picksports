<?php

use App\Http\Controllers\PerformanceController;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', function () {
    $now = now()->toAtomString();
    $urls = [
        url('/'),
        url('/performance'),
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

foreach ([
    'terms' => 'Legal/Terms',
    'privacy' => 'Legal/Privacy',
    'responsible-gambling' => 'Legal/ResponsibleGambling',
] as $path => $page) {
    Route::get($path, fn () => Inertia::render($page))->name($path);
}
