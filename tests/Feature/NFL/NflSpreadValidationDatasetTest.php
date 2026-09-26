<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\NFL\NflSpreadValidationDataset;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;

it('loads frozen forecasts against canonical starts and never uses mutable odds', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'starts_at' => '2025-09-07 17:00:00']);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'sport_event_id' => $event->id, 'season' => 2025, 'season_type' => '2', 'week' => 1,
        'status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 21,
        'game_date' => '2025-09-07', 'game_time' => '22:00:00',
        'result_updated_at' => '2025-09-07 21:00:00',
        'odds_data' => ['vegas_spread' => -45],
    ]);
    // Keep result availability deterministic despite model score-sync hooks.
    $game->forceFill(['result_updated_at' => '2025-09-07 21:00:00'])->saveQuietly();
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    $recorder = app(PredictionFeatureSnapshotRecorder::class);
    $snapshot = $recorder->record($prediction, $game, 'nfl', [
        'predicted_spread' => 5, 'vegas_spread' => -3,
        'model_metadata' => ['legacy' => ['spread' => 7]],
    ], ['generated_at' => '2025-09-07 15:00:00', 'features_available_at' => '2025-09-07 15:00:00']);
    // This is before the legacy (incorrect) 22:00 start, but after the canonical kickoff.
    $recorder->record($prediction, $game, 'nfl', ['predicted_spread' => 30, 'vegas_spread' => -25],
        ['generated_at' => '2025-09-07 18:00:00', 'features_available_at' => '2025-09-07 18:00:00']);

    $count = PredictionFeatureSnapshot::count();
    $data = app(NflSpreadValidationDataset::class)->load(2025, 2025);
    expect($data['games_seen'])->toBe(1)->and($data['rows'])->toHaveCount(1)
        ->and($data['rows'][0])->toMatchArray(['snapshot_id' => $snapshot->id, 'home_line' => -3.0, 'model_margin' => 5.0, 'elo_market_margin' => 5.0, 'validated_epa_market_margin' => null])
        ->and(PredictionFeatureSnapshot::count())->toBe($count);
    $this->artisan('nfl:validate-spread-models', ['--from-season' => 2025, '--to-season' => 2025, '--json' => true])
        ->assertSuccessful();
});

it('reports missing evidence and returns failure instead of a successful empty backtest', function () {
    Game::factory()->create(['season' => 2025, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'sport_event_id' => null,
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $data = app(NflSpreadValidationDataset::class)->load(2025, 2025);
    expect($data['rows'])->toBeEmpty()->and($data['excluded'])->toHaveKey('missing_canonical_start_or_result');
    $this->artisan('nfl:validate-spread-models', ['--from-season' => 2025, '--to-season' => 2025, '--json' => true])->assertFailed();
    $this->artisan('nfl:validate-spread-models', ['--from-season' => 2026, '--to-season' => 2025])->assertExitCode(2);
});
