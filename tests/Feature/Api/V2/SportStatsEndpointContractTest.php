<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Player as CbbPlayer;
use App\Models\CBB\PlayerStat as CbbPlayerStat;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CBB\TeamStat as CbbTeamStat;
use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\Player as CfbPlayer;
use App\Models\CFB\PlayerStat as CfbPlayerStat;
use App\Models\CFB\Team as CfbTeam;
use App\Models\CFB\TeamStat as CfbTeamStat;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\PlayerStat as MlbPlayerStat;
use App\Models\MLB\Team as MlbTeam;
use App\Models\MLB\TeamStat as MlbTeamStat;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\PlayerStat as NbaPlayerStat;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NBA\TeamStat as NbaTeamStat;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Player as NflPlayer;
use App\Models\NFL\PlayerStat as NflPlayerStat;
use App\Models\NFL\Team as NflTeam;
use App\Models\NFL\TeamStat as NflTeamStat;
use App\Models\User;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WCBB\Player as WcbbPlayer;
use App\Models\WCBB\PlayerStat as WcbbPlayerStat;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WCBB\TeamStat as WcbbTeamStat;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Player as WnbaPlayer;
use App\Models\WNBA\PlayerStat as WnbaPlayerStat;
use App\Models\WNBA\Team as WnbaTeam;
use App\Models\WNBA\TeamStat as WnbaTeamStat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2StatsContractSports', [
    'nba' => ['nba', NbaTeam::class, NbaGame::class, NbaPlayer::class, NbaPlayerStat::class, NbaTeamStat::class, 'points', 21, 'points', 101],
    'nfl' => ['nfl', NflTeam::class, NflGame::class, NflPlayer::class, NflPlayerStat::class, NflTeamStat::class, 'passing_yards', 245, 'total_yards', 375],
    'mlb' => ['mlb', MlbTeam::class, MlbGame::class, MlbPlayer::class, MlbPlayerStat::class, MlbTeamStat::class, 'hits', 3, 'runs', 5],
    'cbb' => ['cbb', CbbTeam::class, CbbGame::class, CbbPlayer::class, CbbPlayerStat::class, CbbTeamStat::class, 'points', 18, 'points', 77],
    'cfb' => ['cfb', CfbTeam::class, CfbGame::class, CfbPlayer::class, CfbPlayerStat::class, CfbTeamStat::class, 'passing_yards', 312, 'total_yards', 441],
    'wcbb' => ['wcbb', WcbbTeam::class, WcbbGame::class, WcbbPlayer::class, WcbbPlayerStat::class, WcbbTeamStat::class, 'points', 24, 'points', 83],
    'wnba' => ['wnba', WnbaTeam::class, WnbaGame::class, WnbaPlayer::class, WnbaPlayerStat::class, WnbaTeamStat::class, 'points', 19, 'points', 88],
]);

it('requires authenticated access for v2 stats endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/stats/player")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/stats/team")
        ->assertUnauthorized();
})->with('v2StatsContractSports');

it('returns a clean json 404 for unsupported v2 sport stats endpoints', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nhl/stats/player')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/stats/team')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('lists v2 player stats with stable metadata, filters, and raw stats bag', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $playerModel,
    string $playerStatModel,
    string $teamStatModel,
    string $playerStatKey,
    int $playerStatValue,
) {
    actAsV2StatsContractUser();

    $team = $teamModel::factory()->create();
    $opponent = $teamModel::factory()->create();
    $game = $gameModel::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
    ]);
    $player = createV2StatsContractPlayer($playerModel, $team->id);
    $stat = createV2StatsContractStat($playerStatModel, [
        'player_id' => $player->id,
        'team_id' => $team->id,
        'game_id' => $game->id,
        $playerStatKey => $playerStatValue,
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/stats/player?season=2026&team_id={$team->id}&player_id={$player->id}&game_id={$game->id}&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'player_id',
                    'team_id',
                    'game_id',
                    'season',
                    'season_type',
                    'game_date',
                    'player',
                    'team',
                    'game',
                    'stats',
                ],
            ],
            'meta' => [
                'sport',
                'stat_type',
                'filters',
                'pagination',
                'freshness',
                'warnings',
                'raw_stats',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.stat_type', 'player')
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('meta.filters.team_id', $team->id)
        ->assertJsonPath('meta.filters.player_id', $player->id)
        ->assertJsonPath('meta.filters.game_id', $game->id)
        ->assertJsonPath('meta.raw_stats.strategy', 'stats_bag')
        ->assertJsonPath('meta.raw_stats.field', 'stats')
        ->assertJsonPath('data.0.id', $stat->id)
        ->assertJsonPath('data.0.sport', $slug)
        ->assertJsonPath('data.0.player_id', $player->id)
        ->assertJsonPath('data.0.team_id', $team->id)
        ->assertJsonPath("data.0.stats.{$playerStatKey}", $playerStatValue);

    expect($response->json('data.0'))->not->toHaveKey($playerStatKey)
        ->and($response->json('data.0.stats'))->toBeArray()
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2StatsContractSports');

it('lists v2 player stat available seasons and dates with stable metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $playerModel,
    string $playerStatModel,
    string $teamStatModel,
    string $playerStatKey,
    int $playerStatValue,
) {
    actAsV2StatsContractUser();

    $team = $teamModel::factory()->create();
    $opponent = $teamModel::factory()->create();
    $game = $gameModel::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
    ]);
    $player = createV2StatsContractPlayer($playerModel, $team->id);
    createV2StatsContractStat($playerStatModel, [
        'player_id' => $player->id,
        'team_id' => $team->id,
        'game_id' => $game->id,
        $playerStatKey => $playerStatValue,
    ]);

    $expectedDate = substr((string) $game->getAttribute('game_date'), 0, 10);

    $this->getJson("/api/v2/sports/{$slug}/stats/player/available-seasons")
        ->assertOk()
        ->assertJsonPath('data.0', 2026)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.stat_type', 'player')
        ->assertJsonPath('meta.contract', 'sports.stats.player.available-seasons');

    $this->getJson("/api/v2/sports/{$slug}/stats/player/available-dates?season=2026")
        ->assertOk()
        ->assertJsonPath('data.0', $expectedDate)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.stat_type', 'player')
        ->assertJsonPath('meta.contract', 'sports.stats.player.available-dates')
        ->assertJsonPath('meta.filters.season', 2026);
})->with('v2StatsContractSports');

