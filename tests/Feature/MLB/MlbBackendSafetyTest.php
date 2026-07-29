<?php

use App\Actions\MLB\GeneratePrediction;
use App\Actions\MLB\UpdateLivePrediction;
use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Models\PredictionFeatureSnapshot;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Support\MLB\MlbGamePhase;
use App\Support\MLB\MlbMarketSpread;
use Illuminate\Support\Carbon;

uses()->group('mlb');

it('maps mlb game phases from canonical statuses', function () {
    expect(MlbGamePhase::phase('STATUS_SCHEDULED'))->toBe('pregame')
        ->and(MlbGamePhase::phase('STATUS_DELAYED'))->toBe('delayed')
        ->and(MlbGamePhase::phase('STATUS_IN_PROGRESS'))->toBe('live')
        ->and(MlbGamePhase::phase('STATUS_FINAL'))->toBe('final')
        ->and(MlbGamePhase::phase('STATUS_POSTPONED'))->toBe('postponed')
        ->and(MlbGamePhase::phase('STATUS_SUSPENDED'))->toBe('suspended')
        ->and(MlbGamePhase::phase('STATUS_CANCELED'))->toBe('cancelled')
        ->and(MlbGamePhase::phase('SOMETHING_NEW'))->toBe('unknown');
});

it('separates raw edge and no-vig edge and blocks stale odds from official bets', function () {
    config(['mlb.signals.bet_filter.promotions_validated' => true]);

    $home = Team::factory()->create(['location' => 'St. Louis', 'name' => 'Cardinals', 'abbreviation' => 'STL']);
    $away = Team::factory()->create(['location' => 'Kansas City', 'name' => 'Royals', 'abbreviation' => 'KC']);
    $game = Game::factory()->create([
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'odds_updated_at' => now()->subHours(13),
        'odds_data' => moneylineOddsData('St. Louis Cardinals', 'Kansas City Royals', -105, 120),
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => '2',
        'predicted_spread' => 1.6,
        'predicted_total' => 8.5,
        'win_probability' => 0.57,
        'confidence_score' => 58,
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
                'home_confidence' => 1,
                'away_confidence' => 1,
            ],
        ],
    ]);

    $recommendation = app(MlbPredictionRecommendationService::class)->forPrediction($prediction);

    expect($recommendation['raw_implied_probability'])->toBe(0.5122)
        ->and($recommendation['raw_edge'])->toBe(0.0578)
        ->and($recommendation['no_vig_implied_probability'])->toBe(0.5298)
        ->and($recommendation['no_vig_edge'])->toBe(0.0402)
        ->and($recommendation['recommendation_type'])->toBe('no_play')
        ->and($recommendation['is_bet'])->toBeFalse()
        ->and($recommendation['no_bet_reason'])->toBe('stale_odds')
        ->and($recommendation['risk_flags'])->toContain('stale_odds');
});

it('marks missing odds timestamps and prevents official bet promotion', function () {
    config(['mlb.signals.bet_filter.promotions_validated' => true]);

    $home = Team::factory()->create(['location' => 'St. Louis', 'name' => 'Cardinals']);
    $away = Team::factory()->create(['location' => 'Kansas City', 'name' => 'Royals']);
    $game = Game::factory()->create([
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'odds_updated_at' => null,
        'odds_data' => moneylineOddsData('St. Louis Cardinals', 'Kansas City Royals', -105, 120),
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => '2',
        'predicted_spread' => 1.6,
        'predicted_total' => 8.5,
        'win_probability' => 0.57,
        'confidence_score' => 58,
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
                'home_confidence' => 1,
                'away_confidence' => 1,
            ],
        ],
    ]);

    $recommendation = app(MlbPredictionRecommendationService::class)->forPrediction($prediction);

    expect($recommendation['recommendation_type'])->toBe('no_play')
        ->and($recommendation['is_bet'])->toBeFalse()
        ->and($recommendation['no_bet_reason'])->toBe('missing_odds_timestamp')
        ->and($recommendation['risk_flags'])->toContain('missing_odds_timestamp');
});

it('excludes future team metrics during historical prediction generation', function () {
    $home = Team::factory()->create(['elo_rating' => 1500]);
    $away = Team::factory()->create(['elo_rating' => 1490]);

    TeamMetric::query()->create([
        'team_id' => $home->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 60,
        'losses' => 20,
        'recent_form_rating' => 9.9,
        'injury_adjusted_team_rating' => 1700,
        'calculation_date' => '2026-06-18',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-04-10',
        'game_time' => '18:40:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 5,
        'away_score' => 4,
    ]);

    $prediction = app(GeneratePrediction::class)->executeHistorical($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and(data_get($prediction->model_metadata, 'point_in_time_safety.team_metrics.home.source'))
        ->toBe('missing_or_future_metric_excluded')
        ->and(data_get($prediction->model_metadata, 'point_in_time_safety.team_metrics.home.excluded_future_calculation_date'))
        ->toBe('2026-06-18')
        ->and(data_get($prediction->model_metadata, 'situational_context.handedness.applied'))->toBeFalse()
        ->and(data_get($prediction->model_metadata, 'situational_context.handedness.pregame_safe'))->toBeFalse()
        ->and(data_get($prediction->model_metadata, 'situational_context.handedness.safety_reason'))
        ->toBe('current_roster_membership_disabled_for_historical_reconstruction');
});

it('uses pregame odds snapshots instead of post-start odds for historical predictions', function () {
    $home = Team::factory()->create(['location' => 'New York', 'name' => 'Yankees', 'elo_rating' => 1510]);
    $away = Team::factory()->create(['location' => 'Boston', 'name' => 'Red Sox', 'elo_rating' => 1500]);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-04-10',
        'game_time' => '18:40:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 4,
        'away_score' => 2,
        'odds_data' => spreadTotalOddsData('New York Yankees', 'Boston Red Sox', -2.5, 2.5, 9.5),
    ]);

    GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'source' => 'test',
        'commence_time' => Carbon::parse('2026-04-10 18:40:00', config('app.timezone')),
        'captured_at' => Carbon::parse('2026-04-10 09:00:00', config('app.timezone')),
        'payload_hash' => 'pregame',
        'odds_data' => spreadTotalOddsData('New York Yankees', 'Boston Red Sox', -1.5, 1.5, 8.5),
    ]);

    GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'source' => 'test',
        'commence_time' => Carbon::parse('2026-04-10 18:40:00', config('app.timezone')),
        'captured_at' => Carbon::parse('2026-04-10 18:50:00', config('app.timezone')),
        'payload_hash' => 'post-start',
        'odds_data' => spreadTotalOddsData('New York Yankees', 'Boston Red Sox', -3.5, 3.5, 10.5),
    ]);

    $prediction = app(GeneratePrediction::class)->executeHistorical($game->fresh(['homeTeam', 'awayTeam']));

    expect((float) $prediction->vegas_spread)->toBe(-1.5)
        ->and(data_get($prediction->model_metadata, 'market_context.market_total'))->toBe(8.5)
        ->and(data_get($prediction->model_metadata, 'market_context.safety.source'))->toBe('pregame_odds_snapshot')
        ->and(data_get($prediction->model_metadata, 'market_context.safety.pregame_safe'))->toBeTrue();
});

