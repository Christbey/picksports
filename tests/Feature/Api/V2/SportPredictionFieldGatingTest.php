<?php

use App\Models\CBB\Game as FieldGatingCbbGame;
use App\Models\CBB\Prediction as FieldGatingCbbPrediction;
use App\Models\CBB\Team as FieldGatingCbbTeam;
use App\Models\CFB\Game as FieldGatingCfbGame;
use App\Models\CFB\Prediction as FieldGatingCfbPrediction;
use App\Models\CFB\Team as FieldGatingCfbTeam;
use App\Models\MLB\Game as FieldGatingMlbGame;
use App\Models\MLB\Prediction as FieldGatingMlbPrediction;
use App\Models\MLB\Team as FieldGatingMlbTeam;
use App\Models\NBA\Game as FieldGatingNbaGame;
use App\Models\NBA\Prediction as FieldGatingNbaPrediction;
use App\Models\NBA\Team as FieldGatingNbaTeam;
use App\Models\NFL\Game as FieldGatingNflGame;
use App\Models\NFL\Prediction as FieldGatingNflPrediction;
use App\Models\NFL\Team as FieldGatingNflTeam;
use App\Models\User;
use App\Models\WCBB\Game as FieldGatingWcbbGame;
use App\Models\WCBB\Prediction as FieldGatingWcbbPrediction;
use App\Models\WCBB\Team as FieldGatingWcbbTeam;
use App\Models\WNBA\Game as FieldGatingWnbaGame;
use App\Models\WNBA\Prediction as FieldGatingWnbaPrediction;
use App\Models\WNBA\Team as FieldGatingWnbaTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2PredictionFieldGatingSports', [
    'nba' => ['nba', FieldGatingNbaTeam::class, FieldGatingNbaGame::class, FieldGatingNbaPrediction::class],
    'nfl' => ['nfl', FieldGatingNflTeam::class, FieldGatingNflGame::class, FieldGatingNflPrediction::class],
    'mlb' => ['mlb', FieldGatingMlbTeam::class, FieldGatingMlbGame::class, FieldGatingMlbPrediction::class],
    'cbb' => ['cbb', FieldGatingCbbTeam::class, FieldGatingCbbGame::class, FieldGatingCbbPrediction::class],
    'cfb' => ['cfb', FieldGatingCfbTeam::class, FieldGatingCfbGame::class, FieldGatingCfbPrediction::class],
    'wcbb' => ['wcbb', FieldGatingWcbbTeam::class, FieldGatingWcbbGame::class, FieldGatingWcbbPrediction::class],
    'wnba' => ['wnba', FieldGatingWnbaTeam::class, FieldGatingWnbaGame::class, FieldGatingWnbaPrediction::class],
]);

it('omits narrative, ai analysis, betting value, and raw model internals from default v2 prediction payloads', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $predictionModel,
) {
    v2PredictionFieldGatingActingAsBypassUser();

    [$game, $prediction] = v2PredictionFieldGatingCreateGamePrediction($teamModel, $gameModel, $predictionModel);

    foreach ([
        "/api/v2/sports/{$slug}/predictions?season=2026",
        "/api/v2/sports/{$slug}/predictions/{$prediction->id}",
        "/api/v2/sports/{$slug}/games/{$game->id}/prediction",
    ] as $uri) {
        $payload = $this->getJson($uri)
            ->assertOk()
            ->json();

        v2PredictionFieldGatingAssertNoSensitivePredictionKeys($payload);
    }
})->with('v2PredictionFieldGatingSports');

function v2PredictionFieldGatingActingAsBypassUser(): User
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
function v2PredictionFieldGatingCreateGamePrediction(
    string $teamModel,
    string $gameModel,
    string $predictionModel,
): array {
    $homeTeam = $teamModel::factory()->create();
    $awayTeam = $teamModel::factory()->create();

    $game = $gameModel::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $prediction = $predictionModel::query()->create(v2PredictionFieldGatingAttributes($predictionModel, $game->id));

    return [$game, $prediction];
}

/**
 * @return array<string, mixed>
 */
function v2PredictionFieldGatingAttributes(string $predictionModel, int $gameId): array
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
        'model_version' => 'v2-field-gating-test',
        'feature_version' => 'v2-field-gating-test',
        'blend_version' => 'v2-field-gating-test',
        'model_metadata' => [
            'raw_inputs' => ['home_form' => [1, 2, 3]],
            'analysis_layer' => ['reason_codes' => ['seeded-sensitive-context']],
        ],
        'narrative_json' => [
            'headline' => 'Seeded narrative should not leak by default.',
            'summary' => 'This verifies V2 default responses keep narrative copy gated.',
        ],
        'narrative_provider' => 'test-provider',
        'narrative_model' => 'test-model',
        'narrative_input_hash' => 'test-hash',
    ], $columns);
}

/**
 * @param  array<string, mixed>  $payload
 */
function v2PredictionFieldGatingAssertNoSensitivePredictionKeys(array $payload): void
{
    $keys = v2PredictionFieldGatingPayloadKeys($payload);
    $exactForbiddenKeys = [
        'narrative',
        'narrative_json',
        'narrative_provider',
        'narrative_model',
        'narrative_input_hash',
        'narrative_latency_ms',
        'narrative_generated_at',
        'ai_analysis',
        'prediction_analysis',
        'betting_value',
        'betting_value_summary',
        'model_metadata',
    ];

    expect(array_intersect($keys, $exactForbiddenKeys))->toBeEmpty();

    $rawKeys = array_filter(
        $keys,
        fn (string $key): bool => $key === 'raw' || str_starts_with($key, 'raw_') || str_contains($key, '_raw')
    );

    expect(array_values($rawKeys))->toBeEmpty();
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<int, string>
 */
function v2PredictionFieldGatingPayloadKeys(array $payload): array
{
    $keys = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            $keys = array_merge($keys, v2PredictionFieldGatingPayloadKeys($value));
        }
    }

    return array_values(array_unique($keys));
}
