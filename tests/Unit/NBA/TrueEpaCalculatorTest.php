<?php

use App\Services\Epa\StateBaselineService;
use App\Services\NBA\PlayEpaDataService;
use App\Services\NBA\TrueEpaCalculator;
use Illuminate\Support\Collection;

it('derives distinct expected points states for college-style 20-minute clocks', function () {
    $calculator = new TrueEpaCalculator(new PlayEpaDataService, new StateBaselineService);

    $plays = new Collection([
        (object) [
            'id' => 1,
            'possession_team_id' => 10,
            'period' => 1,
            'clock' => '19:30',
            'play_type' => 'jump shot',
            'play_text' => 'Made Jumper',
            'home_score' => 0,
            'away_score' => 0,
        ],
        (object) [
            'id' => 2,
            'possession_team_id' => 10,
            'period' => 1,
            'clock' => '10:00',
            'play_type' => 'jump shot',
            'play_text' => 'Made Jumper',
            'home_score' => 2,
            'away_score' => 0,
        ],
    ]);

    $results = $calculator->calculateForGame($plays, 10, 20);

    expect($results[1]['eligible'])->toBeTrue()
        ->and($results[2]['eligible'])->toBeTrue()
        ->and($results[1]['ep_before'])->toBe(2.0)
        ->and($results[2]['ep_before'])->toBe(0.0);
});
