<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Prediction as CbbPrediction;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\Prediction as CfbPrediction;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Prediction as NbaPrediction;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Prediction as NflPrediction;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WCBB\Prediction as WcbbPrediction;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Prediction as WnbaPrediction;
use App\Models\WNBA\Team as WnbaTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2PredictionContractSports', [
    'nba' => ['nba', NbaTeam::class, NbaGame::class, NbaPrediction::class],
    'nfl' => ['nfl', NflTeam::class, NflGame::class, NflPrediction::class],
    'mlb' => ['mlb', MlbTeam::class, MlbGame::class, MlbPrediction::class],
    'cbb' => ['cbb', CbbTeam::class, CbbGame::class, CbbPrediction::class],
    'cfb' => ['cfb', CfbTeam::class, CfbGame::class, CfbPrediction::class],
    'wcbb' => ['wcbb', WcbbTeam::class, WcbbGame::class, WcbbPrediction::class],
    'wnba' => ['wnba', WnbaTeam::class, WnbaGame::class, WnbaPrediction::class],
]);

it('requires authenticated access for v2 prediction endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/predictions")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/predictions/1")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/games/1/prediction")
        ->assertUnauthorized();
})->with('v2PredictionContractSports');

it('returns a clean json 404 for unsupported v2 sport prediction endpoints', function () {
    v2PredictionContractActingAsBypassUser();

    $this->getJson('/api/v2/sports/nhl/predictions')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/predictions/1')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/games/1/prediction')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('lists v2 predictions with sport, filter, pagination, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $predictionModel,
) {
    v2PredictionContractActingAsBypassUser();

    [$game, $prediction] = v2PredictionContractCreateGamePrediction(
        $teamModel,
        $gameModel,
        $predictionModel,
        [
            'status' => 'STATUS_FINAL',
            'home_score' => 5,
            'away_score' => 3,
        ],
        [
            'actual_spread' => 2.0,
            'actual_total' => 8.0,
            'spread_error' => 5.5,
            'total_error' => 209.5,
            'winner_correct' => true,
            'graded_at' => now(),
        ],
    );

    $response = $this->getJson("/api/v2/sports/{$slug}/predictions?season=2026&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'game_id',
                    'game',
                    'status',
                    'pick',
                    'projection' => [
                        'home_win_probability',
                        'away_win_probability',
                        'predicted_spread',
                        'predicted_total',
                        'confidence_score',
                    ],
                    'actual_spread',
                    'actual_total',
                    'spread_error',
                    'total_error',
                    'winner_correct',
                    'graded_at',
                    'market_summary',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => [
                'sport',
                'filters',
                'pagination',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('data.0.id', $prediction->id)
        ->assertJsonPath('data.0.sport', $slug)
        ->assertJsonPath('data.0.game_id', $game->id)
        ->assertJsonPath('data.0.game.home_score', 5)
        ->assertJsonPath('data.0.game.away_score', 3)
        ->assertJsonPath('data.0.actual_spread', 2)
        ->assertJsonPath('data.0.actual_total', 8)
        ->assertJsonPath('data.0.spread_error', 5.5)
        ->assertJsonPath('data.0.total_error', 209.5)
        ->assertJsonPath('data.0.winner_correct', true);

    expect($response->json('data.0.game.game_time'))->toMatch('/^\d{2}:\d{2}:\d{2}$/')
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2PredictionContractSports');

it('lists v2 prediction available seasons with stable metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $predictionModel,
) {
    v2PredictionContractActingAsBypassUser();

    v2PredictionContractCreateGamePrediction($teamModel, $gameModel, $predictionModel);

    $this->getJson("/api/v2/sports/{$slug}/predictions/available-seasons")
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => [
                'version',
                'sport',
                'contract',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('data.0', 2026)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.predictions.available-seasons');
})->with('v2PredictionContractSports');

it('lists v2 prediction available dates with optional season filtering', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $predictionModel,
) {
    v2PredictionContractActingAsBypassUser();

    [, $stalePrediction] = v2PredictionContractCreateGamePrediction(
        $teamModel,
        $gameModel,
        $predictionModel,
        ['season' => 2025, 'game_date' => '2025-06-10'],
        ['season' => 2025],
    );

    [$game] = v2PredictionContractCreateGamePrediction(
        $teamModel,
        $gameModel,
        $predictionModel,
        ['season' => 2026, 'game_date' => '2026-06-10'],
        ['season' => 2026],
    );

    $expectedDate = substr((string) $game->getAttribute('game_date'), 0, 10);

    $this->getJson("/api/v2/sports/{$slug}/predictions/available-dates?season=2026")
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => [
                'version',
                'sport',
                'contract',
                'filters',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('data.0', $expectedDate)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.predictions.available-dates')
        ->assertJsonPath('meta.filters.season', 2026);

    $this->getJson("/api/v2/sports/{$slug}/predictions?season=2026&per_page=10")
        ->assertOk()
        ->assertJsonMissingPath('data.1')
        ->assertJsonMissing(['id' => $stalePrediction->id]);
})->with('v2PredictionContractSports');

it('shows a v2 prediction with sport, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $predictionModel,
) {
    v2PredictionContractActingAsBypassUser();

    [$game, $prediction] = v2PredictionContractCreateGamePrediction($teamModel, $gameModel, $predictionModel);

    $response = $this->getJson("/api/v2/sports/{$slug}/predictions/{$prediction->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'sport',
                'game_id',
                'home_team_id',
                'away_team_id',
                'game',
                'status',
                'pick',
                'projection' => [
                    'home_win_probability',
                    'away_win_probability',
                    'predicted_spread',
                    'predicted_total',
                    'confidence_score',
                    'confidence_context',
                ],
                'home_win_probability',
                'away_win_probability',
                'win_probability',
                'predicted_spread',
                'predicted_total',
                'confidence_score',
                'confidence_level',
                'confidence_context',
                'actual_spread',
                'actual_total',
                'winner_correct',
                'depth_chart_context',
                'market_summary',
                'created_at',
                'updated_at',
            ],
            'meta' => [
                'sport',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('data.id', $prediction->id)
        ->assertJsonPath('data.sport', $slug)
        ->assertJsonPath('data.game_id', $game->id)
        ->assertJsonPath('data.home_team_id', $game->getAttribute('home_team_id'))
        ->assertJsonPath('data.away_team_id', $game->getAttribute('away_team_id'))
        ->assertJsonPath('data.home_win_probability', 0.642)
        ->assertJsonPath('data.away_win_probability', 0.358)
        ->assertJsonPath('data.win_probability', 0.642)
        ->assertJsonPath('data.predicted_spread', -3.5)
        ->assertJsonPath('data.predicted_total', 217.5)
        ->assertJsonPath('data.confidence_score', 71.25)
        ->assertJsonPath('data.confidence_level', 'medium')
        ->assertJsonPath('data.confidence_context.label', 'Medium');

    expect($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2PredictionContractSports');

it('shows a v2 game prediction with sport, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $predictionModel,
) {
    v2PredictionContractActingAsBypassUser();

    [$game, $prediction] = v2PredictionContractCreateGamePrediction($teamModel, $gameModel, $predictionModel);

    $response = $this->getJson("/api/v2/sports/{$slug}/games/{$game->id}/prediction")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'sport',
                'game_id',
                'home_team_id',
                'away_team_id',
                'game',
                'status',
                'pick',
                'projection' => [
                    'home_win_probability',
                    'away_win_probability',
                    'predicted_spread',
                    'predicted_total',
                    'confidence_score',
                    'confidence_context',
                ],
                'home_win_probability',
                'away_win_probability',
                'win_probability',
                'predicted_spread',
                'predicted_total',
                'confidence_score',
                'confidence_level',
                'confidence_context',
                'actual_spread',
                'actual_total',
                'winner_correct',
                'depth_chart_context',
                'market_summary',
                'created_at',
                'updated_at',
            ],
            'meta' => [
                'sport',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('data.id', $prediction->id)
        ->assertJsonPath('data.sport', $slug)
        ->assertJsonPath('data.game_id', $game->id)
        ->assertJsonPath('data.home_team_id', $game->getAttribute('home_team_id'))
        ->assertJsonPath('data.away_team_id', $game->getAttribute('away_team_id'))
        ->assertJsonPath('data.home_win_probability', 0.642)
        ->assertJsonPath('data.away_win_probability', 0.358)
        ->assertJsonPath('data.win_probability', 0.642)
        ->assertJsonPath('data.predicted_spread', -3.5)
        ->assertJsonPath('data.predicted_total', 217.5)
        ->assertJsonPath('data.confidence_score', 71.25)
        ->assertJsonPath('data.confidence_level', 'medium')
        ->assertJsonPath('data.confidence_context.label', 'Medium');

    expect($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2PredictionContractSports');

it('marks high raw prediction confidence as watch when sample context is missing', function () {
    v2PredictionContractActingAsBypassUser();

    [, $prediction] = v2PredictionContractCreateGamePrediction(
        MlbTeam::class,
        MlbGame::class,
        MlbPrediction::class,
        [],
        [
            'win_probability' => 0.92,
            'confidence_score' => 92.0,
            'model_metadata' => [
                'raw_inputs' => ['source' => 'legacy-high-confidence-row'],
            ],
        ],
    );

    $this->getJson("/api/v2/sports/mlb/predictions/{$prediction->id}")
        ->assertOk()
        ->assertJsonPath('data.confidence_level', 'high')
        ->assertJsonPath('data.confidence_context.label', 'Watch')
        ->assertJsonPath('data.confidence_context.tier', 'watch')
        ->assertJsonPath('data.confidence_context.raw_level', 'high')
        ->assertJsonPath('data.confidence_context.reason_codes.0', 'sample_context_missing');
});

it('presents cfb v2 prediction game dates in eastern football time', function () {
    v2PredictionContractActingAsBypassUser();

    [$game, $prediction] = v2PredictionContractCreateGamePrediction(
        CfbTeam::class,
        CfbGame::class,
        CfbPrediction::class,
        [
            'season' => 2026,
            'week' => 1,
            'game_date' => '2026-08-30 00:00:00',
            'game_time' => '02:00:00',
            'status' => 'STATUS_SCHEDULED',
        ],
    );

    $this->getJson("/api/v2/sports/cfb/predictions/{$prediction->id}")
        ->assertOk()
        ->assertJsonPath('data.game_id', $game->id)
        ->assertJsonPath('data.game.game_date', '2026-08-29')
        ->assertJsonPath('data.game.game_time', '22:00:00');
});

it('treats late-night wnba utc starts as the local game date for predictions', function () {
    v2PredictionContractActingAsBypassUser();

    [$game, $prediction] = v2PredictionContractCreateGamePrediction(
        WnbaTeam::class,
        WnbaGame::class,
        WnbaPrediction::class,
        [
            'season' => 2026,
            'game_date' => '2026-08-01 00:00:00',
            'game_time' => '02:00:00',
            'status' => 'STATUS_SCHEDULED',
        ],
    );

    v2PredictionContractCreateGamePrediction(
        WnbaTeam::class,
        WnbaGame::class,
        WnbaPrediction::class,
        [
            'season' => 2026,
            'game_date' => '2026-08-01 00:00:00',
            'game_time' => '20:00:00',
            'status' => 'STATUS_SCHEDULED',
        ],
    );

    $this->getJson("/api/v2/sports/wnba/predictions/{$prediction->id}")
        ->assertOk()
        ->assertJsonPath('data.game_id', $game->id)
        ->assertJsonPath('data.game.game_date', '2026-07-31')
        ->assertJsonPath('data.game.game_time', '22:00:00');

    $this->getJson('/api/v2/sports/wnba/predictions?season=2026&from_date=2026-07-31&to_date=2026-07-31&per_page=10')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $prediction->id)
        ->assertJsonPath('data.0.game.game_date', '2026-07-31');

    $this->getJson('/api/v2/sports/wnba/predictions/available-dates?season=2026')
        ->assertOk()
        ->assertJsonPath('data.0', '2026-07-31')
        ->assertJsonPath('data.1', '2026-08-01');
});

it('filters cfb v2 predictions by week zero', function () {
    v2PredictionContractActingAsBypassUser();

    [, $weekZeroPrediction] = v2PredictionContractCreateGamePrediction(
        CfbTeam::class,
        CfbGame::class,
        CfbPrediction::class,
        [
            'season' => 2026,
            'season_type' => 2,
            'week' => 0,
            'game_date' => '2026-08-29 00:00:00',
            'game_time' => '16:00:00',
        ],
    );

    v2PredictionContractCreateGamePrediction(
        CfbTeam::class,
        CfbGame::class,
        CfbPrediction::class,
        [
            'season' => 2026,
            'season_type' => 2,
            'week' => 1,
            'game_date' => '2026-09-03 00:00:00',
            'game_time' => '23:00:00',
        ],
    );

    $this->getJson('/api/v2/sports/cfb/predictions?season=2026&season_type=2&week=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $weekZeroPrediction->id)
        ->assertJsonPath('data.0.game.week', 0);
});

function v2PredictionContractActingAsBypassUser(): User
{
    $user = User::factory()->create();

    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);

    Sanctum::actingAs($user);

    return $user;
}

/**
 * @return array{0: Model, 1: Model}
 */
function v2PredictionContractCreateGamePrediction(
    string $teamModel,
    string $gameModel,
    string $predictionModel,
    array $gameOverrides = [],
    array $predictionOverrides = [],
): array {
    $homeTeam = $teamModel::factory()->create();
    $awayTeam = $teamModel::factory()->create();

    $game = $gameModel::factory()->create(array_filter(array_replace([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_time' => '18:05:00',
    ], $gameOverrides), fn ($value) => $value !== null));

    $prediction = $predictionModel::query()->create(v2PredictionContractAttributes($predictionModel, $game->id, $predictionOverrides));

    return [$game, $prediction];
}

/**
 * @return array<string, mixed>
 */
function v2PredictionContractAttributes(string $predictionModel, int $gameId, array $overrides = []): array
{
    $table = (new $predictionModel)->getTable();
    $columns = array_flip(Schema::getColumnListing($table));

    return array_intersect_key(array_replace([
        'game_id' => $gameId,
        'season' => 2026,
        'season_type' => '2',
        'home_elo' => 1510.5,
        'away_elo' => 1488.5,
        'home_team_elo' => 1510.5,
        'away_team_elo' => 1488.5,
        'home_pitcher_elo' => 1502.0,
        'away_pitcher_elo' => 1491.0,
        'home_combined_elo' => 1508.0,
        'away_combined_elo' => 1490.0,
        'predicted_spread' => -3.5,
        'predicted_total' => 217.5,
        'win_probability' => 0.642,
        'confidence_score' => 71.25,
        'model_version' => 'v2-contract-test',
        'feature_version' => 'v2-contract-test',
        'blend_version' => 'v2-contract-test',
        'model_metadata' => ['raw_inputs' => ['should_not' => 'leak']],
    ], $overrides), $columns);
}
