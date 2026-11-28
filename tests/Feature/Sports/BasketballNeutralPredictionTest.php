<?php

use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\TeamMetric;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function basketballNeutralPredictionGame(string $sport, ?bool $neutral): Model
{
    $team = 'App\\Models\\'.strtoupper($sport).'\\Team';
    $game = 'App\\Models\\'.strtoupper($sport).'\\Game';
    $kickoff = now()->addDay()->utc();

    return $game::factory()->create([
        'home_team_id' => $team::factory()->create(['elo_rating' => 1500])->id,
        'away_team_id' => $team::factory()->create(['elo_rating' => 1500])->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => $kickoff->toDateString(), 'game_time' => $kickoff->format('H:i:s'),
        'neutral_site' => $neutral, 'odds_data' => null,
    ]);
}

it('uses observed neutral context and freezes nullable fallback with a new legacy version', function (string $sport, ?bool $observed) {
    config([
        "$sport.elo.home_court_advantage" => 100,
        "$sport.prediction.elo_to_spread_divisor" => 25,
        "$sport.prediction.elo_weight" => 1.0,
        "$sport.prediction.efficiency_weight" => 0.0,
        "$sport.prediction.form_weight" => 0.0,
        "$sport.prediction.true_epa.enabled" => false,
        "$sport.prediction.spread_output_regression_weight" => 0.0,
    ]);
    $game = basketballNeutralPredictionGame($sport, $observed);
    $class = 'App\\Actions\\'.strtoupper($sport).'\\GeneratePrediction';
    $generator = new $class;
    $data = (new ReflectionMethod($generator, 'makePredictionData'))->invoke($generator, $game);

    expect($data['predicted_spread'])->toBe($observed === true ? 0.0 : 4.0)
        ->and($data['model_version'])->toBe('rules-observed-neutral-v2')
        ->and($data['feature_version'])->toBe('core-neutral-observation-v2');
    $context = $data['_snapshot']['model_metadata']['neutral_context'];
    expect($context['observed_neutral_site'])->toBe($observed)
        ->and($context['effective_home_court_advantage'])->toBe($observed === true ? 0.0 : 100.0)
        ->and($context['unknown_fallback_applied'])->toBe($observed === null)
        ->and($context['risk_flags'])->toBe($observed === null ? ['neutral_site_unknown'] : [])
        ->and($context['historical_availability_verified'])->toBeFalse()
        ->and($data['_snapshot']['features']['neutral_context'])->toBe($context);
})->with(['nba', 'wnba'])->with([true, false, null]);

it('suppresses NBA home efficiency form and venue split adjustments at an observed neutral site', function () {
    config(['nba.prediction.elo_weight' => 0.0, 'nba.prediction.efficiency_weight' => 1.0,
        'nba.prediction.form_weight' => 1.0, 'nba.prediction.home_court_points' => 3.0,
        'nba.prediction.home_away_split_weight' => 1.0, 'nba.prediction.true_epa.enabled' => false]);
    $game = basketballNeutralPredictionGame('nba', true);
    $generator = new GeneratePrediction;
    $home = new TeamMetric(['net_rating' => 10.0]);
    $away = new TeamMetric(['net_rating' => 0.0]);
    $spread = (new ReflectionMethod($generator, 'calculatePredictedSpread'))->invoke($generator, 1500, 1500, $home, $away, $game);
    $metadata = (new ReflectionProperty($generator, 'metadata'))->getValue($generator);
    expect($spread)->toBe(5.0)
        ->and($metadata['home_away_split_adj'])->toBe(0.0)
        ->and($metadata['efficiency_spread_component'])->toBe(5.0)
        ->and($metadata['form_spread_component'])->toBe(0.0);
});

it('does not backdate a current neutral observation into historical reconstruction', function (string $sport) {
    $game = basketballNeutralPredictionGame($sport, true);
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 90, 'away_score' => 80]);
    $class = 'App\\Actions\\'.strtoupper($sport).'\\GeneratePrediction';
    $prediction = (new $class)->executeHistorical($game, false);
    $snapshot = PredictionFeatureSnapshot::where('prediction_table', $sport.'_predictions')
        ->where('prediction_id', $prediction->id)->sole();
    $context = $snapshot->model_metadata['neutral_context'];
    expect($context['observed_neutral_site'])->toBeNull()
        ->and($context['unknown_fallback_applied'])->toBeTrue()
        ->and($context['historical_reconstruction'])->toBeTrue()
        ->and($context['historical_availability_verified'])->toBeFalse();
})->with(['nba', 'wnba']);
