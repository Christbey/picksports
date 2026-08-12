<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Player as CbbPlayer;
use App\Models\CBB\PlayerProp as CbbPlayerProp;
use App\Models\CBB\Team as CbbTeam;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\PlayerProp as MlbPlayerProp;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\PlayerProp as NbaPlayerProp;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Player as NflPlayer;
use App\Models\NFL\PlayerProp as NflPlayerProp;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Player as WnbaPlayer;
use App\Models\WNBA\PlayerProp as WnbaPlayerProp;
use App\Models\WNBA\Team as WnbaTeam;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2PlayerPropContractSports', [
    'nba' => ['nba', NbaTeam::class, NbaPlayer::class, NbaGame::class, NbaPlayerProp::class],
    'nfl' => ['nfl', NflTeam::class, NflPlayer::class, NflGame::class, NflPlayerProp::class],
    'mlb' => ['mlb', MlbTeam::class, MlbPlayer::class, MlbGame::class, MlbPlayerProp::class],
    'cbb' => ['cbb', CbbTeam::class, CbbPlayer::class, CbbGame::class, CbbPlayerProp::class],
    'wnba' => ['wnba', WnbaTeam::class, WnbaPlayer::class, WnbaGame::class, WnbaPlayerProp::class],
]);

it('requires authenticated access for v2 player prop endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/markets/player-props")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/player-props/board")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/players/1/player-props")
        ->assertUnauthorized();
})->with('v2PlayerPropContractSports');

it('returns a clean json 404 for unsupported v2 sport player prop endpoints', function () {
    actAsV2PlayerPropContractUser();

    $this->getJson('/api/v2/sports/nhl/markets/player-props')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/players/1/player-props')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('returns the v2 player prop recommendation board envelope from the analyzer', function () {
    actAsV2PlayerPropContractUser();

    $analyzer = Mockery::mock(PlayerPropAnalyzer::class);
    $analyzer
        ->shouldReceive('precomputedRecommendations')
        ->once()
        ->with('NBA', '2026-06-10', 99, 'player_points', 75)
        ->andReturn(collect());
    $analyzer
        ->shouldReceive('precomputedRecommendationDiagnostics')
        ->once()
        ->with('NBA', '2026-06-10', 99, 'player_points')
        ->andReturn([
            'raw_prop_count' => 0,
            'analyzed_prop_count' => 0,
            'recommendation_candidate_count' => 0,
            'missing_player_link_count' => 0,
        ]);
    $analyzer
        ->shouldReceive('getAvailableDatesForSport')
        ->once()
        ->with('NBA')
        ->andReturn(collect([
            ['value' => '2026-06-10', 'label' => 'Jun 10'],
        ]));
    $analyzer
        ->shouldReceive('getAvailableGamesForSport')
        ->once()
        ->with('NBA', '2026-06-10')
        ->andReturn(collect([
            ['id' => 99, 'label' => 'BOS @ NYK', 'date' => '2026-06-10', 'time' => '7:00 PM'],
        ]));
    $analyzer
        ->shouldReceive('getAvailableMarketsForSport')
        ->once()
        ->with('NBA', '2026-06-10', 99)
        ->andReturn(collect([
            ['value' => 'player_points', 'label' => 'Points'],
        ]));

    app()->instance(PlayerPropAnalyzer::class, $analyzer);

    $url = '/api/v2/sports/nba/player-props/board?date=2026-06-10&game=99&market=player_points';

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('sport', 'NBA')
        ->assertJsonPath('data', [])
        ->assertJsonPath('dates.0.value', '2026-06-10')
        ->assertJsonPath('games.0.id', 99)
        ->assertJsonPath('markets.0.value', 'player_points')
        ->assertJsonPath('filters.date', '2026-06-10')
        ->assertJsonPath('filters.game', 99)
        ->assertJsonPath('filters.market', 'player_points')
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', 'nba')
        ->assertJsonPath('meta.contract', 'sports.player-props.board')
        ->assertJsonPath('meta.source', 'precomputed');

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('filters.game', 99);
});

