<?php

use App\Actions\CBB\GeneratePrediction;
use App\Models\CBB\EloRating;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Services\CBB\CbbSeasonInitializer;
use App\Services\CBB\CbbSeasonInputs;
use App\Services\NBA\WinProbabilityCalibrationInferenceService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo('2026-11-02 12:00:00');
    Http::preventStrayRequests();
    $this->home = Team::factory()->create(['elo_rating' => 1700]);
    $this->away = Team::factory()->create(['elo_rating' => 1400]);
    $this->game = Game::factory()->create([
        'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'season' => 2027, 'season_type' => 2, 'game_date' => '2026-11-03',
        'game_time' => '01:00:00', 'status' => 'STATUS_SCHEDULED',
    ]);
});

function cbbSeasonInputFinal(Team $home, Team $away, string $date, int $season, int $homePoints = 84, int $awayPoints = 63): Game
{
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => $date, 'season' => $season, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'home_score' => $homePoints, 'away_score' => $awayPoints]);
    foreach ([$home->id => $homePoints, $away->id => $awayPoints] as $teamId => $points) {
        TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $teamId,
            'points' => $points, 'possessions' => 70, 'field_goals_made' => intdiv($points, 2),
            'field_goals_attempted' => 70, 'three_point_made' => 0, 'three_point_attempted' => 0,
            'free_throws_made' => $points % 2, 'free_throws_attempted' => 2]);
    }

    return $game;
}

it('uses reconciled regressed prior-season inputs on opening day', function (): void {
    cbbSeasonInputFinal($this->home, $this->away, '2026-03-01', 2026);
    $result = app(CbbSeasonInputs::class)->forTeam($this->home->id, $this->game);
    expect($result['evidence'])->toMatchArray(['source' => 'prior_season', 'current_games' => 0, 'prior_games' => 1, 'provisional' => true])
        ->and((float) $result['metric']->offensive_efficiency)->toBe(110.0)
        ->and((float) $result['metric']->defensive_efficiency)->toBe(95.0);
});

it('excludes corrupt historical boxes and does not use target or future results', function (): void {
    $bad = cbbSeasonInputFinal($this->home, $this->away, '2026-03-01', 2026);
    $bad->update(['home_score' => 99]);
    cbbSeasonInputFinal($this->home, $this->away, '2026-11-03', 2027);
    cbbSeasonInputFinal($this->home, $this->away, '2026-11-04', 2027);
    $result = app(CbbSeasonInputs::class)->forTeam($this->home->id, $this->game);
    expect($result['metric'])->toBeNull()->and($result['evidence']['source'])->toBe('baseline');
});

it('transitions from prior evidence into the current season without a five-game cliff', function (): void {
    cbbSeasonInputFinal($this->home, $this->away, '2026-03-01', 2026);
    cbbSeasonInputFinal($this->home, $this->away, '2026-11-01', 2027, 70, 77);
    $result = app(CbbSeasonInputs::class)->forTeam($this->home->id, $this->game);
    expect($result['evidence'])->toMatchArray(['source' => 'blended', 'current_games' => 1, 'current_weight' => 0.125])
        ->and((float) $result['metric']->offensive_efficiency)->toBe(108.8);
});

it('marks opening-day baseline forecasts provisional and preserves their lineage', function (): void {
    $prediction = app(GeneratePrediction::class)->execute($this->game, false);
    $snapshot = PredictionFeatureSnapshot::where('prediction_id', $prediction->id)->where('sport', 'cbb')->firstOrFail();
    expect($prediction->model_version)->toBe('cbb-season-v2')
        ->and(data_get($prediction->model_metadata, 'season_inputs.home.source'))->toBe('baseline')
        ->and(data_get($prediction->model_metadata, 'provisional'))->toBeTrue()
        ->and(data_get($snapshot->model_metadata, 'statistical_inputs_available'))->toBeFalse()
        ->and($snapshot->pregame_safe)->toBeTrue()
        ->and(data_get($snapshot->features, 'spread_component_weights'))->toMatchArray(['elo' => 1.0, 'efficiency' => 0.0, 'form' => 0.0]);
});

