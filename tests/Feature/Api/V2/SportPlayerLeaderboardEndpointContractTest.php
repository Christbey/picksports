<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Player as CbbPlayer;
use App\Models\CBB\PlayerStat as CbbPlayerStat;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\Player as CfbPlayer;
use App\Models\CFB\PlayerStat as CfbPlayerStat;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\PlayerStat as MlbPlayerStat;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\PlayerStat as NbaPlayerStat;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Player as NflPlayer;
use App\Models\NFL\PlayerStat as NflPlayerStat;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Player as WnbaPlayer;
use App\Models\WNBA\PlayerStat as WnbaPlayerStat;
use App\Models\WNBA\Team as WnbaTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2PlayerLeaderboardSports', [
    'nba' => ['nba', NbaTeam::class, NbaGame::class, NbaPlayer::class, NbaPlayerStat::class, 'points', 24],
    'cbb' => ['cbb', CbbTeam::class, CbbGame::class, CbbPlayer::class, CbbPlayerStat::class, 'points', 22],
    'nfl' => ['nfl', NflTeam::class, NflGame::class, NflPlayer::class, NflPlayerStat::class, 'passing_yards', 280],
    'mlb' => ['mlb', MlbTeam::class, MlbGame::class, MlbPlayer::class, MlbPlayerStat::class, 'hits', 3],
    'cfb' => ['cfb', CfbTeam::class, CfbGame::class, CfbPlayer::class, CfbPlayerStat::class, 'passing_yards', 325],
    'wnba' => ['wnba', WnbaTeam::class, WnbaGame::class, WnbaPlayer::class, WnbaPlayerStat::class, 'points', 21],
]);

it('requires authenticated access for v2 player leaderboard endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/leaderboards/players")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/leaderboards/players/available-seasons")
        ->assertUnauthorized();
})->with('v2PlayerLeaderboardSports');

it('returns a clean json 404 for unsupported v2 player leaderboard sports', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nhl/leaderboards/players')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/wcbb/leaderboards/players')
        ->assertNotFound()
        ->assertJsonPath('message', 'Leaderboard not available for this sport');
});

it('lists v2 player leaderboards with stable metadata and existing aggregate fields', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $playerModel,
    string $playerStatModel,
    string $statKey,
    int $statValue,
) {
    actAsV2PlayerLeaderboardContractUser();

    $team = $teamModel::factory()->create();
    $opponent = $teamModel::factory()->create();
    $game = $gameModel::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
    ]);
    $player = createV2PlayerLeaderboardContractPlayer($playerModel, $team->id);
    createV2PlayerLeaderboardContractStat($playerStatModel, [
        'game_id' => $game->id,
        'team_id' => $team->id,
        'player_id' => $player->id,
        'stat_type' => $slug === 'mlb' ? 'batting' : null,
        $statKey => $statValue,
        'at_bats' => 4,
        'passing_attempts' => 20,
        'passing_completions' => 12,
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/leaderboards/players?season=2026&season_type=2&min_games=1")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'player_id',
                    'player',
                    'games_played',
                    'points_per_game',
                ],
            ],
            'meta' => [
                'version',
                'sport',
                'contract',
                'filters',
                'tier',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.leaderboards.players.index')
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('meta.filters.season_type', '2')
        ->assertJsonPath('meta.filters.min_games', 1)
        ->assertJsonPath('data.0.player_id', $player->id)
        ->assertJsonPath('data.0.games_played', 1);

    expect($response->json('data.0.player'))->toBeArray()
        ->and($response->json('data.0.points_per_game'))->not->toBeNull()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2PlayerLeaderboardSports');

it('lists v2 player leaderboard available seasons with stable metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $playerModel,
    string $playerStatModel,
    string $statKey,
    int $statValue,
) {
    actAsV2PlayerLeaderboardContractUser();

    $team = $teamModel::factory()->create();
    $opponent = $teamModel::factory()->create();
    $game = $gameModel::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
    ]);
    $player = createV2PlayerLeaderboardContractPlayer($playerModel, $team->id);
    createV2PlayerLeaderboardContractStat($playerStatModel, [
        'game_id' => $game->id,
        'team_id' => $team->id,
        'player_id' => $player->id,
        $statKey => $statValue,
    ]);

    $this->getJson("/api/v2/sports/{$slug}/leaderboards/players/available-seasons")
        ->assertOk()
        ->assertJsonPath('data.0', 2026)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.leaderboards.players.available-seasons');
})->with('v2PlayerLeaderboardSports');

function actAsV2PlayerLeaderboardContractUser(): User
{
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  class-string<Model>  $playerModel
 */
function createV2PlayerLeaderboardContractPlayer(string $playerModel, int $teamId): Model
{
    $table = (new $playerModel)->getTable();
    $payload = [
        'espn_id' => 'leaderboard-player-'.strtolower(class_basename($playerModel)).'-'.$teamId,
        'team_id' => $teamId,
        'full_name' => 'Leaderboard Player',
        'first_name' => 'Leaderboard',
        'last_name' => 'Player',
        'position' => 'QB',
        'jersey_number' => '12',
    ];

    return Model::unguarded(fn () => $playerModel::query()->create(
        collect($payload)
            ->filter(fn (mixed $value, string $key): bool => Schema::hasColumn($table, $key))
            ->all()
    ));
}

/**
 * @param  class-string<Model>  $statModel
 * @param  array<string, mixed>  $attributes
 */
function createV2PlayerLeaderboardContractStat(string $statModel, array $attributes): Model
{
    $table = (new $statModel)->getTable();
    $payload = collect($attributes)
        ->filter(fn (mixed $value, string $key): bool => $value !== null && Schema::hasColumn($table, $key))
        ->all();

    return Model::unguarded(fn () => $statModel::query()->create($payload));
}