it('renders v2 player prop board recommendations when provider player names are not linked to internal players', function () {
    actAsV2PlayerPropContractUser();

    $homeTeam = MlbTeam::factory()->create(['name' => 'Los Angeles Dodgers', 'abbreviation' => 'LAD']);
    $awayTeam = MlbTeam::factory()->create(['name' => 'Baltimore Orioles', 'abbreviation' => 'BAL']);
    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-21',
    ]);

    MlbPlayerProp::create([
        'game_id' => $game->id,
        'player_id' => null,
        'player_name' => 'Provider Only Batter',
        'market' => 'batter_hits',
        'bookmaker' => 'draftkings',
        'line' => 1.5,
        'over_price' => 110,
        'under_price' => -130,
        'recommended_side' => 'Over',
        'confidence_score' => 72,
        'predicted_over_probability' => 64.2,
        'market_over_probability' => 47.5,
        'edge_probability' => 16.7,
        'data_quality_score' => 82,
        'match_quality_score' => 70,
        'confidence_decomposition' => [
            'schema_version' => 'player-prop-signal-v2',
            'stat_summary' => [
                'season_avg' => 1.7,
                'recent_avg' => 1.9,
                'last5_avg' => 2.0,
                'consistency' => ['level' => 'stable'],
            ],
            'cover_record' => [
                'season' => [
                    'games' => 24,
                    'recommendation' => 'Over',
                    'recommendation_record' => '14-10',
                    'win_rate' => 58.3,
                ],
                'last_10' => null,
                'last_5' => null,
                'home_away' => null,
                'vs_opponent' => null,
            ],
            'signal_quality' => [
                'label' => 'Lean',
                'tier' => 'lean',
                'reason_codes' => [],
            ],
        ],
    ]);

    $this->getJson('/api/v2/sports/mlb/player-props/board?date=2026-06-21')
        ->assertOk()
        ->assertJsonPath('data.0.player.id', null)
        ->assertJsonPath('data.0.player.name', 'Provider Only Batter')
        ->assertJsonPath('data.0.player.url', null)
        ->assertJsonPath('data.0.recommendation', 'Over')
        ->assertJsonPath('meta.diagnostics.raw_prop_count', 1)
        ->assertJsonPath('meta.diagnostics.recommendation_candidate_count', 1)
        ->assertJsonPath('meta.diagnostics.missing_player_link_count', 1);
});

it('defaults the player prop board date to today or the next available slate for every sport', function () {
    Carbon::setTestNow('2026-06-13 10:00:00');
    actAsV2PlayerPropContractUser();

    $analyzer = Mockery::mock(PlayerPropAnalyzer::class);
    $analyzer
        ->shouldReceive('getAvailableDatesForSport')
        ->once()
        ->with('MLB')
        ->andReturn(collect([
            ['value' => '2026-06-12', 'label' => 'Jun 12'],
            ['value' => '2026-06-14', 'label' => 'Jun 14'],
        ]));
    $analyzer
        ->shouldReceive('precomputedRecommendations')
        ->once()
        ->with('MLB', '2026-06-14', null, null, 75)
        ->andReturn(collect());
    $analyzer
        ->shouldReceive('precomputedRecommendationDiagnostics')
        ->once()
        ->with('MLB', '2026-06-14', null, null)
        ->andReturn([
            'raw_prop_count' => 0,
            'analyzed_prop_count' => 0,
            'recommendation_candidate_count' => 0,
            'missing_player_link_count' => 0,
        ]);
    $analyzer
        ->shouldReceive('getAvailableGamesForSport')
        ->once()
        ->with('MLB', '2026-06-14')
        ->andReturn(collect());
    $analyzer
        ->shouldReceive('getAvailableMarketsForSport')
        ->once()
        ->with('MLB', '2026-06-14', null)
        ->andReturn(collect());

    app()->instance(PlayerPropAnalyzer::class, $analyzer);

    $this->getJson('/api/v2/sports/mlb/player-props/board')
        ->assertOk()
        ->assertJsonPath('filters.date', '2026-06-14');

    Carbon::setTestNow();
});