it('freezes stored predictions at tipoff and requires explicit reconstruction for missing past forecasts', function (): void {
    $prediction = app(GeneratePrediction::class)->execute($this->game, false);
    $original = $prediction->fresh()->getAttributes();
    $this->travelTo('2026-11-03 12:00:00');
    $this->home->update(['elo_rating' => 1100]);
    app(GeneratePrediction::class)->execute($this->game->fresh(), false);
    expect($prediction->fresh()->getAttributes())->toBe($original);
    $past = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'season' => 2027, 'game_date' => '2026-11-01', 'game_time' => '18:00:00', 'status' => 'STATUS_FINAL']);
    expect(app(GeneratePrediction::class)->execute($past, false))->toBeNull();
    $reconstructed = app(GeneratePrediction::class)->executeHistorical($past, false);
    $snapshot = PredictionFeatureSnapshot::where('sport', 'cbb')->where('prediction_id', $reconstructed->id)->firstOrFail();
    expect($snapshot->pregame_safe)->toBeFalse()->and(data_get($snapshot->lineage_metadata, 'run_type'))->toBe('historical_reconstruction');
});

it('rolls back a forecast that crosses tipoff while its inputs are being calculated', function (): void {
    $this->mock(CbbSeasonInputs::class)->shouldReceive('forTeam')->andReturnUsing(function (): array {
        $this->travelTo('2026-11-03 12:00:00');

        return ['metric' => null, 'evidence' => ['source' => 'baseline', 'provisional' => true]];
    });
    expect(fn () => app(GeneratePrediction::class)->execute($this->game, false))->toThrow(LogicException::class, 'rolled back');
    expect($this->game->prediction()->exists())->toBeFalse()
        ->and(PredictionFeatureSnapshot::where('sport', 'cbb')->count())->toBe(0);
});

it('never replaces an original forecast during an explicit historical backfill', function (): void {
    $original = app(GeneratePrediction::class)->execute($this->game, false);
    $this->travelTo('2026-11-03 12:00:00');
    $this->game->update(['status' => 'STATUS_FINAL']);
    $result = app(GeneratePrediction::class)->executeHistorical($this->game, false);
    expect($result->id)->toBe($original->id)
        ->and(PredictionFeatureSnapshot::where('sport', 'cbb')->where('prediction_id', $original->id)->count())->toBe(1);
});

it('does not activate a calibration artifact trained for the previous CBB model', function (): void {
    $this->mock(WinProbabilityCalibrationInferenceService::class)->shouldReceive('calibrate')->once()
        ->andReturn(['enabled' => true, 'baseline_win_probability' => 0.7, 'calibrated_win_probability' => 0.95,
            'active_win_probability' => 0.95, 'active_source' => 'calibrated', 'apply_to_live_output' => true]);
    $prediction = app(GeneratePrediction::class)->execute($this->game, false);
    expect((float) $prediction->win_probability)->toBe(0.7)
        ->and(data_get($prediction->model_metadata, 'win_probability_calibration.reason'))->toBe('cbb_season_v2_requires_validation')
        ->and(data_get($prediction->model_metadata, 'probability_validation'))->toBe('uncalibrated');
});

it('excludes preseason box scores from the regular-season input pool', function (): void {
    $preseason = cbbSeasonInputFinal($this->home, $this->away, '2026-10-20', 2027);
    $preseason->update(['season_type' => 1]);
    $result = app(CbbSeasonInputs::class)->forTeam($this->home->id, $this->game);
    expect($result['metric'])->toBeNull()->and($result['evidence']['current_games'])->toBe(0);
});

it('initializes current-season Elo once from historical ratings rather than repeatedly shrinking it', function (): void {
    EloRating::create(['team_id' => $this->home->id, 'season' => 2026, 'game_id' => null,
        'date' => '2026-04-01', 'elo_rating' => 1700, 'elo_change' => 0]);
    $initializer = app(CbbSeasonInitializer::class);
    expect($initializer->initialize(2027))->toBe(2)
        ->and((int) $this->home->fresh()->elo_rating)->toBe(1640)
        ->and((int) $this->away->fresh()->elo_rating)->toBe(1500);
    expect($initializer->initialize(2027))->toBe(0)
        ->and((int) $this->home->fresh()->elo_rating)->toBe(1640)
        ->and(ModelRun::where('sport', 'cbb')->where('run_type', 'season_initialization')->count())->toBe(1);
});
