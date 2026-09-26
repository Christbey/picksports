<?php

use App\Models\EventInputSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\NFL\NflFrozenSpreadReplay;
use App\Services\NFL\NflFrozenSpreadReplayAudit;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;

function frozenSpreadMetadata(): array
{
    $m = ['legacy' => ['spread' => 5.0], 'blended' => ['spread' => 5.0]];
    foreach (['preseason_signal', 'rolling_efficiency', 'opponent_adjusted_efficiency', 'qb_form', 'line_matchup',
        'contextual_factors', 'depth_chart_injuries', 'adaptive_point_calibration', 'market_blend'] as $key) {
        $m[$key] = ['enabled' => false, 'applied' => false];
    }

    return $m;
}

it('reproduces the published spread before grading revised policy', function () {
    $m = frozenSpreadMetadata();
    $m['adaptive_point_calibration'] = ['enabled' => true, 'applied' => true, 'reason' => 'calibrated', 'spread_adjustment' => 1.0];
    $m['market_blend'] = ['enabled' => true, 'applied' => true, 'market_spread' => 3.0, 'spread_model_weight' => 0.5];
    $replay = new NflFrozenSpreadReplay;
    expect($replay->replay($m, 4.5))->toMatchArray(['status' => 'replayed', 'original_margin' => 4.5, 'revised_margin' => 4.0])
        ->and($replay->replay($m, 7.0))->toBe(['status' => 'excluded', 'reason' => 'original_forecast_not_reproduced']);
});

it('requires archived fallback evidence when removing EPA enables preseason blending', function () {
    $m = frozenSpreadMetadata();
    $m['blended']['spread'] = 9.0;
    $m['preseason_signal'] = ['enabled' => true, 'applied' => false, 'reason' => 'true_epa_already_applied'];
    $replay = new NflFrozenSpreadReplay;
    expect($replay->replay($m, 9.0))->toBe(['status' => 'excluded', 'reason' => 'missing_pregame_preseason_ratings'])
        ->and($replay->replay($m, 9.0, ['signal_spread' => 1.0]))->toMatchArray(['status' => 'replayed', 'revised_margin' => 4.0]);
});

it('keeps zero-adjustment injury rounding and stage clamps', function () {
    $m = frozenSpreadMetadata();
    $m['legacy']['spread'] = $m['blended']['spread'] = 16.16;
    $m['depth_chart_injuries'] = ['enabled' => true, 'applied' => false, 'spread_adjustment' => 0.0];
    $m['market_blend'] = ['enabled' => true, 'applied' => true, 'market_spread' => 0.0, 'spread_model_weight' => 0.5];
    expect((new NflFrozenSpreadReplay)->replay($m, 8.1))->toMatchArray(['status' => 'replayed', 'revised_margin' => 8.1]);
    $m['contextual_factors'] = ['enabled' => true, 'applied' => false, 'spread_adjustment' => 0.0];
    expect((new NflFrozenSpreadReplay)->replay($m, 7.5))->toMatchArray(['status' => 'replayed', 'revised_margin' => 7.5]);
});

it('uses the weaker team sample only for previously active efficiency layers', function () {
    $m = frozenSpreadMetadata();
    $m['rolling_efficiency'] = ['enabled' => true, 'applied' => true, 'weight' => 0.5, 'signal_spread' => 1,
        'home' => ['games' => 8], 'away' => ['games' => 2]];
    expect((new NflFrozenSpreadReplay)->replay($m, 3))->toMatchArray(['status' => 'replayed', 'revised_margin' => 4.6]);
});

it('fails closed on missing and invalid numeric evidence', function ($key, $value) {
    $m = frozenSpreadMetadata();
    data_set($m, $key, $value);
    expect((new NflFrozenSpreadReplay)->replay($m, 5)['status'])->toBe('excluded');
})->with([['legacy.spread', null], ['legacy.spread', 'null'], ['legacy.spread', INF], ['qb_form', null]]);

function frozenRecoveryInput(): EventInputSnapshot
{
    return (new EventInputSnapshot)->forceFill(['id' => 1, 'sport' => 'nfl', 'phase' => 'pregame', 'pregame_safety_status' => 'verified',
        'sport_event_id' => 9, 'captured_at' => '2026-09-20 15:00:00', 'latest_source_available_at' => '2026-09-20 14:00:00',
        'home_team_id' => 1, 'away_team_id' => 2, 'home_season' => 2026, 'away_season' => 2026, 'season_type' => '2',
        'home_rating' => 4, 'away_rating' => 2]);
}

