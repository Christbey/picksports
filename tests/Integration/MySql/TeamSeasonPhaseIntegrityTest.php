<?php

namespace Tests\Integration\MySql;

use App\Services\Api\V2\Concerns\BuildsSportQueries;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Api\V2\SportTeamSeasonAverageQuery;
use App\Services\TeamStats\BasketballTeamSeasonAveragesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamSeasonPhaseIntegrityTest extends TestCase
{
    public function test_integer_phase_aliases_do_not_admit_zero_and_football_keeps_string_aliases(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        Http::preventStrayRequests();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $probe = new class
        {
            use BuildsSportQueries;

            public function legacy(SportContext $context): array
            {
                return $this->seasonTypeCandidates($context, 'regular');
            }
        };
        foreach (['nba', 'cbb', 'wcbb', 'wnba', 'mlb'] as $sport) {
            $namespace = strtoupper($sport);
            $teamClass = "App\\Models\\{$namespace}\\Team";
            $gameClass = "App\\Models\\{$namespace}\\Game";
            $statClass = "App\\Models\\{$namespace}\\TeamStat";
            $team = $teamClass::factory()->create();
            $ids = [];
            foreach ([0, 1, 2] as $phase) {
                $game = $gameClass::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $teamClass::factory(),
                    'season' => 2025, 'season_type' => $phase, 'status' => 'STATUS_FINAL']);
                $ids[$phase] = $game->id;
                $statClass::query()->create(['game_id' => $game->id, 'team_id' => $team->id, 'team_type' => 'home',
                    ...($sport === 'mlb' ? ['runs' => $phase === 2 ? 0 : 99] : ['points' => $phase === 2 ? 0 : 99])]);
            }
            $context = app(SportContextResolver::class)->resolve($sport);
            // This engine preserves phase-zero isolation for the old prepared comparison too.
            $this->assertSame([$ids[2]], $gameClass::query()->whereIn('season_type', $probe->legacy($context))->pluck('id')->all());
            $query = app(SportGameQuery::class);
            foreach (array_unique(['regular', '2', ...array_values(array_intersect_key((array) config("{$sport}.season.type_names", []), ['regular' => 2]))]) as $alias) {
                $selected = $query->query($context, ['season' => 2025, 'season_type' => $alias]);
                $this->assertSame([$ids[2]], $selected->pluck($gameClass::make()->getTable().'.id')->all());
                $this->assertNotContains('regular', $selected->getBindings());
            }
            foreach (['0', '2e0'] as $unknown) {
                $this->assertSame(0, $query->query($context, ['season' => 2025, 'season_type' => $unknown])->count());
            }
            $this->assertSame(3, $query->query($context, ['season' => 2025, 'season_type' => 'all'])->count());
            $averages = app(SportTeamSeasonAverageQuery::class)->teamWithScope($context,
                app(BasketballTeamSeasonAveragesService::class), $team->id, ['season' => 2025, 'season_type' => 'regular']);
            $this->assertSame(1, $averages['data']['games_played']);
            $this->assertEquals(0, $averages['data'][$sport === 'mlb' ? 'runs_per_game' : 'points_per_game']);
        }
        foreach (['nfl', 'cfb'] as $sport) {
            $namespace = strtoupper($sport);
            $gameClass = "App\\Models\\{$namespace}\\Game";
            $teamClass = "App\\Models\\{$namespace}\\Team";
            $ids = [];
            foreach (['regular', '2', 'preseason'] as $phase) {
                $ids[$phase] = $gameClass::factory()->create(['home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
                    'season' => 2025, 'season_type' => $phase, 'status' => 'STATUS_FINAL'])->id;
            }
            $selected = app(SportGameQuery::class)->query(app(SportContextResolver::class)->resolve($sport), ['season' => 2025, 'season_type' => 'regular']);
            $this->assertEqualsCanonicalizing([$ids['regular'], $ids['2']], $selected->pluck($gameClass::make()->getTable().'.id')->all());
        }
    }
}
