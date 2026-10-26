<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Jobs\NFL\EvaluateShadowPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamStat;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\ShadowModelOutputRecorder;
use App\Services\NFL\NflCalibrationHistory;
use App\Services\NFL\NflFullHistoricalShadowInferenceService;
use App\Services\NFL\NflModelMonitoringService;
use App\Services\NFL\NflSeasonEvidenceWeight;
use App\Services\NFL\NflTeamHistory;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Support\NflModelVersion;
use App\Support\NflTeamAlignment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-25 08:00:00');
});

function nflIntegrityGame(array $attributes = []): Game
{
    return Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => '2026-10-25',
        'game_time' => '17:00:00', 'status' => 'STATUS_SCHEDULED', ...$attributes,
    ])->fresh(['homeTeam', 'awayTeam']);
}

function nflIntegritySnapshot(Game $game, array $attributes = []): PredictionFeatureSnapshot
{
    return PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => 1,
        'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(),
        'model_version' => NflModelVersion::current(), 'feature_version' => 'test', 'blend_version' => 'test',
        'features' => [], 'outputs' => ['predicted_spread' => 7, 'predicted_total' => 50, 'win_probability' => .7],
        'feature_hash' => hash('sha256', (string) Str::uuid()),
        'generated_at' => $game->game_date->copy()->subDay(),
        'game_start_at' => $game->game_date->copy()->setTime(17, 0),
        'features_available_at' => $game->game_date->copy()->subDay(),
        'pregame_safe' => true, 'availability_status' => 'observed_pregame', ...$attributes,
    ]);
}

