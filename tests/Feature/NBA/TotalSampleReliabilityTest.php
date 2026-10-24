<?php

use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-24 12:00:00');
    Http::preventStrayRequests();
    config(['nba.prediction.true_epa.enabled' => false, 'nba.prediction.recent_total_weight' => 0,
        'nba.prediction.fatigue_total_weight' => 0, 'nba.prediction.injury_total_weight' => 0]);
    $this->home = Team::factory()->create(['elo_rating' => 1500]);
    $this->away = Team::factory()->create(['elo_rating' => 1500]);
    $this->target = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-24', 'game_time' => '22:00:00', 'odds_data' => null]);
});

function nbaSampleHistory(Team $home, Team $away, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
            'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL',
            'game_date' => '2026-10-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'game_time' => '23:00:00']);
        foreach ([[$home, 140], [$away, 100]] as [$team, $points]) {
            TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $team->id, 'points' => $points, 'possessions' => 100]);
        }
    }
}

function nbaSampleMetrics(Team $team, int $count, array $attributes = []): TeamMetric
{
    return TeamMetric::query()->create(['team_id' => $team->id, 'season' => 2027, 'season_type' => '2',
        'wins' => $count, 'losses' => 0, 'offensive_efficiency' => 120, 'defensive_efficiency' => 110,
        'tempo' => 100, 'recent_form_rating' => 0, 'rest_travel_fatigue' => 0,
        'injury_adjusted_team_rating' => 1500, 'injury_total_adjustment' => 0, ...$attributes]);
}

it('ramps total season recent and venue support with actual sample size', function (int $count, float $support, float $expectedTotal) {
    nbaSampleHistory($this->home, $this->away, $count);
    nbaSampleMetrics($this->home, $count);
    nbaSampleMetrics($this->away, $count);
    $prediction = app(GeneratePrediction::class)->execute($this->target, false);
    $total = $prediction->model_metadata['total_model'];
    expect($total['home_recent_games'])->toEqual($count)->and($total['away_recent_games'])->toEqual($count)
        ->and($total['home_season_support'])->toEqual($support)
        ->and($total['recent_support'])->toEqual($support)->and($total['venue_support'])->toEqual($support)
        ->and($total['recent_efficiency_weight_applied'])->toEqual(round(0.35 * $support, 4))
        ->and($total['venue_efficiency_weight_applied'])->toEqual(round(0.15 * $support, 4))
        ->and($total['season_home_score_component'])->toEqual(round(110 + 5 * $support, 3))
        ->and((float) $prediction->predicted_total)->toEqual($expectedTotal);
})->with([[0, 0.0, 222.0], [1, 0.1, 223.9], [2, 0.2, 225.6], [10, 1.0, 235.7]]);

it('does not treat a missing opponent or a neutral venue as observed evidence', function () {
    nbaSampleHistory($this->home, $this->away, 1);
    TeamStat::query()->where('team_id', $this->away->id)->delete();
    $this->target->update(['neutral_site' => true]);
    $prediction = app(GeneratePrediction::class)->execute($this->target, false);
    expect(data_get($prediction->model_metadata, 'total_model.recent_support'))->toEqual(0.0)
        ->and(data_get($prediction->model_metadata, 'total_model.venue_support'))->toEqual(0.0);
});

it('estimates missing possessions from complete box scores consistently for form and venue', function () {
    nbaSampleHistory($this->home, $this->away, 1);
    TeamStat::query()->update(['possessions' => null, 'field_goals_attempted' => 90,
        'offensive_rebounds' => 10, 'turnovers' => 10, 'free_throws_attempted' => 0]);
    TeamStat::query()->where('team_id', $this->home->id)->update(['points' => 108]);
    TeamStat::query()->where('team_id', $this->away->id)->update(['points' => 90]);
    $action = new GeneratePrediction;
    $recent = (new ReflectionMethod($action, 'getRecentForm'))->invoke($action, $this->home, 2027, $this->target);
    $venue = (new ReflectionMethod($action, 'getVenueEfficiency'))->invoke($action, $this->home, 2027, 'home', $this->target);
    expect($recent['off_eff'])->toEqual(120.0)->and($recent['def_eff'])->toEqual(100.0)
        ->and($recent['tempo'])->toEqual(90.0)->and($venue['off'])->toEqual(120.0)->and($venue['def'])->toEqual(100.0)
        ->and(TeamStat::query()->whereNotNull('possessions')->count())->toEqual(0);
});

