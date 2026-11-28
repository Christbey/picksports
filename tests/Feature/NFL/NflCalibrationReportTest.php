<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\NflCalibrationReport;
use App\Services\Predictions\SnapshotProvenanceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('uses the last valid pregame observation once per game and excludes incompatible evidence', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 21:00:00', 'America/Chicago'));
    $game = Game::factory()->create(['season' => 2026, 'season_type' => 2,
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'game_date' => '2026-10-18', 'game_time' => '12:00:00', 'status' => 'STATUS_FINAL',
        'home_score' => 24, 'away_score' => 17]);
    $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game)->setTimezone(config('app.timezone'));
    $base = ['sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => 1,
        'game_id' => $game->id, 'model_version' => 'report-test', 'feature_version' => 'test',
        'blend_version' => 'test', 'features' => [],
        'outputs' => ['predicted_spread' => 7, 'predicted_total' => 41, 'win_probability' => .8],
        'game_start_at' => $kickoff, 'generated_at' => $kickoff->subHour(),
        'features_available_at' => $kickoff->subHours(2), 'pregame_safe' => true,
        'availability_status' => 'observed_pregame'];
    foreach ([[], ['generated_at' => $kickoff->subMinutes(30)],
        ['generated_at' => $kickoff->addMinute()], ['availability_status' => 'verified_reconstruction'],
        ['model_version' => 'other']] as $override) {
        PredictionFeatureSnapshot::create([...$base, 'snapshot_run_id' => (string) Str::uuid(),
            'feature_hash' => hash('sha256', (string) Str::uuid()), ...$override]);
    }
    $result = app(NflCalibrationReport::class)->build(2026, 'report-test', CarbonImmutable::now());
    expect($result['snapshot_count'])->toBe(4)->and($result['completed_games'])->toBe(1)
        ->and($result['excluded_snapshots'])->toBe(['invalid_provenance' => 2, 'duplicate_game' => 1])
        ->and($result['moneyline']['brier'])->toBe(.04)->and($result['calibrated'])->toBeFalse();
    $historical = app(NflCalibrationReport::class)->build(2026, 'report-test', $kickoff->addHour());
    expect($historical['completed_games'])->toBe(0)->and($historical['pending_games'])->toBe(1);
    $game->update(['status' => 'STATUS_IN_PROGRESS']);
    $pending = app(NflCalibrationReport::class)->build(2026, 'report-test', CarbonImmutable::now());
    expect($pending['completed_games'])->toBe(0)->and($pending['pending_games'])->toBe(1);
    $empty = app(NflCalibrationReport::class)->build(2025, 'report-test', CarbonImmutable::now());
    expect($empty['snapshot_count'])->toBe(0)->and($empty['moneyline']['brier'])->toBeNull();
});

it('rejects invalid season input without querying evidence', function () {
    $this->artisan('nfl:report-calibration', ['--season' => 'garbage'])->assertFailed();
});
