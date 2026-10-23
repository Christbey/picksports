<?php

use App\Models\NFL\PlayerStat;
use App\Services\BettingRecommendations\NflPropOpportunityProjection;

it('responds to a new workload while estimating efficiency over the longer window', function () {
    $history = collect(range(1, 10))->map(fn ($id) => new PlayerStat([
        'game_id' => $id, 'rushing_attempts' => $id <= 5 ? 20 : 5,
        'rushing_yards' => $id <= 5 ? 80 : 30,
    ]));
    $projection = (new NflPropOpportunityProjection)->project($history, 'player_rush_yds');
    expect($projection['expected_opportunities'])->toBe(20.0)
        ->and($projection['production_per_opportunity'])->toBeGreaterThan(4)
        ->and($projection['baseline_mean'])->toBeGreaterThan(80)
        ->and($projection['baseline_mean'])->toBeLessThan(120)
        ->and($projection['volume_games'])->toBe(5)
        ->and($projection['game_ids'])->toBe(range(1, 10));
});

it('keeps reported zeroes and exposes missing targets instead of substituting catches for targets', function () {
    $history = collect([
        new PlayerStat(['game_id' => 1, 'receiving_targets' => null, 'receptions' => 9]),
        new PlayerStat(['game_id' => 2, 'receiving_targets' => 0, 'receptions' => 0]),
        new PlayerStat(['game_id' => 3, 'receiving_targets' => 4, 'receptions' => 2]),
    ]);
    $projection = (new NflPropOpportunityProjection)->project($history, 'player_receptions');
    expect($projection['missing_opportunity_games'])->toBe(1)
        ->and($projection['games'])->toBe(2)
        ->and($projection['game_ids'])->toBe([2, 3])
        ->and($projection['production_per_opportunity'])->toBe(0.5);
});

it('does not invent receiving volume when every target field is missing', function () {
    expect((new NflPropOpportunityProjection)->project(collect([
        new PlayerStat(['receptions' => 4]),
    ]), 'player_receptions'))->toBeNull();
});