it('rejects missing possession estimates when required box score fields are absent', function () {
    nbaSampleHistory($this->home, $this->away, 1);
    TeamStat::query()->where('team_id', $this->home->id)->update(['possessions' => null, 'field_goals_attempted' => null]);
    $prediction = app(GeneratePrediction::class)->execute($this->target, false);
    expect(data_get($prediction->model_metadata, 'total_model.home_recent_games'))->toEqual(0);
});

it('applies persisted injury points exactly once and reconciles final total metadata', function () {
    nbaSampleMetrics($this->home, 10);
    nbaSampleMetrics($this->away, 10);
    $baseline = app(GeneratePrediction::class)->preview($this->target);
    TeamMetric::query()->where('team_id', $this->home->id)->update(['injury_total_adjustment' => -2]);
    TeamMetric::query()->where('team_id', $this->away->id)->update(['injury_total_adjustment' => -3]);
    $prediction = app(GeneratePrediction::class)->execute($this->target, false);
    $total = $prediction->model_metadata['total_model'];
    $snapshot = PredictionFeatureSnapshot::query()->where('prediction_id', $prediction->id)->latest('id')->first();
    expect((float) $prediction->predicted_total)->toEqual(round($baseline['predicted_total'] - 5, 1))
        ->and($total['injury_total_adjustment'])->toEqual(-5.0)
        ->and($total['post_model_injury_adjustment'])->toEqual(0.0)
        ->and($total['context_injury_adjustment'])->toEqual(0.0)
        ->and($total['final_predicted_total'])->toEqual((float) $prediction->predicted_total)
        ->and(round($total['rounded_calibrated_total'] + $total['context_total_adjustment'], 1))->toEqual((float) $prediction->predicted_total)
        ->and(data_get($snapshot->model_metadata, 'total_model.final_predicted_total'))->toEqual((float) $prediction->predicted_total);
});

it('scales the post model recent adjustment and records every remaining total step', function () {
    config(['nba.prediction.recent_total_weight' => 0.12, 'nba.prediction.fatigue_total_weight' => 0.2]);
    nbaSampleHistory($this->home, $this->away, 2);
    nbaSampleMetrics($this->home, 2, ['recent_form_rating' => -20, 'rest_travel_fatigue' => 1]);
    nbaSampleMetrics($this->away, 2, ['recent_form_rating' => -10, 'rest_travel_fatigue' => 2]);
    $prediction = app(GeneratePrediction::class)->execute($this->target, false);
    $total = $prediction->model_metadata['total_model'];
    expect($total['context_recent_adjustment'])->toEqual(-0.72)->and($total['context_fatigue_adjustment'])->toEqual(-0.6)
        ->and($total['context_total_adjustment'])->toEqual(-1.32)
        ->and(round($total['rounded_calibrated_total'] + $total['context_total_adjustment'], 1))->toEqual((float) $prediction->predicted_total);
});

it('does not subtract the generic injury rating loss again from NBA totals', function () {
    config(['nba.prediction.injury_total_weight' => 0.015]);
    nbaSampleMetrics($this->home, 0);
    nbaSampleMetrics($this->away, 0);
    $baseline = app(GeneratePrediction::class)->preview($this->target);
    TeamMetric::query()->where('team_id', $this->home->id)->update(['injury_adjusted_team_rating' => 1400]);
    $injured = app(GeneratePrediction::class)->preview($this->target);
    expect($injured['predicted_total'])->toEqual($baseline['predicted_total'])
        ->and(data_get($injured, 'model_metadata.total_model.context_injury_adjustment'))->toEqual(0.0);
});

it('does not count previous season fallback metrics as current season sample support', function () {
    foreach ([$this->home, $this->away] as $team) {
        nbaSampleMetrics($team, 82, ['season' => 2026, 'offensive_efficiency' => 150, 'defensive_efficiency' => 150, 'tempo' => 120]);
    }
    $prediction = app(GeneratePrediction::class)->preview($this->target);
    expect($prediction['predicted_total'])->toEqual(222.0)
        ->and(data_get($prediction, 'model_metadata.total_model.home_season_support'))->toEqual(0.0);
});
