<?php

namespace Tests\Integration\MySql;

use App\Services\Api\V2\Concerns\BuildsSportQueries;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPlayerLeaderboardQuery;
use App\Services\PlayerStats\BasketballLeaderboardService;
use App\Services\PlayerStats\NbaPlayerEpaCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BasketballLeaderboardPhaseIntegrityTest extends TestCase
{
    public function test_integer_phase_admission_preserves_configured_codes_and_distinguishes_omission_from_unknown(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        Http::preventStrayRequests();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $legacy = new class
        {
            use BuildsSportQueries;

            public function candidates(SportContext $context, string $value): array
            {
                return $this->seasonTypeCandidates($context, $value);
            }
        };
        $failures = [];
        $record = ['engine_version' => DB::selectOne('SELECT VERSION() AS version')->version, 'sports' => []];
        foreach (['nba', 'cbb', 'wnba'] as $sport) {
            $namespace = strtoupper($sport);
            $teamModel = "App\\Models\\{$namespace}\\Team";
            $gameModel = "App\\Models\\{$namespace}\\Game";
            $playerModel = "App\\Models\\{$namespace}\\Player";
            $statModel = "App\\Models\\{$namespace}\\PlayerStat";
            $types = (array) config("{$sport}.season.types");
            $names = (array) config("{$sport}.season.type_names");
            $regular = $types['regular'];
            $this->assertIsInt($regular);
            $outside = array_values(array_diff(range(0, 255), array_values($types)))[0];
            $this->assertNotContains($outside, array_values($types));
            $other = array_values(array_diff(array_values($types), [$regular]))[0] ?? null;
            $phases = array_values(array_unique(array_filter([$outside, $regular, $other], fn ($value) => $value !== null)));
            $team = $teamModel::factory()->create();
            $opponent = $teamModel::factory()->create();
            $columns = Schema::getColumnListing((new $playerModel)->getTable());
            $player = Model::unguarded(fn () => $playerModel::query()->create(array_intersect_key([
                'espn_id' => "phase-probe-{$sport}", 'team_id' => $team->id, 'first_name' => 'Synthetic', 'last_name' => 'Player', 'full_name' => 'Synthetic Phase Player', 'position' => 'G',
            ], array_flip($columns))));
            $ids = [];
            foreach ($phases as $phase) {
                $game = $gameModel::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opponent->id, 'season' => 2026, 'season_type' => $phase, 'status' => 'STATUS_FINAL']);
                $this->assertSame($phase, (int) $game->fresh()->getRawOriginal('season_type'));
                $ids[$phase] = $game->id;
                $statModel::query()->create(['game_id' => $game->id, 'team_id' => $team->id, 'player_id' => $player->id,
                    ...array_fill_keys(NbaPlayerEpaCalculator::BOX_SCORE_FIELDS, 0), 'points' => $phase === $regular ? 0 : 900, 'minutes_played' => '12:00']);
            }
            $context = app(SportContextResolver::class)->resolve($sport);
            $legacyCandidates = $legacy->candidates($context, 'regular');
            $legacyIds = $gameModel::query()->where('season', 2026)->whereIn('season_type', $legacyCandidates)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->assertContains($ids[$regular], $legacyIds);
            $details = ['regular_code' => $regular, 'outside_code' => $outside, 'zero_is_configured' => in_array(0, $types, true), 'legacy_candidates' => $legacyCandidates, 'legacy_selected_game_ids' => $legacyIds, 'legacy_selected_outside' => in_array($ids[$outside], $legacyIds, true), 'bindings' => []];
            foreach (array_unique(array_filter(['regular', (string) $regular, $names['regular'] ?? null], fn ($value) => $value !== null && $value !== '')) as $alias) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $rows = app(SportPlayerLeaderboardQuery::class)->get($context, ['season' => 2026, 'season_type' => $alias, 'min_games' => 1]);
                $logs = DB::getQueryLog();
                DB::disableQueryLog();
                if ($rows->count() !== 1 || $rows->first()['games_played'] !== 1 || (float) $rows->first()['points_per_game'] !== 0.0) {
                    $failures[] = "{$sport}: configured alias {$alias} selected outside its game scope";
                }
                foreach ($logs as $log) {
                    if (str_contains($log['query'], 'season_type') && str_contains($log['query'], (new $statModel)->getTable())) {
                        $details['bindings'][] = ['alias' => $alias, 'values' => $log['bindings'], 'types' => array_map('get_debug_type', $log['bindings'])];
                        if (count(array_filter($log['bindings'], fn ($value) => ! is_int($value))) > 0) {
                            $failures[] = "{$sport}: configured integer phase query retained noninteger bindings";
                        }
                    }
                }
            }
            $configuredAliases = array_map('strval', [...array_keys($types), ...array_values($types), ...array_values($names)]);
            $numericAlias = array_values(array_filter(["{$regular}e0", "+{$regular}", "{$regular}.0"], fn ($value) => ! in_array($value, $configuredAliases, true)))[0];
            foreach ([(string) $outside, $numericAlias, '__unmapped_phase_probe__'] as $unknown) {
                $this->assertNotContains($unknown, $configuredAliases);
                $rows = app(SportPlayerLeaderboardQuery::class)->get($context, ['season' => 2026, 'season_type' => $unknown, 'min_games' => 1]);
                $details['unknown_results'][$unknown] = $rows->map(fn ($row) => ['games' => $row['games_played'], 'points_per_game' => $row['points_per_game']])->all();
                if ($rows->isNotEmpty()) {
                    $failures[] = "{$sport}: unsupported phase {$unknown} admitted stored rows";
                }
            }
            $all = app(SportPlayerLeaderboardQuery::class)->get($context, ['season' => 2026, 'min_games' => 1]);
            $this->assertSame(count($phases), $all->sole()['games_played']);
            $none = app(BasketballLeaderboardService::class)->execute($statModel, $playerModel, 1, $gameModel, 2026, []);
            $details['explicit_empty_results'] = $none->count();
            if ($none->isNotEmpty()) {
                $failures[] = "{$sport}: explicit empty phase candidates broadened to all history";
            }
            $record['sports'][$sport] = $details;
        }
        foreach (['nfl', 'cfb'] as $sport) {
            $teamModel = 'App\\Models\\'.strtoupper($sport).'\\Team';
            $gameModel = 'App\\Models\\'.strtoupper($sport).'\\Game';
            $ids = [];
            foreach (['regular', '2', 'preseason'] as $phase) {
                $ids[$phase] = $gameModel::factory()->create(['home_team_id' => $teamModel::factory(), 'away_team_id' => $teamModel::factory(), 'season' => 2026, 'season_type' => $phase, 'status' => 'STATUS_FINAL'])->id;
            }
            $candidates = $legacy->candidates(app(SportContextResolver::class)->resolve($sport), 'regular');
            $this->assertEqualsCanonicalizing([$ids['regular'], $ids['2']], $gameModel::query()->where('season', 2026)->whereIn('season_type', $candidates)->pluck('id')->all());
            $record['varchar_controls'][$sport] = ['candidates' => $candidates, 'matched_regular_and_numeric_alias' => true];
        }
        $record['violations'] = array_values(array_unique($failures));
        fwrite(STDOUT, 'BASKETBALL_PHASE_CHARACTERIZATION '.json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL);
        $this->assertSame([], $record['violations']);
    }
}