it('recovers only verified same-event same-season inputs available before the original forecast', function ($changes, $valid) {
    $input = frozenRecoveryInput()->forceFill($changes);
    $game = new Game(['sport_event_id' => 9, 'home_team_id' => 1, 'away_team_id' => 2, 'season' => 2026]);
    $result = (new NflFrozenSpreadReplayAudit)->recoverPreseason($input, $game,
        ['generated_at' => '2026-09-20T16:00:00-05:00', 'kickoff' => '2026-09-20T17:00:00-05:00'], ['home_field_advantage' => 25]);
    if ($valid) {
        expect($result)->toMatchArray(['signal_spread' => 4.25, 'age_hours' => 1.0]);
    } else {
        expect($result)->toBeNull();
    }
})->with([
    'valid' => [[], true],
    'future capture' => [['captured_at' => '2026-09-20 16:00:01'], false],
    'future source' => [['latest_source_available_at' => '2026-09-20 15:00:01'], false],
    'missing source' => [['latest_source_available_at' => null], false],
    'wrong teams' => [['home_team_id' => 3], false],
    'previous season' => [['away_season' => 2025], false],
    'wrong event' => [['sport_event_id' => 10], false],
    'unverified' => [['pregame_safety_status' => 'unknown'], false],
    'missing metric' => [['home_rating' => 'null'], false],
    'nonfinite metric' => [['home_rating' => INF], false],
    'postseason metric' => [['season_type' => '3'], false],
]);

it('rejects invalid command scope and an empty replay', function () {
    $this->artisan('nfl:replay-frozen-spreads', ['--season' => 'no'])->assertExitCode(2);
    $this->artisan('nfl:replay-frozen-spreads', ['--through-week' => 19])->assertExitCode(2);
    $this->artisan('nfl:replay-frozen-spreads', ['--season' => 2026])->assertFailed();
});

it('replays production regression fixtures without silently relaxing reproduction tolerance', function () {
    $fixtures = collect(json_decode(file_get_contents(base_path('tests/Fixtures/nfl-frozen-spread-replay-2026.json')), true, flags: JSON_THROW_ON_ERROR))->keyBy('snapshot_id');
    $replay = new NflFrozenSpreadReplay;
    expect($replay->replay($fixtures[182164]['metadata'], 8.9))->toMatchArray(['status' => 'replayed', 'revised_margin' => 8.9])
        ->and($replay->replay($fixtures[182174]['metadata'], -0.1))->toMatchArray(['status' => 'excluded', 'reason' => 'original_forecast_not_reproduced'])
        ->and($replay->replay($fixtures[190049]['metadata'], 6.5, ['signal_spread' => -22.794]))->toMatchArray(['status' => 'replayed', 'revised_margin' => 4.8]);
});

it('loads preforecast evidence and ignores future inputs and mutable odds without writing records', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'starts_at' => '2026-09-20 17:00:00']);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'season_type' => '2', 'week' => 2,
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_FINAL', 'game_date' => '2026-09-20', 'game_time' => '17:00:00',
        'home_score' => 20, 'away_score' => 32, 'odds_data' => ['vegas_spread' => -45]]);
    $game->forceFill(['result_updated_at' => '2026-09-20 21:00:00'])->saveQuietly();
    $fixture = collect(json_decode(file_get_contents(base_path('tests/Fixtures/nfl-frozen-spread-replay-2026.json')), true))->firstWhere('snapshot_id', 190049);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nfl', [
        'predicted_spread' => 6.5, 'vegas_spread' => -6.5, 'model_metadata' => $fixture['metadata'],
    ], ['generated_at' => '2026-09-20 16:00:00', 'features_available_at' => '2026-09-20 16:00:00',
        'model_version' => 'nfl-historical-elo-v2-career-regular-v2', 'features' => ['home_field_advantage' => 25]]);
    $inputs = ['event' => ['season_type' => '2'],
        'home' => ['team_id' => $game->home_team_id, 'metrics' => ['record_season' => 2026, 'predictive_rating' => -25.044]],
        'away' => ['team_id' => $game->away_team_id, 'metrics' => ['record_season' => 2026, 'predictive_rating' => 0]]];
    $archived = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nfl',
        'captured_at' => '2026-09-20 15:00:00', 'latest_source_available_at' => '2026-09-20 14:00:00', 'inputs' => $inputs]);
    $inputs['home']['metrics']['predictive_rating'] = 100;
    EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nfl',
        'captured_at' => '2026-09-20 16:30:00', 'latest_source_available_at' => '2026-09-20 16:29:00', 'inputs' => $inputs,
        'content_hash' => hash('sha256', json_encode($inputs))]);
    $counts = [Prediction::count(), PredictionFeatureSnapshot::count(), EventInputSnapshot::count()];
    $report = (new NflFrozenSpreadReplayAudit)->run(2026, 2);
    expect($report['completed_games'])->toBe(1)
        ->and($report['games'][0])->toMatchArray(['status' => 'replayed', 'original_result' => 'no_pick', 'revised_result' => 'win', 'revised_margin' => 4.8])
        ->and($report['games'][0]['preseason_recovery']['input_snapshot_id'])->toBe($archived->id)
        ->and([Prediction::count(), PredictionFeatureSnapshot::count(), EventInputSnapshot::count()])->toBe($counts);
});
