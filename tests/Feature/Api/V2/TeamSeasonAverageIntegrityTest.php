<?php

use App\Models\MLB\Game;
use App\Models\MLB\TeamStat;
use App\Models\NBA\Team;
use App\Models\User;
use App\Services\Api\V2\Concerns\BuildsSportQueries;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportTeamSeasonAverageQuery;
use App\Services\TeamStats\BasketballTeamSeasonAveragesService;
use App\Services\TeamStats\CompletedTeamStatScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

function seasonAverageSample(string $sport, object $team, int $season, int $phase, string $status, array $stats): void
{
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $statClass = "App\\Models\\{$namespace}\\TeamStat";
    $game = $gameClass::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $teamClass::factory(),
        'season' => $season, 'season_type' => $phase, 'status' => $status]);
    $statClass::query()->create(['team_id' => $team->id, 'game_id' => $game->id, 'team_type' => 'home', ...$stats]);
}

beforeEach(function () {
    $user = User::factory()->create();
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
});

it('keeps explicit basketball season averages on completed selected-phase samples', function (string $sport) {
    $teamClass = 'App\\Models\\'.strtoupper($sport).'\\Team';
    $team = $teamClass::factory()->create();
    seasonAverageSample($sport, $team, 2024, 2, 'STATUS_FINAL', ['points' => 100]);
    seasonAverageSample($sport, $team, 2025, 2, 'STATUS_FINAL', ['points' => 0]);
    seasonAverageSample($sport, $team, 2025, 2, 'STATUS_FINAL', ['points' => 20]);
    seasonAverageSample($sport, $team, 2025, 1, 'STATUS_FINAL', ['points' => 90]);
    seasonAverageSample($sport, $team, 2025, 2, 'STATUS_IN_PROGRESS', ['points' => 500]);
    $this->getJson("/api/v2/sports/{$sport}/teams/{$team->id}/stats/season-averages?season=2025&season_type=regular")
        ->assertOk()->assertJsonPath('data.points_per_game', 10)->assertJsonPath('data.games_played', 2)
        ->assertJsonPath('data.season', 2025)->assertJsonPath('meta.scope.season', 2025)
        ->assertJsonPath('meta.scope.selection', 'explicit')->assertJsonPath('data.sample_sizes.points_per_game', 2);
})->with(['nba', 'cbb', 'wcbb', 'wnba']);

it('labels the latest stored completed season instead of silently mixing or calling it current', function () {
    $team = Team::factory()->create();
    seasonAverageSample('nba', $team, 2024, 2, 'STATUS_FINAL', ['points' => 100]);
    seasonAverageSample('nba', $team, 2025, 2, 'STATUS_FINAL', ['points' => 0]);
    seasonAverageSample('nba', $team, 2026, 2, 'STATUS_SCHEDULED', ['points' => 999]);
    $this->getJson("/api/v2/sports/nba/teams/{$team->id}/stats/season-averages")
        ->assertOk()->assertJsonPath('data.points_per_game', 0)->assertJsonPath('data.games_played', 1)
        ->assertJsonPath('meta.scope.season', 2025)->assertJsonPath('meta.scope.selection', 'latest_stored_completed');
});

it('preserves missing field and paired percentage samples instead of turning unknown into zero', function () {
    $team = Team::factory()->create();
    seasonAverageSample('nba', $team, 2025, 2, 'STATUS_FINAL', ['points' => 0, 'field_goals_made' => null, 'field_goals_attempted' => 20]);
    seasonAverageSample('nba', $team, 2025, 2, 'STATUS_FINAL', ['points' => null, 'field_goals_made' => 5, 'field_goals_attempted' => null]);
    $this->getJson("/api/v2/sports/nba/teams/{$team->id}/stats/season-averages?season=2025")
        ->assertOk()->assertJsonPath('data.points_per_game', 0)->assertJsonPath('data.rebounds_per_game', null)
        ->assertJsonPath('data.field_goal_percentage', null)
        ->assertJsonPath('data.shooting_totals.field_goal_percentage.made', null)
        ->assertJsonPath('data.shooting_totals.field_goal_percentage.attempted', null)->assertJsonPath('data.sample_sizes.points_per_game', 1)
        ->assertJsonPath('data.sample_sizes.field_goal_percentage', 0)->assertJsonPath('data.games_played', 2);
});

