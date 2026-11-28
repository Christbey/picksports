<?php

use App\Http\Resources\NBA\PlayerStatResource;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;
use App\Models\User;
use App\Services\Api\V2\SportPlayerLeaderboardQuery;
use App\Services\PlayerStats\BasketballLeaderboardService;
use App\Services\PlayerStats\NbaPlayerEpaCalculator;
use App\Support\SportsViewCache;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

function basketballObservedZeros(): array
{
    return array_fill_keys(['points', 'assists', 'rebounds_total', 'steals', 'blocks', 'turnovers', 'field_goals_made', 'field_goals_attempted', 'free_throws_made', 'free_throws_attempted'], 0);
}

function basketballObservationRows(array $rows): Player
{
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();
    $player = Player::factory()->for($team)->create();
    foreach ($rows as $attributes) {
        $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opponent->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL']);
        PlayerStat::query()->create(['game_id' => $game->id, 'team_id' => $team->id, 'player_id' => $player->id, ...$attributes]);
    }
    Sanctum::actingAs(User::factory()->admin()->create());

    return $player;
}

it('serializes basketball fields instead of football fields in the declared womens resources', function (string $sport) {
    $model = 'App\\Models\\'.$sport.'\\PlayerStat';
    $resource = 'App\\Http\\Resources\\'.$sport.'\\PlayerStatResource';
    $row = new $model;
    $row->forceFill(['id' => 1, 'points' => 0, 'assists' => 4, 'rebounds_total' => null, 'minutes_played' => '12:00']);
    $payload = (new $resource($row))->resolve(Request::create('/'));
    expect($payload)->toHaveKey('points', 0)->toHaveKey('assists', 4)->toHaveKey('rebounds_total', null)->not->toHaveKey('passing_yards');
})->with(['WNBA', 'WCBB']);

it('keeps incomplete box-score estimates unknown in legacy basketball read resources', function (string $sport) {
    $model = 'App\\Models\\'.$sport.'\\PlayerStat';
    $resource = 'App\\Http\\Resources\\'.$sport.'\\PlayerStatResource';
    $row = new $model;
    $row->forceFill([...basketballObservedZeros(), 'steals' => null, 'minutes_played' => '12:00']);
    $payload = (new $resource($row))->resolve(Request::create('/'));
    expect($payload['estimated_epa'])->toBeNull()->and($payload['estimated_epa_per_36'])->toBeNull();
})->with(['NBA', 'CBB']);

it('retains true all-zero observed box-score estimates', function () {
    $row = new PlayerStat;
    $row->forceFill([...basketballObservedZeros(), 'minutes_played' => '12:00']);
    $payload = (new PlayerStatResource($row))->resolve(Request::create('/'));
    expect($payload['estimated_epa'])->toEqual(0)->and($payload['estimated_epa_per_36'])->toEqual(0);
});

it('keeps all-missing aggregate observations null at the actual leaderboard endpoint', function () {
    basketballObservationRows([['minutes_played' => null]]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.points_per_game', null)
        ->assertJsonPath('data.0.estimated_epa_per_game', null)
        ->assertJsonPath('data.0.minutes_per_game', null)
        ->assertJsonPath('data.0.field_samples.points', 0);
});

it('does not recombine inconsistent observed rowsets into complete EPA', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'points' => 10, 'steals' => null, 'minutes_played' => '12:00'], [...basketballObservedZeros(), 'points' => 20, 'steals' => 2, 'minutes_played' => '12:00']]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.points_per_game', 15)
        ->assertJsonPath('data.0.estimated_epa_per_game', null)
        ->assertJsonPath('data.0.field_samples.points', 2)
        ->assertJsonPath('data.0.field_samples.steals', 1)
        ->assertJsonPath('data.0.complete_box_score_games', 1);
});

it('excludes unknown minutes from their observed denominator and from a complete per36 claim', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'minutes_played' => '12:00'], [...basketballObservedZeros(), 'minutes_played' => null]]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.minutes_per_game', 12)
        ->assertJsonPath('data.0.minutes_samples', 1)
        ->assertJsonPath('data.0.estimated_epa_per_game', 0)
        ->assertJsonPath('data.0.estimated_epa_per_36', null);
});

it('preserves complete observed zero at the actual endpoint', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'minutes_played' => '12:00']]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.points_per_game', 0)
        ->assertJsonPath('data.0.estimated_epa_per_game', 0)
        ->assertJsonPath('data.0.estimated_epa_per_36', 0);
});

it('computes shooting percentages only from observed made-attempt pairs', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'field_goals_made' => 1, 'field_goals_attempted' => null, 'minutes_played' => '12:00'], [...basketballObservedZeros(), 'field_goals_made' => null, 'field_goals_attempted' => 2, 'minutes_played' => '12:00']]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.field_goal_percentage', null)
        ->assertJsonPath('data.0.shooting_samples.field_goals', 0);
});

it('read-only observed adapters decline invalid minutes and incomplete or contradictory counts', function () {
    $calculator = app(NbaPlayerEpaCalculator::class);
    foreach ([null, '', 'DNP', '12:75', '12:xx', -1, INF, false, true, []] as $minutes) {
        expect($calculator->observedMinutes($minutes))->toBeNull()
            ->and($calculator->estimateObservedPer36(1, $minutes))->toBeNull();
    }
    expect($calculator->observedMinutes('12:30'))->toBe(12.5)
        ->and($calculator->observedMinutes('0:00'))->toBe(0.0)
        ->and($calculator->estimateObservedPer36(0, '0:00'))->toBeNull()
        ->and($calculator->estimateFromObservedBoxScore([...basketballObservedZeros(), 'assists' => false]))->toBeNull()
        ->and($calculator->estimateFromObservedBoxScore([...basketballObservedZeros(), 'points' => INF]))->toBeNull()
        ->and($calculator->estimateFromObservedBoxScore([...basketballObservedZeros(), 'field_goals_made' => 1, 'field_goals_attempted' => 0]))->toBeNull();
});

