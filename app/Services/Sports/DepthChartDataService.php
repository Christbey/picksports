<?php

namespace App\Services\Sports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepthChartDataService
{
    /**
     * @param  class-string<Model>  $teamModel
     * @param  class-string<Model>  $depthChartEntryModel
     * @param  class-string<Model>  $playerStatModel
     * @param  class-string<Model>  $gameModel
     * @return array<string, mixed>
     */
    public function forTeam(
        string $sport,
        string $teamModel,
        string $depthChartEntryModel,
        string $playerStatModel,
        string $gameModel,
        int $teamId,
        ?int $season = null,
        mixed $seasonType = null,
        ?string $beforeDate = null,
    ): array {
        /** @var Model $team */
        $team = $teamModel::query()->findOrFail($teamId);
        $resolvedSeason = $season ?: (int) now()->year;

        return $this->buildTeamPayload(
            sport: $sport,
            teamModelInstance: $team,
            depthChartEntryModel: $depthChartEntryModel,
            playerStatModel: $playerStatModel,
            gameModel: $gameModel,
            season: $resolvedSeason,
            seasonType: $seasonType,
            beforeDate: $beforeDate,
        );
    }

    /**
     * @param  class-string<Model>  $gameModel
     * @param  class-string<Model>  $depthChartEntryModel
     * @param  class-string<Model>  $playerStatModel
     * @return array<string, mixed>
     */
    public function forGame(
        string $sport,
        string $gameModel,
        string $depthChartEntryModel,
        string $playerStatModel,
        int $gameId,
    ): array {
        /** @var Model $game */
        $game = $gameModel::query()
            ->with(['homeTeam', 'awayTeam'])
            ->findOrFail($gameId);

        $season = (int) ($game->season ?? now()->year);
        $seasonType = $game->season_type ?? null;
        $beforeDate = $game->game_date?->toDateString();

        return [
            'game_id' => (int) $game->getKey(),
            'season' => $season,
            'season_type' => $seasonType,
            'game_date' => $beforeDate,
            'away_team' => $this->buildTeamPayload(
                sport: $sport,
                teamModelInstance: $game->awayTeam,
                depthChartEntryModel: $depthChartEntryModel,
                playerStatModel: $playerStatModel,
                gameModel: $gameModel,
                season: $season,
                seasonType: $seasonType,
                beforeDate: $beforeDate,
                gameContext: true,
            ),
            'home_team' => $this->buildTeamPayload(
                sport: $sport,
                teamModelInstance: $game->homeTeam,
                depthChartEntryModel: $depthChartEntryModel,
                playerStatModel: $playerStatModel,
                gameModel: $gameModel,
                season: $season,
                seasonType: $seasonType,
                beforeDate: $beforeDate,
                gameContext: true,
            ),
        ];
    }