it('does not widen an explicit empty season or phase to other completed history', function () {
    $team = Team::factory()->create();
    seasonAverageSample('nba', $team, 2025, 2, 'STATUS_FINAL', ['points' => 80]);
    $this->getJson('/api/v2/sports/nba/stats/team/season-averages?season=2024&season_type=2')
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.scope.season', 2024);
    $this->getJson("/api/v2/sports/nba/teams/{$team->id}/stats/season-averages?season=2025&season_type=preseason")
        ->assertNotFound()->assertJsonPath('meta.scope.season', 2025);
});

it('validates season-average scope before aggregation', function (array $query) {
    $this->getJson('/api/v2/sports/nba/stats/team/season-averages?'.http_build_query($query))->assertUnprocessable();
})->with([
    'invalid year' => [['season' => 'bad']], 'invalid phase' => [['season_type' => 'not-a-phase']],
    'nested year' => [['season' => ['2025']]], 'nested phase' => [['season_type' => ['regular']]],
]);

it('keeps MLB season averages on an explicit completed source season rather than the machine calendar year', function () {
    $team = App\Models\MLB\Team::factory()->create();
    seasonAverageSample('mlb', $team, 2024, 2, 'STATUS_FINAL', ['runs' => 0]);
    seasonAverageSample('mlb', $team, 2025, 2, 'STATUS_FINAL', ['runs' => 9]);
    $this->getJson("/api/v2/sports/mlb/teams/{$team->id}/stats/season-averages?season=2024&season_type=2")
        ->assertOk()->assertJsonPath('data.runs_per_game', 0)->assertJsonPath('data.games_played', 1)
        ->assertJsonPath('data.hits_per_game', null)->assertJsonPath('meta.scope.season', 2024);
});

class SelectedSeasonAverageMlbStat extends TeamStat
{
    protected $connection = 'season_average_selected';
}

it('uses the supplied stat model connection for MLB source selection and aggregation', function (string $prefix) {
    $team = App\Models\MLB\Team::factory()->create();
    seasonAverageSample('mlb', $team, (int) date('Y'), 2, 'STATUS_FINAL', ['runs' => 999]);
    config(['database.connections.season_average_selected' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => $prefix, 'foreign_key_constraints' => false]]);
    DB::purge('season_average_selected');
    try {
        Schema::connection('season_average_selected')->create('mlb_games', function (Blueprint $table) {
            $table->id();
            $table->integer('season');
            $table->string('season_type');
            $table->string('status');
            $table->integer('home_team_id');
            $table->integer('away_team_id');
        });
        Schema::connection('season_average_selected')->create('mlb_team_stats', function (Blueprint $table) {
            $table->id();
            $table->integer('game_id');
            $table->integer('team_id');
            foreach (['runs', 'hits', 'home_runs', 'rbis', 'walks', 'strikeouts', 'stolen_bases', 'batting_average', 'doubles', 'triples', 'errors', 'earned_runs', 'era'] as $field) {
                $table->double($field)->nullable();
            }
        });
        DB::connection('season_average_selected')->table('mlb_games')->insert(['id' => 1, 'season' => 2025, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'home_team_id' => 1, 'away_team_id' => 2]);
        DB::connection('season_average_selected')->table('mlb_team_stats')->insert(['game_id' => 1, 'team_id' => 1, 'runs' => 7]);
        $context = app(SportContextResolver::class)->resolve('mlb');
        $selected = new SportContext($context->slug, $context->label, $context->namespace,
            [...$context->models, 'team_stat' => SelectedSeasonAverageMlbStat::class], $context->resources, $context->capabilities, $context->web);
        $rows = app(SportTeamSeasonAverageQuery::class)->allAverages($selected, app(BasketballTeamSeasonAveragesService::class), ['season' => 2025, 'season_type' => '2']);
        expect(array_map(fn ($row) => (float) ((array) $row)['runs_per_game'], $rows))->toBe([7.0]);
    } finally {
        DB::purge('season_average_selected');
        config(['database.connections.season_average_selected' => null]);
    }
})->with(['plain tables' => '', 'configured table prefix' => 'picked_']);

