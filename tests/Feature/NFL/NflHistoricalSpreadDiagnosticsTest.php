<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\ProviderSourceFile;
use App\Services\NFL\NflHistoricalSpreadDiagnostics;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('freezes training before evaluation and separates model versions', function () {
    $training = array_fill(0, 200, ['season' => 2023, 'model_version' => 'old', 'model_margin' => 5.0, 'actual_margin' => 5.0, 'home_line' => -3.0]);
    $evaluation = ['season' => 2024, 'model_version' => 'old', 'model_margin' => 5.0, 'actual_margin' => -10.0, 'home_line' => -3.0];
    $rows = [...$training, $evaluation, [...$evaluation, 'model_version' => 'new']];
    $report = app(NflHistoricalSpreadDiagnostics::class)->evaluate($rows, 2024);

    expect($report['promotion_allowed'])->toBeFalse()
        ->and($report['independent_holdout_verified'])->toBeFalse()
        ->and($report['models']['old'])->toMatchArray(['training_games' => 200, 'evaluation_games' => 1, 'losses' => 1, 'conditional_cover_brier' => 1.0])
        ->and($report['models']['new'])->toMatchArray(['training_games' => 0, 'probability_games' => 0, 'conditional_cover_brier' => null]);
});

it('audits archived closing lines with verified game identity and correct spread direction', function () {
    Storage::fake('provider-local');
    $csv = "game_id,espn,season,game_type,week,gameday,home_team,away_team,home_score,away_score,spread_line\n2024_01_KC_NE,123,2024,REG,1,2024-09-08,NE,KC,24,21,2.5\n";
    Storage::disk('provider-local')->put('games.csv', $csv);
    $source = ProviderSourceFile::factory()->create(['object_key' => 'games.csv', 'sha256' => hash('sha256', $csv)]);
    $game = Game::factory()->create(['espn_event_id' => '123', 'season' => 2024, 'season_type' => '2', 'week' => 1,
        'home_team_id' => Team::factory()->create(['abbreviation' => 'NE'])->id,
        'away_team_id' => Team::factory()->create(['abbreviation' => 'KC'])->id,
        'game_date' => '2024-09-08', 'game_time' => '17:00:00', 'home_score' => 24, 'away_score' => 21, 'status' => 'STATUS_FINAL']);
    $prediction = Prediction::factory()->create(['game_id' => $game->id, 'model_version' => 'historical']);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nfl', ['predicted_spread' => 5, 'model_version' => 'historical'],
        ['generated_at' => '2026-07-30', 'point_in_time_verified' => true, 'run_type' => 'historical_reconstruction']);
    $before = $prediction->fresh()->getAttributes();
    expect(Artisan::call('nfl:audit-historical-spreads', ['--source-id' => $source->id, '--evaluation-season' => 2024]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['matched_games'])->toBe(1)
        ->and($report['models']['historical']['wins'])->toBe(1)
        ->and($report['models']['historical']['market_mae'])->toBe(0.5)
        ->and($prediction->fresh()->getAttributes())->toBe($before);

    $game->update(['home_score' => 25]);
    expect(Artisan::call('nfl:audit-historical-spreads', ['--source-id' => $source->id, '--evaluation-season' => 2024]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['excluded']['source_identity_or_score_mismatch'])->toBe(1);

    Storage::disk('provider-local')->put('games.csv', 'corrupted');
    $this->artisan('nfl:audit-historical-spreads', ['--source-id' => $source->id])->expectsOutput('Archived source checksum mismatch.')->assertFailed();
});
