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

    [$game, $prediction] = v2PredictionContractCreateGamePrediction($teamModel, $gameModel, $predictionModel);

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
        ->assertJsonPath('data.0.game_id', $game->id);

    expect($response->json('meta.pagination'))->toBeArray()
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

    [$game] = v2PredictionContractCreateGamePrediction($teamModel, $gameModel, $predictionModel);

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
                ],
                'home_win_probability',
                'away_win_probability',
                'win_probability',
                'predicted_spread',
                'predicted_total',
                'confidence_score',
                'confidence_level',
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
        ->assertJsonPath('data.confidence_level', 'medium');

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
                ],
                'home_win_probability',
                'away_win_probability',
                'win_probability',
                'predicted_spread',
                'predicted_total',
                'confidence_score',
                'confidence_level',
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
        ->assertJsonPath('data.confidence_level', 'medium');

    expect($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2PredictionContractSports');

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
): array {
    $homeTeam = $teamModel::factory()->create();
    $awayTeam = $teamModel::factory()->create();

    $game = $gameModel::factory()->create(array_filter([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
    ], fn ($value) => $value !== null));

    $prediction = $predictionModel::query()->create(v2PredictionContractAttributes($predictionModel, $game->id));

    return [$game, $prediction];
}

/**
 * @return array<string, mixed>
 */
function v2PredictionContractAttributes(string $predictionModel, int $gameId): array
{
    $table = (new $predictionModel)->getTable();
    $columns = array_flip(Schema::getColumnListing($table));

    return array_intersect_key([
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
    ], $columns);
}
