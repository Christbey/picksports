<?php

use App\Actions\MLB\GeneratePrediction;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Services\MLB\MlbPredictionCalculationAuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

uses()->group('mlb');

beforeEach(function () {
    Config::set('mlb.prediction.home_field_advantage', 0);
    Config::set('mlb.elo.home_field_advantage', 0);
    Config::set('mlb.elo.team_weight', 1.0);
    Config::set('mlb.prediction.early_season.team_weight_start', 1.0);
    Config::set('mlb.prediction.early_season.context_scale_min', 0.0);
    Config::set('mlb.prediction.elo_diff_to_spread_divisor', 25.0);
    Config::set('mlb.prediction.spread_to_probability_coefficient', 2.0);
    Config::set('mlb.prediction.total_model.base_runs', 8.5);
    Config::set('mlb.prediction.total_model.average_elo_baseline', 1500.0);
    Config::set('mlb.prediction.total_model.average_elo_divisor', 50.0);
    Config::set('mlb.prediction.situational.advanced_ratings.enabled', false);
    Config::set('mlb.prediction.situational.starter_form.enabled', false);
    Config::set('mlb.prediction.park_factors', []);
    Config::set('mlb.prediction.actual_weather.enabled', false);
});

it('keeps generated mlb prediction outputs mathematically coherent', function () {
    [$game] = mlbSoundnessGame(homeElo: 1550, awayElo: 1500);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));
    $audit = app(MlbPredictionCalculationAuditService::class);
    $derived = $audit->derivedScores((float) $prediction->predicted_spread, (float) $prediction->predicted_total);

    expect($audit->hardInvariantFailures($prediction))->toBe([])
        ->and((float) $prediction->win_probability)->toBeGreaterThanOrEqual(0.0)
        ->and((float) $prediction->win_probability)->toBeLessThanOrEqual(1.0)
        ->and(round((float) $prediction->win_probability + (1 - (float) $prediction->win_probability), 3))->toBe(1.0)
        ->and((float) $prediction->predicted_spread)->toBeGreaterThan(0.0)
        ->and((float) $prediction->win_probability)->toBeGreaterThan(0.5)
        ->and(round($derived['home'] + $derived['away'], 1))->toBe((float) $prediction->predicted_total)
        ->and(round($derived['home'] - $derived['away'], 1))->toBe((float) $prediction->predicted_spread)
        ->and($audit->confidenceLabel((float) $prediction->confidence_score))->toBe('medium');
});

it('uses explicit fallback pitcher inputs when probable pitchers are missing', function () {
    [$game] = mlbSoundnessGame(homeElo: 1500, awayElo: 1500);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->home_pitcher_elo)->toBe(1500.0)
        ->and((float) $prediction->away_pitcher_elo)->toBe(1500.0)
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.home_source'))->toBe('league_average')
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.away_source'))->toBe('league_average');
});

it('keeps moneyline market inputs out of core prediction probability', function () {
    [$favoriteMarketGame] = mlbSoundnessGame(homeElo: 1525, awayElo: 1500, oddsData: mlbSoundnessMoneylineOdds(-200, 175));
    [$dogMarketGame] = mlbSoundnessGame(homeElo: 1525, awayElo: 1500, oddsData: mlbSoundnessMoneylineOdds(180, -205));

    $favoriteMarketPrediction = app(GeneratePrediction::class)->execute($favoriteMarketGame->fresh(['homeTeam', 'awayTeam']));
    $dogMarketPrediction = app(GeneratePrediction::class)->execute($dogMarketGame->fresh(['homeTeam', 'awayTeam']));

    expect((float) $favoriteMarketPrediction->win_probability)->toBe((float) $dogMarketPrediction->win_probability)
        ->and((float) $favoriteMarketPrediction->predicted_spread)->toBe((float) $dogMarketPrediction->predicted_spread)
        ->and((float) $favoriteMarketPrediction->predicted_total)->toBe((float) $dogMarketPrediction->predicted_total);
});

it('does not let live fields alter pregame prediction outputs on regeneration', function () {
    [$game] = mlbSoundnessGame(homeElo: 1540, awayElo: 1500);
    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));
    $before = $prediction->only(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score']);

    $prediction->forceFill([
        'live_win_probability' => 0.99,
        'live_predicted_spread' => 8.0,
        'live_predicted_total' => 15.0,
        'live_outs_remaining' => 6,
        'live_updated_at' => now(),
    ])->save();

    $regenerated = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($regenerated->only(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score']))->toBe($before)
        ->and(app(MlbPredictionCalculationAuditService::class)->warnings($regenerated))->toContain('live_fields_present_but_not_core_pregame_inputs');
});

it('explains a stored mlb prediction as structured json', function () {
    [$game, $homeTeam] = mlbSoundnessGame(homeElo: 1550, awayElo: 1500);
    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $exitCode = Artisan::call('mlb:explain-prediction', [
        'game_id' => $game->id,
        '--json' => true,
        '--as-of' => '2026-06-18T12:00:00Z',
    ]);

    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and($payload['game_id'])->toBe($game->id)
        ->and($payload['outputs']['home_win_probability'])->toBeGreaterThan(0.5)
        ->and($payload['outputs']['predicted_winner'])->toBe($homeTeam->abbreviation)
        ->and($payload['safety']['hard_failures'])->toBe([]);
});

it('audit command fails on hard calculation invariant failures', function () {
    [$game] = mlbSoundnessGame(homeElo: 1500, awayElo: 1500);

    Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => '2',
        'predicted_spread' => -1.5,
        'predicted_total' => 8.5,
        'win_probability' => 1.2,
        'confidence_score' => 120,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
    ]);

    $exitCode = Artisan::call('mlb:audit-prediction-calculations', [
        '--season' => 2026,
        '--json' => true,
    ]);

    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(1)
        ->and($payload['summary']['invalid_probabilities'])->toBe(1)
        ->and($payload['summary']['invalid_total_score_relationship'])->toBe(0)
        ->and($payload['hard_failure_samples'][0]['reasons'])->toContain('home_win_probability_out_of_range')
        ->and($payload['hard_failure_samples'][0]['reasons'])->toContain('confidence_out_of_range');
});

function mlbSoundnessGame(int $homeElo, int $awayElo, ?array $oddsData = null): array
{
    static $sequence = 0;

    $sequence++;
    $homeAbbreviation = 'H'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    $awayAbbreviation = 'A'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);

    $home = Team::factory()->create([
        'abbreviation' => $homeAbbreviation,
        'location' => 'Home',
        'name' => 'Club',
        'elo_rating' => $homeElo,
    ]);
    $away = Team::factory()->create([
        'abbreviation' => $awayAbbreviation,
        'location' => 'Away',
        'name' => 'Club',
        'elo_rating' => $awayElo,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-18',
        'game_time' => '19:10:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'odds_data' => $oddsData,
        'odds_updated_at' => $oddsData ? now() : null,
    ]);

    return [$game, $home, $away];
}

function mlbSoundnessMoneylineOdds(int $homePrice, int $awayPrice): array
{
    return [
        'home_team' => 'Home Club',
        'away_team' => 'Away Club',
        'bookmakers' => [[
            'key' => 'testbook',
            'markets' => [[
                'key' => 'h2h',
                'outcomes' => [
                    ['name' => 'Home Club', 'price' => $homePrice],
                    ['name' => 'Away Club', 'price' => $awayPrice],
                ],
            ]],
        ]],
    ];
}