it('lists v2 market player props with stable shape, filters, pagination, freshness, and warnings', function (
    string $slug,
    string $teamModel,
    string $playerModel,
    string $gameModel,
    string $playerPropModel,
) {
    actAsV2PlayerPropContractUser();

    [$team, $opponent, $player, $game] = createV2PlayerPropContractFixture(
        teamModel: $teamModel,
        playerModel: $playerModel,
        gameModel: $gameModel,
        gameDate: '2026-06-10',
    );

    $prop = createV2PlayerPropContractProp($playerPropModel, [
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Contract Prop Player',
        'market' => 'player_points',
        'bookmaker' => 'draftkings',
        'line' => 22.5,
        'over_price' => -110,
        'under_price' => -105,
    ]);
    createV2PlayerPropContractProp($playerPropModel, [
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Contract Prop Player',
        'market' => 'player_rebounds',
        'bookmaker' => 'draftkings',
        'line' => 7.5,
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/markets/player-props?date=2026-06-10&game_id={$game->id}&market=player_points&bookmaker=draftkings&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'game_id',
                    'player_id',
                    'player_name',
                    'market',
                    'bookmaker',
                    'line',
                    'over_price',
                    'under_price',
                    'fetched_at',
                    'player',
                    'game',
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
        ->assertJsonPath('meta.filters.date', '2026-06-10')
        ->assertJsonPath('meta.filters.game_id', $game->id)
        ->assertJsonPath('meta.filters.market', 'player_points')
        ->assertJsonPath('meta.filters.bookmaker', 'draftkings')
        ->assertJsonPath('data.0.id', $prop->id)
        ->assertJsonPath('data.0.sport', $slug)
        ->assertJsonPath('data.0.game_id', $game->id)
        ->assertJsonPath('data.0.player_id', $player->id)
        ->assertJsonPath('data.0.market', 'player_points')
        ->assertJsonPath('data.0.bookmaker', 'draftkings')
        ->assertJsonPath('data.0.recommendation.signal_quality.label', 'Lean')
        ->assertJsonPath('data.0.recommendation.signal_quality.tier', 'lean');

    expect($team)->toBeInstanceOf(Model::class)
        ->and($opponent)->toBeInstanceOf(Model::class)
        ->and($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();

    assertV2PlayerPropContractHasNoNarrativeLeakage($response->json());
})->with('v2PlayerPropContractSports');

it('lists v2 player-scoped player props with stable shape, filters, freshness, and warnings', function (
    string $slug,
    string $teamModel,
    string $playerModel,
    string $gameModel,
    string $playerPropModel,
) {
    actAsV2PlayerPropContractUser();

    [$team, $opponent, $player, $game] = createV2PlayerPropContractFixture(
        teamModel: $teamModel,
        playerModel: $playerModel,
        gameModel: $gameModel,
        gameDate: '2026-06-10',
    );
    [, , $otherPlayer] = createV2PlayerPropContractFixture(
        teamModel: $teamModel,
        playerModel: $playerModel,
        gameModel: $gameModel,
        gameDate: '2026-06-10',
    );

    $prop = createV2PlayerPropContractProp($playerPropModel, [
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Contract Prop Player',
        'market' => 'player_assists',
        'bookmaker' => 'fanduel',
        'line' => 6.5,
        'over_price' => 100,
        'under_price' => -120,
    ]);
    createV2PlayerPropContractProp($playerPropModel, [
        'game_id' => $game->id,
        'player_id' => $otherPlayer->id,
        'player_name' => 'Other Prop Player',
        'market' => 'player_assists',
        'bookmaker' => 'fanduel',
        'line' => 4.5,
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/players/{$player->id}/player-props?date=2026-06-10&market=player_assists&bookmaker=fanduel")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'game_id',
                    'player_id',
                    'player_name',
                    'market',
                    'bookmaker',
                    'line',
                    'over_price',
                    'under_price',
                    'fetched_at',
                    'player',
                    'game',
                ],
            ],
            'meta' => [
                'sport',
                'player_id',
                'filters',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.player_id', $player->id)
        ->assertJsonPath('meta.filters.date', '2026-06-10')
        ->assertJsonPath('meta.filters.market', 'player_assists')
        ->assertJsonPath('meta.filters.bookmaker', 'fanduel')
        ->assertJsonPath('data.0.id', $prop->id)
        ->assertJsonPath('data.0.sport', $slug)
        ->assertJsonPath('data.0.game_id', $game->id)
        ->assertJsonPath('data.0.player_id', $player->id)
        ->assertJsonPath('data.0.market', 'player_assists')
        ->assertJsonPath('data.0.bookmaker', 'fanduel');

    expect($team)->toBeInstanceOf(Model::class)
        ->and($opponent)->toBeInstanceOf(Model::class)
        ->and($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();

    assertV2PlayerPropContractHasNoNarrativeLeakage($response->json());
})->with('v2PlayerPropContractSports');

function actAsV2PlayerPropContractUser(): User
{
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  class-string<Model>  $teamModel
 * @param  class-string<Model>  $playerModel
 * @param  class-string<Model>  $gameModel
 * @return array{0: Model, 1: Model, 2: Model, 3: Model}
 */
function createV2PlayerPropContractFixture(
    string $teamModel,
    string $playerModel,
    string $gameModel,
    string $gameDate,
): array {
    $team = $teamModel::factory()->create();
    $opponent = $teamModel::factory()->create();

    $player = createV2PlayerPropContractPlayer($playerModel, [
        'team_id' => $team->id,
        'full_name' => 'Contract Prop Player',
        'first_name' => 'Contract',
        'last_name' => 'Player',
        'position' => 'G',
    ]);

    $game = $gameModel::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => $gameDate,
    ]);

    return [$team, $opponent, $player, $game];
}

/**
 * @param  class-string<Model>  $playerModel
 * @param  array<string, mixed>  $overrides
 */
function createV2PlayerPropContractPlayer(string $playerModel, array $overrides = []): Model
{
    $table = (new $playerModel)->getTable();
    $attributes = array_merge([
        'team_id' => null,
        'espn_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        'first_name' => 'Contract',
        'last_name' => 'Player',
        'full_name' => 'Contract Prop Player',
        'name' => 'Contract Prop Player',
        'display_name' => 'Contract Prop Player',
        'jersey_number' => 12,
        'position' => 'G',
        'height' => '6-5',
        'weight' => 205,
        'age' => 28,
        'experience' => 5,
        'year' => 'Senior',
        'college' => 'Contract University',
        'hometown' => 'Contract City, TX',
        'status' => 'Active',
        'batting_hand' => 'R',
        'throwing_hand' => 'R',
        'headshot_url' => 'https://example.com/player.png',
    ], $overrides);

    return $playerModel::query()->create(array_filter(
        $attributes,
        fn (mixed $value, string $column): bool => $value !== null && Schema::hasColumn($table, $column),
        ARRAY_FILTER_USE_BOTH,
    ));
}

/**
 * @param  class-string<Model>  $playerPropModel
 * @param  array<string, mixed>  $overrides
 */
function createV2PlayerPropContractProp(string $playerPropModel, array $overrides = []): Model
{
    $table = (new $playerPropModel)->getTable();
    $attributes = array_merge([
        'odds_api_event_id' => 'contract-event-'.fake()->unique()->numberBetween(1000, 9999),
        'player_name' => 'Contract Prop Player',
        'market' => 'player_points',
        'bookmaker' => 'draftkings',
        'line' => 22.5,
        'over_price' => -110,
        'under_price' => -105,
        'raw_data' => ['source' => 'contract-test'],
        'fetched_at' => '2026-06-09 12:00:00',
        'recommended_side' => 'over',
        'confidence_score' => 72,
        'predicted_over_probability' => 0.58,
        'market_over_probability' => 0.52,
        'edge_probability' => 0.06,
        'data_quality_score' => 90,
        'match_quality_score' => 95,
        'context_adjustment_factor' => 1.000,
        'confidence_decomposition' => [
            'schema_version' => 'player-prop-signal-v2',
            'signal_quality' => [
                'label' => 'Lean',
                'tier' => 'lean',
                'reason_codes' => [],
            ],
        ],
        'narrative_json' => ['summary' => 'This AI-written explanation must not leak.'],
        'narrative_provider' => 'openai',
        'narrative_model' => 'gpt-contract',
        'narrative_input_hash' => 'contract-narrative-hash',
        'narrative_latency_ms' => 123,
        'narrative_generated_at' => '2026-06-09 12:05:00',
    ], $overrides);

    return $playerPropModel::query()->create(array_filter(
        $attributes,
        fn (mixed $value, string $column): bool => $value !== null && Schema::hasColumn($table, $column),
        ARRAY_FILTER_USE_BOTH,
    ));
}

function assertV2PlayerPropContractHasNoNarrativeLeakage(array $payload): void
{
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    foreach ([
        'narrative_json',
        'narrative_provider',
        'narrative_model',
        'narrative_input_hash',
        'narrative_latency_ms',
        'narrative_generated_at',
        'confidence_decomposition',
        'prompt',
        'completion',
        'openai',
        'gpt-contract',
        'AI-written explanation',
    ] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
}
