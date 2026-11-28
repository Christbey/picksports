<?php

namespace App\Services\Sports;

use App\DataTransferObjects\ESPN\GameData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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

        return $this->forLoadedGame(
            sport: $sport,
            game: $game,
            depthChartEntryModel: $depthChartEntryModel,
            playerStatModel: $playerStatModel,
        );
    }

    /**
     * @param  class-string<Model>  $depthChartEntryModel
     * @param  class-string<Model>  $playerStatModel
     * @return array<string, mixed>
     */
    public function forLoadedGame(
        string $sport,
        Model $game,
        string $depthChartEntryModel,
        string $playerStatModel,
    ): array {
        $game->loadMissing(['homeTeam', 'awayTeam']);

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
                gameModel: $game::class,
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
                gameModel: $game::class,
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
                    ->whereIn('status', GameData::finalStatuses());

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
            $query->selectRaw("SUM({$column}) as {$column}, COUNT({$column}) as samples_{$column}");
        }

        if ($sport === 'mlb') {
            foreach (['era' => ['innings_pitched', 'earned_runs'], 'whip' => ['innings_pitched', 'walks_allowed', 'hits_allowed'],
                'avg' => ['at_bats', 'hits'], 'ops' => ['at_bats', 'hits', 'walks', 'doubles', 'triples', 'home_runs']] as $rate => $columns) {
                $known = implode(' AND ', array_map(fn (string $column): string => "{$column} IS NOT NULL", $columns));
                $query->selectRaw("COUNT(CASE WHEN {$known} THEN 1 END) as samples_{$rate}");
                foreach ($columns as $column) {
                    $query->selectRaw("SUM(CASE WHEN {$known} THEN {$column} END) as paired_{$rate}_{$column}");
                }
            }
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
     * @return list<array<string,mixed>>
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
     * @return list<array<string,mixed>>
     */
    protected function formatNflMetrics(string $positionCode, array $stats): array
    {
        $fields = match (strtoupper(trim($positionCode))) {
            'QB' => [['pass_yards', 'Pass Yds', 'passing_yards'], ['pass_td', 'Pass TD', 'passing_touchdowns'],
                ['ints', 'INT', 'interceptions_thrown'], ['rush_yards', 'Rush Yds', 'rushing_yards']],
            'RB', 'HB', 'FB' => [['rush_yards', 'Rush Yds', 'rushing_yards'], ['rush_td', 'Rush TD', 'rushing_touchdowns'],
                ['receptions', 'Rec', 'receptions'], ['receiving_yards', 'Rec Yds', 'receiving_yards']],
            'WR', 'TE' => [['receptions', 'Rec', 'receptions'], ['receiving_yards', 'Rec Yds', 'receiving_yards'],
                ['receiving_td', 'Rec TD', 'receiving_touchdowns']],
            default => [['tackles', 'Tackles', 'tackles_total'], ['sacks', 'Sacks', 'sacks', 1], ['ints', 'INT', 'interceptions']],
        };
        $metrics = array_map(fn (array $field): array => $this->metric($field[0], $field[1],
            $this->number($stats[$field[2]] ?? null), (int) ($stats['samples_'.$field[2]] ?? 0), $field[3] ?? 0), $fields);
        if (in_array(strtoupper(trim($positionCode)), ['WR', 'TE'], true)) {
            $samples = (int) ($stats['samples_receiving_yards'] ?? 0);
            $yards = $this->number($stats['receiving_yards'] ?? null);
            $metrics[] = $this->metric('yards_per_game', 'Yds/G', $samples > 0 && $yards !== null ? $yards / $samples : null, $samples, 1);
        }

        return $metrics;
    }

    /** @return list<array<string,mixed>> */
    protected function formatNbaMetrics(array $stats): array
    {
        $metrics = [];
        foreach (['ppg' => ['PPG', 'points'], 'rpg' => ['RPG', 'rebounds_total'], 'apg' => ['APG', 'assists'],
            'spg' => ['SPG', 'steals'], 'bpg' => ['BPG', 'blocks']] as $key => [$label, $column]) {
            $samples = (int) ($stats['samples_'.$column] ?? 0);
            $sum = $this->number($stats[$column] ?? null);
            $metrics[] = $this->metric($key, $label, $samples > 0 && $sum !== null ? $sum / $samples : null, $samples, 1);
        }

        return $metrics;
    }

    /** @return list<array<string,mixed>> */
    protected function formatMlbMetrics(string $positionCode, array $stats): array
    {
        if (str_contains(strtoupper(trim($positionCode)), 'P')) {
            $eraInnings = $this->number($stats['paired_era_innings_pitched'] ?? null);
            $earned = $this->number($stats['paired_era_earned_runs'] ?? null);
            $whipInnings = $this->number($stats['paired_whip_innings_pitched'] ?? null);
            $walks = $this->number($stats['paired_whip_walks_allowed'] ?? null);
            $hits = $this->number($stats['paired_whip_hits_allowed'] ?? null);

            return [
                $this->metric('ip', 'IP', $this->number($stats['innings_pitched'] ?? null), (int) ($stats['samples_innings_pitched'] ?? 0), 1) + ['unit' => 'decimal_innings'],
                $this->metric('era', 'ERA', $eraInnings > 0 && $earned !== null ? $earned * 9 / $eraInnings : null, (int) ($stats['samples_era'] ?? 0), 2),
                $this->metric('k', 'K', $this->number($stats['strikeouts_pitched'] ?? null), (int) ($stats['samples_strikeouts_pitched'] ?? 0)),
                $this->metric('whip', 'WHIP', $whipInnings > 0 && $walks !== null && $hits !== null ? ($walks + $hits) / $whipInnings : null, (int) ($stats['samples_whip'] ?? 0), 2),
            ];
        }
        $atBats = $this->number($stats['paired_avg_at_bats'] ?? null);
        $hits = $this->number($stats['paired_avg_hits'] ?? null);
        $opsAtBats = $this->number($stats['paired_ops_at_bats'] ?? null);
        $opsHits = $this->number($stats['paired_ops_hits'] ?? null);
        $walks = $this->number($stats['paired_ops_walks'] ?? null);
        $doubles = $this->number($stats['paired_ops_doubles'] ?? null);
        $triples = $this->number($stats['paired_ops_triples'] ?? null);
        $homeRuns = $this->number($stats['paired_ops_home_runs'] ?? null);
        $ops = null;
        if ($opsAtBats > 0 && $opsHits !== null && $walks !== null && $doubles !== null && $triples !== null && $homeRuns !== null) {
            $singles = $opsHits - $doubles - $triples - $homeRuns;
            if ($singles >= 0 && $opsAtBats + $walks > 0) {
                $ops = ($opsHits + $walks) / ($opsAtBats + $walks)
                    + ($singles + 2 * $doubles + 3 * $triples + 4 * $homeRuns) / $opsAtBats;
            }
        }

        return [
            $this->metric('avg', 'AVG', $atBats > 0 && $hits !== null ? $hits / $atBats : null, (int) ($stats['samples_avg'] ?? 0), 3),
            $this->metric('ops', 'OPS', $ops, (int) ($stats['samples_ops'] ?? 0), 3),
            $this->metric('hr', 'HR', $this->number($stats['home_runs'] ?? null), (int) ($stats['samples_home_runs'] ?? 0)),
            $this->metric('rbi', 'RBI', $this->number($stats['rbis'] ?? null), (int) ($stats['samples_rbis'] ?? 0)),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    /** @return array{key:string,label:string,value:string,sample_size:int} */
    private function metric(string $key, string $label, ?float $value, int $samples, int $precision = 0): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value === null || ! is_finite($value) ? '-' : number_format($value, $precision, '.', ''),
            'sample_size' => $samples];
    }
}
