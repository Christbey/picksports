<?php

use App\Http\Resources\BettingRecommendationResource;

it('keeps stored prop player links and freshness thresholds in their own sport', function (string $sport, string $namespace, int $freshness) {
    config(["validation.thresholds.player_prop_freshness.stale_after_hours_by_sport.{$sport}" => $freshness]);
    $propClass = "App\\Models\\{$namespace}\\PlayerProp";
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $prop = new $propClass(['player_id' => 42, 'player_name' => 'Stored Player', 'line' => 22.5]);
    $game = new $gameClass(['game_date' => '2026-11-17', 'game_time' => '23:00:00']);
    $game->setRelation('homeTeam', null)->setRelation('awayTeam', null);
    $resource = new BettingRecommendationResource([
        'prop' => $prop, 'game' => $game, 'market' => 'Points', 'line' => 22.5,
        'recommendation' => 'Over', 'odds' => -110, 'confidence' => 70,
        'season_avg' => 24, 'recent_avg' => 24, 'last5_avg' => 24,
        'edge' => 1.5, 'reasoning' => [],
    ]);
    $payload = $resource->resolve();

    expect($payload['player']['url'])->toBe($sport === 'wnba' ? null : route('cfb.player.show', 42))
        ->and($payload['freshness_hours'])->toBe($freshness);
})->with([
    'WNBA' => ['wnba', 'WNBA', 7],
    'CFB' => ['cfb', 'CFB', 23],
]);
