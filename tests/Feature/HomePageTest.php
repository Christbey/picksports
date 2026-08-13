<?php

use App\Services\PerformanceStatistics;
use Inertia\Testing\AssertableInertia as Assert;

test('home page presents measured performance without synthetic fallback data', function () {
    $zeroOverall = [
        'total_predictions' => 0,
        'winner_accuracy' => 0,
        'avg_spread_error' => 0,
        'avg_total_error' => 0,
        'win_record' => '0-0',
    ];
    $zeroRoi = [
        'total_bets' => 0,
        'total_wins' => 0,
        'total_losses' => 0,
        'total_wagered' => 0,
        'total_profit' => 0,
        'roi_percentage' => 0,
        'win_percentage' => 0,
        'verified' => true,
        'methodology' => 'settled_pregame_bet_decisions',
    ];

    $performance = Mockery::mock(PerformanceStatistics::class);
    $performance->shouldReceive('getOverallStats')->once()->andReturn($zeroOverall);
    $performance->shouldReceive('getRecentPerformance')->once()->andReturn([
        'overall' => $zeroOverall,
        'by_sport' => [],
        'roi' => $zeroRoi,
    ]);
    $performance->shouldReceive('calculateROI')->once()->andReturn($zeroRoi);
    app()->instance(PerformanceStatistics::class, $performance);

    $this->withoutVite();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('performance.overall.total_predictions', 0)
            ->where('performance.overall.win_record', '0-0')
            ->where('performance.recent.overall.total_predictions', 0)
            ->where('performance.roi.total_bets', 0)
        );
});
