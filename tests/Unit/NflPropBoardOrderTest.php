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

it('ranks price-aware expected return ahead of a higher raw probability', function () {
    $now = Carbon::parse('2026-10-10T18:00:00-05:00');
    $make = fn ($id, $probability, $price) => (new PlayerProp)->forceFill([
        'id' => $id, 'predicted_over_probability' => $probability, 'recommended_side' => 'Over', 'over_price' => $price,
        'fetched_at' => $now, 'confidence_score' => 70, 'data_quality_score' => 70,
        'confidence_decomposition' => ['history' => ['current_season_games' => 6]],
    ]);
    // 80% at -500 loses money; 60% at +100 has a positive modeled return.
    $rows = collect([$make(1, 80, -500), $make(2, 60, 100)]);
    expect($rows->sortByDesc(fn ($prop) => NflPropBoardOrder::key($prop, $now->timestamp, 36))->pluck('id')->all())->toBe([2, 1]);
});