it('uses one frozen compatible pregame forecast per completed regular season game', function () {
    $target = nflIntegrityGame();
    $prior = nflIntegrityGame(['game_date' => '2026-10-10', 'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 17]);
    nflIntegritySnapshot($prior, ['outputs' => ['predicted_spread' => 3, 'predicted_total' => 40, 'win_probability' => .6]]);
    nflIntegritySnapshot($prior, ['generated_at' => '2026-10-10 11:00:00']);
    nflIntegritySnapshot($prior, ['availability_status' => 'verified_reconstruction', 'generated_at' => '2026-10-11']);
    nflIntegritySnapshot($prior, ['model_version' => 'other-model']);
    $history = new NflCalibrationHistory;
    $rows = $history->prior($target, NflModelVersion::current(), 512);
    expect($rows)->toHaveCount(1)->and((float) $rows[0]->predicted_total)->toBe(50.0)
        ->and($rows[0]->game->id)->toBe($prior->id);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $history->prior($target, NflModelVersion::current(), 384);
    expect(DB::getQueryLog())->toBeEmpty();
    DB::disableQueryLog();
});

it('rejects incompatible unsafe late and preseason calibration evidence', function (array $gameAttributes, array $snapshotAttributes) {
    $target = nflIntegrityGame();
    $prior = nflIntegrityGame(['game_date' => '2026-10-10', 'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 17, ...$gameAttributes]);
    nflIntegritySnapshot($prior, $snapshotAttributes);
    expect((new NflCalibrationHistory)->prior($target, NflModelVersion::current(), 512))->toBeEmpty();
})->with([
    'preseason' => [['season_type' => '1'], []],
    'other version' => [[], ['model_version' => 'old-model']],
    'reconstruction' => [[], ['availability_status' => 'verified_reconstruction']],
    'unsafe' => [[], ['pregame_safe' => false]],
    'late generation' => [[], ['generated_at' => '2026-10-11']],
    'late features' => [[], ['features_available_at' => '2026-10-11']],
    'incorrect future kickoff' => [[], ['game_start_at' => '2026-10-12', 'generated_at' => '2026-10-11']],
    'missing kickoff' => [[], ['game_start_at' => null]],
    'incomplete outputs' => [[], ['outputs' => ['win_probability' => .7]]],
]);

it('rolls back forecast publication when its evidence snapshot fails', function (bool $existing) {
    Bus::fake();
    $game = nflIntegrityGame();
    if ($existing) {
        Prediction::query()->create(['game_id' => $game->id, 'home_elo' => 1500, 'away_elo' => 1500,
            'predicted_spread' => 9, 'predicted_total' => 40, 'win_probability' => .7, 'confidence_score' => 70]);
    }
    $this->mock(PredictionFeatureSnapshotRecorder::class)->shouldReceive('record')->once()
        ->andThrow(new RuntimeException('Snapshot unavailable'));
    expect(fn () => app(GeneratePredictionFromHistoricalElo::class)->execute($game))->toThrow(RuntimeException::class, 'Snapshot unavailable');
    if ($existing) {
        expect((float) Prediction::query()->where('game_id', $game->id)->value('predicted_spread'))->toBe(9.0);
    } else {
        expect(Prediction::query()->where('game_id', $game->id)->exists())->toBeFalse();
    }
    Bus::assertNotDispatched(EvaluateShadowPrediction::class);
})->with([false, true]);

it('keeps the committed forecast when optional shadow evaluation throws', function () {
    $game = nflIntegrityGame();
    $this->mock(NflFullHistoricalShadowInferenceService::class)->shouldReceive('evaluate')->once()
        ->andThrow(new RuntimeException('Optional challenger failed'));
    expect(app(GeneratePredictionFromHistoricalElo::class)->execute($game))->toBe('created')
        ->and(Prediction::query()->where('game_id', $game->id)->exists())->toBeTrue()
        ->and(PredictionFeatureSnapshot::query()->where('game_id', $game->id)->exists())->toBeTrue();
});

it('never lets a delayed shadow job overwrite a newer forecast or frozen snapshot', function () {
    $game = nflIntegrityGame();
    $prediction = Prediction::query()->create(['game_id' => $game->id, 'home_elo' => 1500, 'away_elo' => 1500,
        'predicted_spread' => 9, 'predicted_total' => 40, 'win_probability' => .7, 'confidence_score' => 70,
        'model_version' => NflModelVersion::current(), 'model_metadata' => ['generation' => 'new']]);
    $old = nflIntegritySnapshot($game, ['prediction_id' => $prediction->id]);
    nflIntegritySnapshot($game, ['prediction_id' => $prediction->id]);
    $inference = Mockery::mock(NflFullHistoricalShadowInferenceService::class);
    $inference->shouldReceive('evaluate')->once()->andReturn(['challenger_outputs' => ['predicted_spread' => 2]]);
    $recorder = Mockery::mock(ShadowModelOutputRecorder::class);
    $recorder->shouldReceive('record')->once()->andReturnNull();
    (new EvaluateShadowPrediction($old->id))->handle($inference, $recorder);
    expect($prediction->refresh()->model_metadata)->toBe(['generation' => 'new'])
        ->and($old->refresh()->model_metadata)->toBeNull()
        ->and((float) $prediction->predicted_spread)->toBe(9.0);
});

it('resolves both Washington aliases from the same alignment definition', function () {
    expect(NflTeamAlignment::TEAMS['WAS'])->toBe(NflTeamAlignment::TEAMS['WSH']);
});

it('retains missing statistics without inventing zero rates or lost fumbles', function () {
    $target = nflIntegrityGame();
    $prior = nflIntegrityGame(['home_team_id' => $target->home_team_id, 'away_team_id' => $target->away_team_id,
        'game_date' => '2026-10-10', 'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 17]);
    foreach ([$target->home_team_id, $target->away_team_id] as $index => $teamId) {
        TeamStat::query()->create(['game_id' => $prior->id, 'team_id' => $teamId,
            'team_type' => $index ? 'away' : 'home', 'passing_attempts' => 20, 'rushing_attempts' => 20,
            'sacks_allowed' => 0, 'total_yards' => 200, 'rushing_yards' => 80, 'fumbles' => 5,
            'fumbles_lost' => null, 'interceptions' => 0, 'red_zone_scores' => 0, 'red_zone_attempts' => 0,
            'third_down_conversions' => 0, 'third_down_attempts' => 0, 'penalty_yards' => null]);
    }
    $history = new NflTeamHistory($target, fn () => 1500.0);
    $totals = $history->totals($target->home_team_id);
    expect($totals['yards_per_play'])->toBe(5.0)->and($totals['red_zone_rate'])->toBeNull()
        ->and($totals['third_down_rate'])->toBeNull()->and($totals['turnover_rate'])->toBeNull()
        ->and($totals['penalty_yards'])->toBeNull()
        ->and($history->rolling($target->home_team_id)['turnover_diff'])->toBeNull();
    config(['nfl.predictions.total_environment.enabled' => true, 'nfl.predictions.total_environment.min_games' => 1]);
    $action = app(GeneratePredictionFromHistoricalElo::class);
    $result = (new ReflectionMethod($action, 'applyTotalEnvironmentBlend'))->invoke($action, $target, 46.0);
    $metadata = (new ReflectionProperty($action, 'lastModelMetadata'))->getValue($action);
    expect($result)->toBe(46.0)->and($metadata['total_environment']['reason'])->toBe('incomplete_statistics');
});

it('keeps version identities within the database limit without collisions', function () {
    config(['nfl.predictions.model_version' => str_repeat('long-base-version-', 15)]);
    $first = NflModelVersion::current();
    expect(strlen($first))->toBe(128)->and($first)->toBe(NflModelVersion::current())
        ->and($first)->not->toBe(NflModelVersion::current('full-historical'))
        ->and(strlen(NflModelVersion::definition()))->toBeGreaterThan(128);
});

it('does not refresh forecast timestamps when the current shadow finishes', function () {
    $game = nflIntegrityGame();
    $prediction = Prediction::query()->create(['game_id' => $game->id, 'home_elo' => 1500, 'away_elo' => 1500,
        'predicted_spread' => 9, 'predicted_total' => 40, 'win_probability' => .7, 'confidence_score' => 70,
        'model_version' => NflModelVersion::current(), 'model_metadata' => ['generation' => 'baseline'],
        'updated_at' => now()->subHour()]);
    $updatedAt = $prediction->updated_at->toDateTimeString();
    $snapshot = nflIntegritySnapshot($game, ['prediction_id' => $prediction->id]);
    $inference = Mockery::mock(NflFullHistoricalShadowInferenceService::class);
    $inference->shouldReceive('evaluate')->once()->andReturn(['active' => true]);
    $recorder = Mockery::mock(ShadowModelOutputRecorder::class);
    $recorder->shouldReceive('record')->once()->andReturnNull();
    (new EvaluateShadowPrediction($snapshot->id))->handle($inference, $recorder);
    expect($prediction->refresh()->updated_at->toDateTimeString())->toBe($updatedAt)
        ->and($prediction->model_metadata['generation'])->toBe('baseline')
        ->and($prediction->model_metadata['shadow_inference']['active'])->toBeTrue();
});

it('preserves deployed automatic season evidence weighting', function () {
    $target = nflIntegrityGame();
    foreach (range(1, 5) as $week) {
        nflIntegrityGame(['home_team_id' => $target->home_team_id, 'away_team_id' => $target->away_team_id,
            'game_date' => '2026-09-0'.$week, 'season_type' => '2', 'status' => 'STATUS_FINAL',
            'home_score' => 20, 'away_score' => 17, 'result_updated_at' => '2026-09-10']);
    }
    nflIntegrityGame(['home_team_id' => $target->home_team_id, 'away_team_id' => $target->away_team_id,
        'game_date' => '2026-08-01', 'season_type' => '1', 'status' => 'STATUS_FINAL', 'home_score' => 50, 'away_score' => 0]);
    $metric = new TeamMetric(['games_played' => 5]);
    $weight = app(NflSeasonEvidenceWeight::class)->resolve($target, $metric, $metric, .25);
    expect($weight['weight'])->toEqualWithDelta(5 / 7, .000001)
        ->and($weight['home_games'])->toBe(5)->and($weight['calibrated'])->toBeFalse();
});

it('calibrates each stage against its frozen inputs rather than market blended outputs', function () {
    $target = nflIntegrityGame();
    $prior = nflIntegrityGame(['game_date' => '2026-10-10', 'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 17]);
    nflIntegritySnapshot($prior, ['outputs' => [
        'predicted_spread' => 3, 'predicted_total' => 40, 'win_probability' => .6,
        'calibration_inputs' => ['point' => ['predicted_spread' => 7, 'predicted_total' => 50],
            'win_probability' => ['win_probability' => .8]],
    ]]);
    $history = new NflCalibrationHistory;
    $point = $history->prior($target, NflModelVersion::current(), 384, 'point')->sole();
    $probability = $history->prior($target, NflModelVersion::current(), 512, 'win_probability')->sole();
    $published = $history->prior($target, NflModelVersion::current(), 512)->sole();
    expect((float) $point->predicted_total)->toBe(50.0)
        ->and((float) $point->predicted_spread)->toBe(7.0)
        ->and((float) $probability->win_probability)->toBe(.8)
        ->and((float) $published->predicted_total)->toBe(40.0)
        ->and((float) $published->win_probability)->toBe(.6);
});

it('does not substitute published outputs when a calibration stage was not frozen', function () {
    $target = nflIntegrityGame();
    $prior = nflIntegrityGame(['game_date' => '2026-10-10', 'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 17]);
    nflIntegritySnapshot($prior);
    $history = new NflCalibrationHistory;
    expect($history->prior($target, NflModelVersion::current(), 512, 'point'))->toBeEmpty()
        ->and($history->prior($target, NflModelVersion::current(), 512, 'win_probability'))->toBeEmpty();
});

it('shows queued shadow outputs without editing the frozen baseline snapshot', function () {
    $snapshot = new PredictionFeatureSnapshot(['outputs' => ['baseline_predicted_spread' => 7, 'baseline_predicted_total' => 50,
        'baseline_win_probability' => .7, 'challenger_predicted_spread' => null, 'challenger_predicted_total' => null]]);
    $output = new ShadowModelOutput(['market_type' => 'spread', 'baseline_output' => 7, 'challenger_output' => 3,
        'explanation' => ['challenger_outputs' => ['predicted_spread' => 3, 'predicted_total' => 45, 'win_probability' => .6]]]);
    $output->setRelation('featureSnapshot', $snapshot)->setRelation('betDecisions', collect());
    $service = app(NflModelMonitoringService::class);
    $observation = (new ReflectionMethod($service, 'observation'))->invoke($service, $output, null);
    expect($observation['challenger_outputs']['predicted_spread'])->toBe(3.0)
        ->and($observation['challenger_outputs']['predicted_total'])->toBe(45.0)
        ->and($observation['challenger_outputs']['win_probability'])->toBe(.6)
        ->and($snapshot->outputs['challenger_predicted_spread'])->toBeNull();
});

it('does not run delayed shadow jobs after kickoff even when scoreboard status is stale', function () {
    $game = nflIntegrityGame(['game_date' => now()->subDay()->toDateString(), 'status' => 'STATUS_SCHEDULED']);
    $snapshot = nflIntegritySnapshot($game);
    $inference = Mockery::mock(NflFullHistoricalShadowInferenceService::class);
    $inference->shouldNotReceive('evaluate');
    $recorder = Mockery::mock(ShadowModelOutputRecorder::class);
    $recorder->shouldNotReceive('record');
    (new EvaluateShadowPrediction($snapshot->id))->handle($inference, $recorder);
});