it('lists v2 team stats with stable metadata, filters, and raw stats bag', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $playerModel,
    string $playerStatModel,
    string $teamStatModel,
    string $playerStatKey,
    int $playerStatValue,
    string $teamStatKey,
    int $teamStatValue,
) {
    actAsV2StatsContractUser();

    $team = $teamModel::factory()->create();
    $opponent = $teamModel::factory()->create();
    $game = $gameModel::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
    ]);
    $stat = createV2StatsContractStat($teamStatModel, [
        'team_id' => $team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        $teamStatKey => $teamStatValue,
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/stats/team?season=2026&team_id={$team->id}&game_id={$game->id}&team_type=home&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'team_id',
                    'game_id',
                    'team_type',
                    'season',
                    'season_type',
                    'game_date',
                    'team',
                    'game',
                    'stats',
                ],
            ],
            'meta' => [
                'sport',
                'stat_type',
                'filters',
                'pagination',
                'freshness',
                'warnings',
                'raw_stats',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.stat_type', 'team')
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('meta.filters.team_id', $team->id)
        ->assertJsonPath('meta.filters.game_id', $game->id)
        ->assertJsonPath('meta.filters.team_type', 'home')
        ->assertJsonPath('meta.raw_stats.strategy', 'stats_bag')
        ->assertJsonPath('meta.raw_stats.field', 'stats')
        ->assertJsonPath('data.0.id', $stat->id)
        ->assertJsonPath('data.0.sport', $slug)
        ->assertJsonPath('data.0.team_id', $team->id)
        ->assertJsonPath('data.0.team_type', 'home')
        ->assertJsonPath("data.0.stats.{$teamStatKey}", $teamStatValue);

    expect($response->json('data.0'))->not->toHaveKey($teamStatKey)
        ->and($response->json('data.0.stats'))->toBeArray()
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2StatsContractSports');

it('lists v2 team stat available seasons and dates with stable metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
    string $playerModel,
    string $playerStatModel,
    string $teamStatModel,
    string $playerStatKey,
    int $playerStatValue,
    string $teamStatKey,
    int $teamStatValue,
) {
    actAsV2StatsContractUser();

    $team = $teamModel::factory()->create();
    $opponent = $teamModel::factory()->create();
    $game = $gameModel::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
    ]);
    createV2StatsContractStat($teamStatModel, [
        'team_id' => $team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        $teamStatKey => $teamStatValue,
    ]);

    $expectedDate = substr((string) $game->getAttribute('game_date'), 0, 10);

    $this->getJson("/api/v2/sports/{$slug}/stats/team/available-seasons")
        ->assertOk()
        ->assertJsonPath('data.0', 2026)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.stat_type', 'team')
        ->assertJsonPath('meta.contract', 'sports.stats.team.available-seasons');

    $this->getJson("/api/v2/sports/{$slug}/stats/team/available-dates?season=2026")
        ->assertOk()
        ->assertJsonPath('data.0', $expectedDate)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.stat_type', 'team')
        ->assertJsonPath('meta.contract', 'sports.stats.team.available-dates')
        ->assertJsonPath('meta.filters.season', 2026);
})->with('v2StatsContractSports');

function actAsV2StatsContractUser(): User
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
function createV2StatsContractPlayer(string $playerModel, int $teamId): Model
{
    $table = (new $playerModel)->getTable();

    return $playerModel::query()->create(array_filter([
        'team_id' => $teamId,
        'espn_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        'first_name' => 'Stats',
        'last_name' => 'Contract',
        'full_name' => 'Stats Contract',
        'name' => 'Stats Contract',
        'display_name' => 'Stats Contract',
        'jersey_number' => 7,
        'position' => 'G',
        'status' => 'Active',
        'batting_hand' => 'R',
        'throwing_hand' => 'R',
    ], fn (mixed $value, string $column): bool => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_BOTH));
}

/**
 * @param  class-string<Model>  $statModel
 * @param  array<string, mixed>  $overrides
 */
function createV2StatsContractStat(string $statModel, array $overrides): Model
{
    $table = (new $statModel)->getTable();

    $attributes = array_merge([
        'stat_type' => 'batting',
        'team_type' => 'home',
        'minutes_played' => '31:00',
        'points' => 0,
        'passing_yards' => 0,
        'total_yards' => 0,
        'hits' => 0,
        'runs' => 0,
    ], $overrides);

    return $statModel::query()->create(array_filter(
        $attributes,
        fn (mixed $value, string $column): bool => $value !== null && Schema::hasColumn($table, $column),
        ARRAY_FILTER_USE_BOTH,
    ));
}