    /**
     * @param  class-string<Model>  $depthChartEntryModel
     * @param  class-string<Model>  $playerStatModel
     * @param  class-string<Model>  $gameModel
     * @return array<string, mixed>
     */
    protected function buildTeamPayload(
        string $sport,
        ?Model $teamModelInstance,
        string $depthChartEntryModel,
        string $playerStatModel,
        string $gameModel,
        int $season,
        mixed $seasonType,
        ?string $beforeDate,
        bool $gameContext = false,
    ): array {
        if (! $teamModelInstance) {
            return [
                'team' => null,
                'season' => $season,
                'season_type' => $seasonType,
                'before_date' => $beforeDate,
                'entries' => [],
            ];
        }

        $entries = $depthChartEntryModel::query()
            ->with('player')
            ->where('team_id', $teamModelInstance->getKey())
            ->where('season', $season)
            ->orderBy('position_slot_key')
            ->orderBy('depth_rank')
            ->get();

        if ($gameContext) {
            $entries = $this->filterGameEntries($entries);
        }

        $statsByPlayerId = $this->loadAggregatedStats(
            sport: $sport,
            playerStatModel: $playerStatModel,
            gameModel: $gameModel,
            teamId: (int) $teamModelInstance->getKey(),
            playerIds: $entries->pluck('player_id')->filter()->map(fn ($id) => (int) $id)->all(),
            season: $season,
            seasonType: $seasonType,
            beforeDate: $beforeDate,
        );

        return [
            'team' => [
                'id' => (int) $teamModelInstance->getKey(),
                'espn_id' => $teamModelInstance->espn_id,
                'abbreviation' => $teamModelInstance->abbreviation,
                'display_name' => $teamModelInstance->display_name ?? trim((string) (($teamModelInstance->location ?? '').' '.($teamModelInstance->name ?? ''))),
                'logo' => $teamModelInstance->logo_url ?? null,
            ],
            'season' => $season,
            'season_type' => $seasonType,
            'before_date' => $beforeDate,
            'entries' => $entries->map(fn ($entry) => $this->serializeEntry($sport, $entry, $statsByPlayerId))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Model>  $entries
     * @return Collection<int, Model>
     */
    protected function filterGameEntries(Collection $entries): Collection
    {
        return $entries
            ->filter(fn (Model $entry): bool => (bool) $entry->is_starter)
            ->unique(function (Model $entry): string {
                if ($entry->player_id) {
                    return 'player:'.$entry->player_id;
                }

                $espnAthleteId = trim((string) ($entry->espn_athlete_id ?? ''));

                return $espnAthleteId !== ''
                    ? 'espn:'.$espnAthleteId
                    : 'entry:'.$entry->getKey();
            })
            ->values();
    }

    /**
     * @return array<int, string>
     */
    protected function seasonTypeVariants(mixed $seasonType): array
    {
        if ($seasonType === null) {
            return [];
        }

        $value = trim((string) $seasonType);

        if ($value === '') {
            return [];
        }

        $variants = [$value];
        $normalized = strtolower($value);

        if (ctype_digit($value)) {
            $variants = [...$variants, ...match ((int) $value) {
                1 => ['Preseason', 'Pre Season'],
                2 => ['Regular Season', 'Regular'],
                3 => ['Postseason', 'Post Season', 'Playoffs'],
                default => [],
            }];
        } else {
            $variants = [...$variants, ...match ($normalized) {
                'preseason', 'pre season' => ['1'],
                'regular season', 'regular' => ['2'],
                'postseason', 'post season', 'playoffs' => ['3'],
                default => [],
            }];
        }

        return array_values(array_unique($variants));
    }

    /**
     * @param  class-string<Model>  $playerStatModel
     * @param  class-string<Model>  $gameModel
     * @param  list<int>  $playerIds
     * @return array<int, array<string, mixed>>
     */
    protected function loadAggregatedStats(
        string $sport,
        string $playerStatModel,
        string $gameModel,
        int $teamId,
        array $playerIds,
        int $season,
        mixed $seasonType,
        ?string $beforeDate,
    ): array {
        if ($playerIds === []) {
            return [];
        }

        $query = $playerStatModel::query()
            ->select('player_id')
            ->selectRaw('COUNT(DISTINCT game_id) as games_played')
            ->where('team_id', $teamId)
            ->whereIn('player_id', $playerIds)
            ->whereHas('game', function ($gameQuery) use ($season, $seasonType, $beforeDate): void {
                $gameQuery->where('season', $season)
                    ->where('status', 'STATUS_FINAL');

                $seasonTypes = $this->seasonTypeVariants($seasonType);

                if ($seasonTypes !== []) {
                    $gameQuery->whereIn('season_type', $seasonTypes);
                }

                if ($beforeDate) {
                    $gameQuery->whereDate('game_date', '<', $beforeDate);
                }
            })
            ->groupBy('player_id');

        foreach ($this->sumColumnsForSport($sport) as $column) {
            $query->selectRaw("COALESCE(SUM({$column}), 0) as {$column}");
        }

        /** @var Collection<int, Model> $rows */
        $rows = $query->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->player_id] = $row->toArray();
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    protected function sumColumnsForSport(string $sport): array
    {
        return match ($sport) {
            'nfl' => [
                'passing_yards',
                'passing_touchdowns',
                'interceptions_thrown',
                'rushing_yards',
                'rushing_touchdowns',
                'receptions',
                'receiving_yards',
                'receiving_touchdowns',
                'tackles_total',
                'sacks',
                'interceptions',
            ],
            'nba' => [
                'points',
                'rebounds_total',
                'assists',
                'steals',
                'blocks',
            ],
            'mlb' => [
                'at_bats',
                'hits',
                'doubles',
                'triples',
                'home_runs',
                'rbis',
                'walks',
                'strikeouts',
                'innings_pitched',
                'earned_runs',
                'walks_allowed',
                'hits_allowed',
                'strikeouts_pitched',
            ],
            default => [],
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $statsByPlayerId
     * @return array<string, mixed>
     */
    protected function serializeEntry(string $sport, Model $entry, array $statsByPlayerId): array
    {
        $player = $entry->player;
        $stats = $entry->player_id ? ($statsByPlayerId[(int) $entry->player_id] ?? null) : null;

        return [
            'id' => (int) $entry->getKey(),
            'player_id' => $entry->player_id ? (int) $entry->player_id : null,
            'espn_athlete_id' => $entry->espn_athlete_id,
            'full_name' => $player?->full_name,
            'jersey_number' => $player?->jersey_number,
            'headshot_url' => $player?->headshot_url ?? $player?->headshot ?? null,
            'player_position' => $player?->position,
            'position_slot_key' => $entry->position_slot_key,
            'position_code' => $entry->position_code,
            'position_name' => $entry->position_display_name ?: $entry->position_name,
            'depth_chart_name' => $entry->depth_chart_name,
            'slot_order' => $entry->slot_order,
            'depth_rank' => (int) $entry->depth_rank,
            'is_starter' => (bool) $entry->is_starter,
            'stats' => [
                'games_played' => $stats ? (int) ($stats['games_played'] ?? 0) : 0,
                'metrics' => $this->formatMetrics($sport, $entry, $stats),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stats
     * @return list<array{key:string,label:string,value:string}>
     */
    protected function formatMetrics(string $sport, Model $entry, ?array $stats): array
    {
        if (! $stats) {
            return [];
        }

        return match ($sport) {
            'nfl' => $this->formatNflMetrics((string) ($entry->position_code ?? ''), $stats),
            'nba' => $this->formatNbaMetrics($stats),
            'mlb' => $this->formatMlbMetrics((string) ($entry->position_code ?? ''), $stats),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return list<array{key:string,label:string,value:string}>
     */
    protected function formatNflMetrics(string $positionCode, array $stats): array
    {
        $code = strtoupper(trim($positionCode));
        $games = max(1, (int) ($stats['games_played'] ?? 0));

        if ($code === 'QB') {
            return [
                ['key' => 'pass_yards', 'label' => 'Pass Yds', 'value' => (string) (int) ($stats['passing_yards'] ?? 0)],
                ['key' => 'pass_td', 'label' => 'Pass TD', 'value' => (string) (int) ($stats['passing_touchdowns'] ?? 0)],
                ['key' => 'ints', 'label' => 'INT', 'value' => (string) (int) ($stats['interceptions_thrown'] ?? 0)],
                ['key' => 'rush_yards', 'label' => 'Rush Yds', 'value' => (string) (int) ($stats['rushing_yards'] ?? 0)],
            ];
        }

        if (in_array($code, ['RB', 'HB', 'FB'], true)) {
            return [
                ['key' => 'rush_yards', 'label' => 'Rush Yds', 'value' => (string) (int) ($stats['rushing_yards'] ?? 0)],
                ['key' => 'rush_td', 'label' => 'Rush TD', 'value' => (string) (int) ($stats['rushing_touchdowns'] ?? 0)],
                ['key' => 'receptions', 'label' => 'Rec', 'value' => (string) (int) ($stats['receptions'] ?? 0)],
                ['key' => 'receiving_yards', 'label' => 'Rec Yds', 'value' => (string) (int) ($stats['receiving_yards'] ?? 0)],
            ];
        }

        if (in_array($code, ['WR', 'TE'], true)) {
            return [
                ['key' => 'receptions', 'label' => 'Rec', 'value' => (string) (int) ($stats['receptions'] ?? 0)],
                ['key' => 'receiving_yards', 'label' => 'Rec Yds', 'value' => (string) (int) ($stats['receiving_yards'] ?? 0)],
                ['key' => 'receiving_td', 'label' => 'Rec TD', 'value' => (string) (int) ($stats['receiving_touchdowns'] ?? 0)],
                ['key' => 'yards_per_game', 'label' => 'Yds/G', 'value' => number_format(((float) ($stats['receiving_yards'] ?? 0)) / $games, 1)],
            ];
        }

        return [
            ['key' => 'tackles', 'label' => 'Tackles', 'value' => (string) (int) ($stats['tackles_total'] ?? 0)],
            ['key' => 'sacks', 'label' => 'Sacks', 'value' => number_format((float) ($stats['sacks'] ?? 0), 1)],
            ['key' => 'ints', 'label' => 'INT', 'value' => (string) (int) ($stats['interceptions'] ?? 0)],
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return list<array{key:string,label:string,value:string}>
     */
    protected function formatNbaMetrics(array $stats): array
    {
        $games = max(1, (int) ($stats['games_played'] ?? 0));

        return [
            ['key' => 'ppg', 'label' => 'PPG', 'value' => number_format(((float) ($stats['points'] ?? 0)) / $games, 1)],
            ['key' => 'rpg', 'label' => 'RPG', 'value' => number_format(((float) ($stats['rebounds_total'] ?? 0)) / $games, 1)],
            ['key' => 'apg', 'label' => 'APG', 'value' => number_format(((float) ($stats['assists'] ?? 0)) / $games, 1)],
            ['key' => 'spg', 'label' => 'SPG', 'value' => number_format(((float) ($stats['steals'] ?? 0)) / $games, 1)],
            ['key' => 'bpg', 'label' => 'BPG', 'value' => number_format(((float) ($stats['blocks'] ?? 0)) / $games, 1)],
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return list<array{key:string,label:string,value:string}>
     */
    protected function formatMlbMetrics(string $positionCode, array $stats): array
    {
        $code = strtoupper(trim($positionCode));

        if (str_contains($code, 'P')) {
            $innings = (float) ($stats['innings_pitched'] ?? 0);
            $earnedRuns = (float) ($stats['earned_runs'] ?? 0);
            $era = $innings > 0 ? ($earnedRuns * 9) / $innings : null;

            return [
                ['key' => 'ip', 'label' => 'IP', 'value' => number_format($innings, 1)],
                ['key' => 'era', 'label' => 'ERA', 'value' => $era === null ? '-' : number_format($era, 2)],
                ['key' => 'k', 'label' => 'K', 'value' => (string) (int) ($stats['strikeouts_pitched'] ?? 0)],
                ['key' => 'whip', 'label' => 'WHIP', 'value' => $innings > 0 ? number_format((((float) ($stats['walks_allowed'] ?? 0)) + ((float) ($stats['hits_allowed'] ?? 0))) / $innings, 2) : '-'],
            ];
        }

        $atBats = (float) ($stats['at_bats'] ?? 0);
        $hits = (float) ($stats['hits'] ?? 0);
        $walks = (float) ($stats['walks'] ?? 0);
        $doubles = (float) ($stats['doubles'] ?? 0);
        $triples = (float) ($stats['triples'] ?? 0);
        $homeRuns = (float) ($stats['home_runs'] ?? 0);
        $singles = max(0.0, $hits - $doubles - $triples - $homeRuns);
        $totalBases = $singles + ($doubles * 2) + ($triples * 3) + ($homeRuns * 4);
        $avg = $atBats > 0 ? $hits / $atBats : null;
        $obp = ($atBats + $walks) > 0 ? ($hits + $walks) / ($atBats + $walks) : null;
        $slg = $atBats > 0 ? $totalBases / $atBats : null;
        $ops = ($obp !== null && $slg !== null) ? $obp + $slg : null;

        return [
            ['key' => 'avg', 'label' => 'AVG', 'value' => $avg === null ? '-' : number_format($avg, 3)],
            ['key' => 'ops', 'label' => 'OPS', 'value' => $ops === null ? '-' : number_format($ops, 3)],
            ['key' => 'hr', 'label' => 'HR', 'value' => (string) (int) $homeRuns],
            ['key' => 'rbi', 'label' => 'RBI', 'value' => (string) (int) ($stats['rbis'] ?? 0)],
        ];
    }
}