it('uses only jointly observed shooting pairs and exposes their actual denominators', function () {
    $team = Team::factory()->create();
    seasonAverageSample('nba', $team, 2025, 2, 'STATUS_FULL_TIME', ['points' => 0, 'field_goals_made' => 2, 'field_goals_attempted' => 4]);
    seasonAverageSample('nba', $team, 2025, 2, 'STATUS_FINAL', ['points' => 0, 'field_goals_made' => 5, 'field_goals_attempted' => null]);
    $this->getJson("/api/v2/sports/nba/teams/{$team->id}/stats/season-averages?season=2025")
        ->assertOk()->assertJsonPath('data.field_goal_percentage', 50)
        ->assertJsonPath('data.sample_sizes.field_goal_percentage', 1)
        ->assertJsonPath('data.shooting_totals.field_goal_percentage.made', 2)
        ->assertJsonPath('data.shooting_totals.field_goal_percentage.attempted', 4)
        ->assertJsonPath('data.games_played', 2);
});

class ForeignSeasonAverageMlbGame extends Game
{
    protected $connection = 'season_average_foreign';
}

class SplitSeasonAverageMlbStat extends SelectedSeasonAverageMlbStat
{
    public function game(): BelongsTo
    {
        return $this->belongsTo(ForeignSeasonAverageMlbGame::class, 'game_id');
    }
}

it('refuses an explicitly split stat-game connection before any source query', function () {
    foreach (['season_average_selected', 'season_average_foreign'] as $name) {
        config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge($name);
    }
    $queries = [];
    DB::listen(function ($event) use (&$queries) {
        $queries[] = $event->sql;
    });
    try {
        expect(fn () => app(CompletedTeamStatScope::class)->resolve(SplitSeasonAverageMlbStat::class, ['season' => 2025]))
            ->toThrow(LogicException::class);
        expect($queries)->toBeEmpty();
    } finally {
        foreach (['season_average_selected', 'season_average_foreign'] as $name) {
            DB::purge($name);
            config(["database.connections.{$name}" => null]);
        }
    }
});

it('keeps team games on the configured phase alias or explicit all without guessing an unknown phase', function (string $phase, array $expectedPhases) {
    $team = Team::factory()->create();
    $ids = [];
    foreach ([0, 1, 2] as $code) {
        $game = App\Models\NBA\Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => Team::factory(),
            'season' => 2025, 'season_type' => $code, 'status' => 'STATUS_FINAL']);
        $ids[$code] = $game->id;
    }
    $response = $this->getJson("/api/v2/sports/nba/teams/{$team->id}/games?season=2025&season_type={$phase}")->assertOk();
    expect($response->json('data.*.id'))->toEqualCanonicalizing(array_map(fn ($code) => $ids[$code], $expectedPhases));
})->with([
    'configured alias' => ['regular', [2]], 'explicit all' => ['all', [0, 1, 2]],
    'numeric phase isolation' => ['1', [1]], 'unknown zero must not broaden' => ['0', []],
]);

it('binds only configured integer game phase codes without changing football alias support', function () {
    $probe = new class
    {
        use BuildsSportQueries;

        public function candidates(SportContext $context, string $phase): array
        {
            return $this->gameSeasonTypeCandidates($context, $phase);
        }
    };
    foreach (['nba', 'cbb', 'wcbb', 'wnba', 'mlb'] as $sport) {
        $context = app(SportContextResolver::class)->resolve($sport);
        expect($probe->candidates($context, 'regular'))->toBe([2])
            ->and($probe->candidates($context, '0'))->toBe([])
            ->and($probe->candidates($context, '2e0'))->toBe([]);
    }
    expect($probe->candidates(app(SportContextResolver::class)->resolve('nfl'), 'regular'))->toContain('regular', 2);
});
