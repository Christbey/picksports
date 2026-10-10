<?php

use App\Models\NFL\PlayerProp;
use App\Services\BettingRecommendations\NflPropBoardOrder;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

test('nfl board prioritizes supported fresh side probabilities rather than edge scores', function () {
    $now = Carbon::parse('2026-10-10T18:00:00-05:00');
    $make = function ($id, $over, $side = 'Over', $hours = 2, $games = 4, $confidence = 60) use ($now) {
        $prop = new PlayerProp;
        $prop->forceFill([
            'id' => $id,
            'predicted_over_probability' => $over,
            'recommended_side' => $side,
            'fetched_at' => $now->copy()->subHours($hours),
            'confidence_score' => $confidence,
            'data_quality_score' => 70,
            'confidence_decomposition' => ['history' => ['current_season_games' => $games]],
        ]);

        return $prop;
    };
    $rows = collect([
        $make(1, 55, confidence: 99),
        $make(2, 20, 'Under', hours: 30),
        $make(3, 75),
        $make(4, 99, hours: 37),
        $make(5, 99, games: 1),
        $make(6, null),
        $make(7, 101),
        $make(8, 99, hours: -1),
    ]);
    $sorted = $rows->sortByDesc(fn ($prop) => NflPropBoardOrder::key($prop, $now->timestamp, 36))->values();

    expect($sorted->pluck('id')->all())->toBe([2, 3, 1, 5, 6, 7, 4, 8]);
});
