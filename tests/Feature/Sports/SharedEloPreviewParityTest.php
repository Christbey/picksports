<?php

use App\Services\Api\V2\SportContextResolver;

it('preserves ordinary persisted rating changes when extracting shared pure Elo math', function (string $sport) {
    $context = app(SportContextResolver::class)->find($sport);
    $gameModel = $context->models['game'];
    $teamModel = $context->models['team'];
    $home = $teamModel::factory()->create(['elo_rating' => 1500]);
    $away = $teamModel::factory()->create(['elo_rating' => 1500]);
    $game = $gameModel::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL', 'home_score' => 90, 'away_score' => 80, 'season_type' => '2']);
    $class = 'App\\Actions\\'.strtoupper($sport).'\\CalculateElo';
    $calculator = new $class;
    $preview = $calculator->previewChanges($game, 1500, 1500);
    $result = $calculator->execute($game);
    expect($result['skipped'])->toBeFalse()
        ->and($result['home_change'])->toBe($preview['home_change'])
        ->and($result['away_change'])->toBe($preview['away_change'])
        ->and((float) $home->fresh()->elo_rating)->toBe((float) $preview['home_new_elo'])
        ->and((float) $away->fresh()->elo_rating)->toBe((float) $preview['away_new_elo']);
})->with(['nfl', 'nba', 'cbb', 'wcbb', 'wnba']);