it('preserves same-version prediction snapshots as distinguishable runs', function () {
    $home = Team::factory()->create(['elo_rating' => 1510]);
    $away = Team::factory()->create(['elo_rating' => 1500]);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));
    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = $game->prediction()->first();
    $snapshots = PredictionFeatureSnapshot::query()
        ->where('prediction_table', 'mlb_predictions')
        ->where('prediction_id', $prediction->id)
        ->get();

    expect($snapshots)->toHaveCount(2)
        ->and($snapshots->pluck('snapshot_run_id')->unique())->toHaveCount(2);
});

it('keeps live fields out of official pregame bet counts', function () {
    $home = Team::factory()->create(['location' => 'St. Louis', 'name' => 'Cardinals']);
    $away = Team::factory()->create(['location' => 'Kansas City', 'name' => 'Royals']);
    $game = Game::factory()->create([
        'status' => 'STATUS_IN_PROGRESS',
        'inning' => 5,
        'inning_state' => 'top',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'odds_updated_at' => now(),
        'odds_data' => moneylineOddsData('St. Louis Cardinals', 'Kansas City Royals', -105, 120),
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => '2',
        'predicted_spread' => 1.6,
        'predicted_total' => 8.5,
        'win_probability' => 0.57,
        'confidence_score' => 58,
        'live_win_probability' => 0.99,
        'live_predicted_spread' => 5.0,
        'live_predicted_total' => 12.0,
        'live_outs_remaining' => 24,
        'live_updated_at' => now(),
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
                'home_confidence' => 1,
                'away_confidence' => 1,
            ],
        ],
    ]);

    $recommendation = app(MlbPredictionRecommendationService::class)->forPrediction($prediction);

    expect($recommendation['recommendation_type'])->toBe('monitor')
        ->and($recommendation['is_bet'])->toBeFalse()
        ->and(data_get($recommendation, 'pregame_recommendation.recommendation_type'))->toBe('bet')
        ->and(data_get($recommendation, 'pregame_recommendation.is_bet'))->toBeTrue();
});

it('clears live prediction fields for delayed games instead of treating them as live', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'status' => 'STATUS_DELAYED',
        'inning' => 0,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'live_win_probability' => 0.75,
        'live_predicted_spread' => 2.0,
        'live_predicted_total' => 9.0,
        'live_outs_remaining' => 30,
        'live_updated_at' => now(),
    ]);

    $result = app(UpdateLivePrediction::class)->execute($game->fresh());

    expect($result)->toBeNull();
    $prediction->refresh();
    expect($prediction->live_win_probability)->toBeNull()
        ->and($prediction->live_predicted_spread)->toBeNull()
        ->and($prediction->live_predicted_total)->toBeNull();
});

it('documents mlb market spread sign conversion', function () {
    expect(MlbMarketSpread::edgeRuns(2.0, -1.5))->toBe(0.5)
        ->and(MlbMarketSpread::edgeRuns(-2.0, 1.5))->toBe(-0.5)
        ->and(MlbMarketSpread::marketLineFromHomeSpread(-1.5))->toBe(1.5)
        ->and(MlbMarketSpread::marketLineFromHomeSpread(1.5))->toBe(-1.5);
});

function moneylineOddsData(string $homeName, string $awayName, int $homePrice, int $awayPrice): array
{
    return [
        'home_team' => $homeName,
        'away_team' => $awayName,
        'bookmakers' => [[
            'key' => 'draftkings',
            'markets' => [[
                'key' => 'h2h',
                'outcomes' => [
                    ['name' => $homeName, 'price' => $homePrice],
                    ['name' => $awayName, 'price' => $awayPrice],
                ],
            ]],
        ]],
    ];
}

function spreadTotalOddsData(string $homeName, string $awayName, float $homeSpread, float $awaySpread, float $total): array
{
    return [
        'home_team' => $homeName,
        'away_team' => $awayName,
        'bookmakers' => [[
            'key' => 'draftkings',
            'markets' => [
                [
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => $homeName, 'point' => $homeSpread],
                        ['name' => $awayName, 'point' => $awaySpread],
                    ],
                ],
                [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => $total],
                        ['name' => 'Under', 'point' => $total],
                    ],
                ],
            ],
        ]],
    ];
}