it('preserves legacy estimator behavior and complete observed arithmetic without retuning', function () {
    $calculator = app(NbaPlayerEpaCalculator::class);
    $fields = [...basketballObservedZeros(), 'points' => 20, 'assists' => 4, 'rebounds_total' => 6, 'steals' => 1, 'blocks' => 1, 'turnovers' => 2, 'field_goals_made' => 7, 'field_goals_attempted' => 14, 'free_throws_made' => 4, 'free_throws_attempted' => 4];
    expect($calculator->estimateFromObservedBoxScore($fields))->toBe(15.04)
        ->and($calculator->estimateFromBoxScore(...array_fill(0, 10, null)))->toBe(0.0)
        ->and($calculator->estimatePer36(1, null))->toBe(0.0)
        ->and($calculator->estimateObservedPer36(15.04, '32:00'))->toBe(16.92);
});

it('does not reuse cached basketball rows from before observation completeness rules', function () {
    $player = basketballObservationRows([['minutes_played' => null]]);
    $cache = app(SportsViewCache::class);
    $key = $cache->contextHash(['sport' => 'nba', 'filters' => ['season' => 2026, 'min_games' => 1]]);
    $old = [['player_id' => $player->id, 'games_played' => 1, 'points_per_game' => 0, 'estimated_epa_per_game' => 0]];
    $cache->remember(SportsViewCache::SEGMENT_PLAYER_LEADERBOARDS, $key, 300, fn () => $old);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.points_per_game', null)
        ->assertJsonPath('data.0.field_samples.points', 0);
    expect($cache->remember(SportsViewCache::SEGMENT_PLAYER_LEADERBOARDS, $key, 300, fn () => []))->toBe($old);
});

it('preserves the existing cache key contract for nonbasketball leaderboard rows', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $cache = app(SportsViewCache::class);
    $key = $cache->contextHash(['sport' => 'nfl', 'filters' => ['season' => 2026, 'min_games' => 1]]);
    $cache->remember(SportsViewCache::SEGMENT_PLAYER_LEADERBOARDS, $key, 300, fn () => [['player_id' => 7, 'games_played' => 1, 'synthetic_cache_control' => true]]);
    $this->mock(SportPlayerLeaderboardQuery::class)->shouldNotReceive('get');
    $this->getJson('/api/v2/sports/nfl/leaderboards/players?season=2026&min_games=1')->assertOk()->assertJsonPath('data.0.synthetic_cache_control', true);
});

it('declines contradictory shooting count pairs instead of reporting a rate above one hundred percent', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'field_goals_made' => 5, 'field_goals_attempted' => 1, 'minutes_played' => '12:00']]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.field_goal_percentage', null)
        ->assertJsonPath('data.0.shooting_samples.field_goals', 0)
        ->assertJsonPath('data.0.estimated_epa_per_game', null);
});

it('keeps the zero-attempt shooting rate unavailable while preserving raw zero observations', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'minutes_played' => '12:00']]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.field_goals_made_per_game', 0)
        ->assertJsonPath('data.0.field_goals_attempted_per_game', 0)
        ->assertJsonPath('data.0.shooting_samples.field_goals', 1)
        ->assertJsonPath('data.0.field_goal_percentage', null);
});

it('retains a genuine zero shooting rate with positive observed attempts', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'field_goals_attempted' => 4, 'minutes_played' => '12:00']]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()
        ->assertJsonPath('data.0.field_goal_percentage', 0)
        ->assertJsonPath('data.0.shooting_samples.field_goals', 1);
});

it('honors current leaderboard capability even when the prior enabled result is cached', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'minutes_played' => '12:00']]);
    $url = '/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1';
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.points_per_game', 0);
    config(['sports.domains.nba.capabilities.player_stats_leaderboard' => false]);
    $this->getJson($url)->assertNotFound();
});

it('does not select basketball rows for unconfigured integer phase spellings', function (string $phase) {
    basketballObservationRows([[...basketballObservedZeros(), 'minutes_played' => '12:00']]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1&season_type='.$phase)
        ->assertOk()->assertJsonCount(0, 'data');
})->with(['0', '2e0']);

it('keeps omitted basketball phase distinct from an explicit empty candidate set', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'minutes_played' => '12:00']]);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1')->assertOk()->assertJsonCount(1, 'data');
    $rows = app(BasketballLeaderboardService::class)->execute(PlayerStat::class, Player::class, 1, Game::class, 2026, []);
    expect($rows)->toBeEmpty();
});

it('does not reuse pre-phase-policy basketball cache entries', function () {
    basketballObservationRows([[...basketballObservedZeros(), 'minutes_played' => '12:00']]);
    $cache = app(SportsViewCache::class);
    $key = $cache->contextHash(['sport' => 'nba', 'filters' => ['season' => 2026, 'min_games' => 1, 'season_type' => '2e0'], 'observation_policy' => BasketballLeaderboardService::OBSERVATION_POLICY]);
    $old = [['player_id' => 999, 'synthetic_pre_phase_policy' => true]];
    $cache->remember(SportsViewCache::SEGMENT_PLAYER_LEADERBOARDS, $key, 300, fn () => $old);
    $this->getJson('/api/v2/sports/nba/leaderboards/players?season=2026&min_games=1&season_type=2e0')->assertOk()->assertJsonCount(0, 'data');
    expect($cache->remember(SportsViewCache::SEGMENT_PLAYER_LEADERBOARDS, $key, 300, fn () => []))->toBe($old);
});
