<?php

namespace App\Console\Commands\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerStat;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReportWeekTrendsCommand extends Command
{
    protected $signature = 'nfl:report-week-trends
        {--from-season=2021 : First season to include}
        {--to-season=2025 : Last season to include}
        {--season-type=2 : Season type to include}
        {--early-weeks=4 : Number of early-season weeks to summarize}
        {--min-games=20 : Minimum games for top angle rows}
        {--top=20 : Number of top angle rows to show}
        {--output= : Optional JSON output path}';

    protected $description = 'Report historical NFL ATS and totals trends by week using stored market lines';

    public function handle(): int
    {
        $rows = $this->rows();

        if ($rows->isEmpty()) {
            $this->warn('No completed NFL games with market spread data found for the selected scope.');

            return self::SUCCESS;
        }

        $weekRows = $this->weekRows($rows);
        $angleRows = $this->angleRows($rows);
        $teamQualityRows = $this->teamQualityRows($rows);
        $qbRows = $this->qbRows($rows);

        $fromSeason = (int) $this->option('from-season');
        $toSeason = (int) $this->option('to-season');

        $this->info('NFL Week Trends');
        $this->line("Scope: {$fromSeason} through {$toSeason}; rows: {$rows->count()}");
        $this->line('Market line source: stored game odds or earliest odds snapshot.');
        $this->newLine();

        $this->table(
            ['Week', 'Games', 'Home ATS', 'Fav ATS', 'Dog ATS', 'Overs', 'Avg Line', 'Avg Total', 'Avg Pts'],
            $weekRows
        );

        $this->newLine();
        $this->info('Champion And Playoff Team Trends');
        $this->table(
            ['Group', 'Scope', 'Team Games', 'SU', 'ATS', 'Avg Margin', 'Avg ATS'],
            $teamQualityRows
        );

        $this->newLine();
        $this->info('QB Experience Trends');
        $this->table(
            ['Group', 'Scope', 'Starts', 'SU', 'ATS', 'Avg Margin', 'Avg ATS'],
            $qbRows
        );

        $this->newLine();
        $this->info('Top Week Angles');
        $this->table(
            ['Angle', 'Week', 'Games', 'Record', 'Win %', 'Avg Margin'],
            array_slice($angleRows, 0, max(1, (int) $this->option('top')))
        );

        if ($output = $this->option('output')) {
            @mkdir(dirname((string) $output), 0777, true);
            file_put_contents((string) $output, json_encode([
                'report_type' => 'nfl_week_trends',
                'from_season' => $fromSeason,
                'to_season' => $toSeason,
                'season_type' => $this->option('season-type'),
                'row_count' => $rows->count(),
                'weeks' => $weekRows,
                'team_quality' => $teamQualityRows,
                'qb_experience' => $qbRows,
                'top_angles' => $angleRows,
                'source_note' => 'Pregame-style ATS/totals trend report calculated from local stored market lines. First-recorded-season QB is a local-data proxy, not an official rookie designation.',
                'pregame_reference' => 'https://pregame.com/game-center/game-center-guide',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Wrote report to {$output}");
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $fromSeason = (int) $this->option('from-season');
        $toSeason = (int) $this->option('to-season');
        $seasonType = (string) $this->option('season-type');

        return Game::query()
            ->with(['homeTeam', 'awayTeam', 'prediction'])
            ->whereBetween('season', [$fromSeason, $toSeason])
            ->where('season_type', $seasonType)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderBy('season')
            ->orderBy('week')
            ->orderBy('game_date')
            ->get()
            ->map(fn (Game $game): ?array => $this->row($game))
            ->filter()
            ->values()
            ->pipe(fn (Collection $rows): Collection => $this->withPrimaryQbContext($rows));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(Game $game): ?array
    {
        $lineSet = $this->lineSet($game);
        $homeSpread = $lineSet['home_spread'];
        if ($homeSpread === null) {
            return null;
        }

        $total = $lineSet['total'];
        $actualMargin = (float) $game->home_score - (float) $game->away_score;
        $actualTotal = (float) $game->home_score + (float) $game->away_score;
        $homeAtsMargin = $actualMargin + $homeSpread;
        $favoriteSide = $homeSpread < 0 ? 'home' : ($homeSpread > 0 ? 'away' : null);
        $favoriteAtsMargin = $favoriteSide === 'home' ? $homeAtsMargin : ($favoriteSide === 'away' ? -$homeAtsMargin : null);
        $dogAtsMargin = $favoriteAtsMargin !== null ? -$favoriteAtsMargin : null;
        $overMargin = $total !== null ? $actualTotal - $total : null;

        return [
            'season' => (int) $game->season,
            'week' => (int) $game->week,
            'game_id' => (int) $game->getKey(),
            'home_team_id' => (int) $game->home_team_id,
            'away_team_id' => (int) $game->away_team_id,
            'home_team' => (string) ($game->homeTeam?->abbreviation ?? $game->homeTeam?->name ?? 'HOME'),
            'away_team' => (string) ($game->awayTeam?->abbreviation ?? $game->awayTeam?->name ?? 'AWAY'),
            'home_score' => (float) $game->home_score,
            'away_score' => (float) $game->away_score,
            'home_spread' => $homeSpread,
            'market_margin' => -$homeSpread,
            'total' => $total,
            'actual_margin' => $actualMargin,
            'actual_total' => $actualTotal,
            'home_ats_margin' => $homeAtsMargin,
            'away_ats_margin' => -$homeAtsMargin,
            'favorite_side' => $favoriteSide,
            'favorite_ats_margin' => $favoriteAtsMargin,
            'dog_ats_margin' => $dogAtsMargin,
            'over_margin' => $overMargin,
            'home_qb_bucket' => data_get($game->prediction?->model_metadata, 'qb_form.home.experience_bucket'),
            'away_qb_bucket' => data_get($game->prediction?->model_metadata, 'qb_form.away.experience_bucket'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function weekRows(Collection $rows): array
    {
        return $rows
            ->groupBy('week')
            ->sortKeys()
            ->map(function (Collection $group, int|string $week): array {
                $totals = $group->filter(fn (array $row): bool => $row['over_margin'] !== null)->values();

                return [
                    'Week '.$week,
                    (string) $group->count(),
                    $this->record($group, 'home_ats_margin'),
                    $this->record($group->filter(fn (array $row): bool => $row['favorite_ats_margin'] !== null)->values(), 'favorite_ats_margin'),
                    $this->record($group->filter(fn (array $row): bool => $row['dog_ats_margin'] !== null)->values(), 'dog_ats_margin'),
                    $this->record($totals, 'over_margin'),
                    number_format((float) $group->avg(fn (array $row): float => abs((float) $row['market_margin'])), 1),
                    $totals->isNotEmpty() ? number_format((float) $totals->avg('total'), 1) : 'n/a',
                    number_format((float) $group->avg('actual_total'), 1),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function teamQualityRows(Collection $rows): array
    {
        $fromSeason = (int) $this->option('from-season');
        $toSeason = (int) $this->option('to-season');
        $earlyWeeks = max(1, (int) $this->option('early-weeks'));
        $champions = $this->superBowlChampionsBySeason($fromSeason - 1, $toSeason);
        $playoffTeams = $this->playoffTeamsBySeason($fromSeason - 1, $toSeason);

        $groups = [
            'Eventual Super Bowl champs' => $champions,
            'Defending Super Bowl champs' => $this->previousSeasonMap($champions, $fromSeason, $toSeason),
            'Eventual playoff teams' => $playoffTeams,
            'Prior-season playoff teams' => $this->previousSeasonMap($playoffTeams, $fromSeason, $toSeason),
        ];

        $summaries = [];

        foreach ($groups as $label => $teamsBySeason) {
            $summaries[] = $this->teamSummaryRow($label, 'Week 1', $rows, $teamsBySeason, [1]);
            $summaries[] = $this->teamSummaryRow($label, "Weeks 1-{$earlyWeeks}", $rows, $teamsBySeason, range(1, $earlyWeeks));
        }

        return $summaries;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function qbRows(Collection $rows): array
    {
        $earlyWeeks = max(1, (int) $this->option('early-weeks'));
        $groups = [
            'Rookie QB starts (metadata)' => fn (array $row, string $side): bool => ($row[$side.'_qb_bucket'] ?? null) === 'rookie',
            'First-year starter QB starts' => fn (array $row, string $side): bool => ($row[$side.'_qb_bucket'] ?? null) === 'first_year_starter',
            'First-recorded-season QB starts' => fn (array $row, string $side): bool => (bool) ($row[$side.'_primary_qb_first_recorded_season'] ?? false),
        ];

        $summaries = [];

        foreach ($groups as $label => $filter) {
            $summaries[] = $this->qbSummaryRow($label, 'Week 1', $rows, $filter, [1]);
            $summaries[] = $this->qbSummaryRow($label, "Weeks 1-{$earlyWeeks}", $rows, $filter, range(1, $earlyWeeks));
        }

        return $summaries;
    }

    /**
     * @param  array<int, array<int, string>>  $teamIdsBySeason
     * @param  array<int, int>  $weeks
     * @return array<int, string>
     */
    private function teamSummaryRow(string $label, string $scope, Collection $rows, array $teamIdsBySeason, array $weeks): array
    {
        $starts = [];

        foreach ($rows as $row) {
            if (! in_array((int) $row['week'], $weeks, true)) {
                continue;
            }

            $teamIds = $teamIdsBySeason[(int) $row['season']] ?? [];
            if ($teamIds === []) {
                continue;
            }

            if (in_array((int) $row['home_team_id'], $teamIds, true)) {
                $starts[] = [
                    'su_margin' => (float) $row['actual_margin'],
                    'ats_margin' => (float) $row['home_ats_margin'],
                ];
            }

            if (in_array((int) $row['away_team_id'], $teamIds, true)) {
                $starts[] = [
                    'su_margin' => -(float) $row['actual_margin'],
                    'ats_margin' => (float) $row['away_ats_margin'],
                ];
            }
        }

        return $this->summaryRow($label, $scope, collect($starts));
    }

    /**
     * @param  callable(array<string, mixed>, string): bool  $filter
     * @param  array<int, int>  $weeks
     * @return array<int, string>
     */
    private function qbSummaryRow(string $label, string $scope, Collection $rows, callable $filter, array $weeks): array
    {
        $starts = [];

        foreach ($rows as $row) {
            if (! in_array((int) $row['week'], $weeks, true)) {
                continue;
            }

            foreach (['home', 'away'] as $side) {
                if (! $filter($row, $side)) {
                    continue;
                }

                $starts[] = [
                    'su_margin' => $side === 'home' ? (float) $row['actual_margin'] : -(float) $row['actual_margin'],
                    'ats_margin' => $side === 'home' ? (float) $row['home_ats_margin'] : (float) $row['away_ats_margin'],
                ];
            }
        }

        return $this->summaryRow($label, $scope, collect($starts));
    }

    /**
     * @param  Collection<int, array{su_margin:float,ats_margin:float}>  $starts
     * @return array<int, string>
     */
    private function summaryRow(string $label, string $scope, Collection $starts): array
    {
        if ($starts->isEmpty()) {
            return [$label, $scope, '0', 'n/a', 'n/a', 'n/a', 'n/a'];
        }

        return [
            $label,
            $scope,
            (string) $starts->count(),
            $this->recordFromMargins($starts->pluck('su_margin')),
            $this->recordFromMargins($starts->pluck('ats_margin')),
            $this->signed((float) $starts->avg('su_margin'), 2),
            $this->signed((float) $starts->avg('ats_margin'), 2),
        ];
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function superBowlChampionsBySeason(int $fromSeason, int $toSeason): array
    {
        return Game::query()
            ->whereBetween('season', [$fromSeason, $toSeason])
            ->where('season_type', 3)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderBy('season')
            ->orderBy('game_date')
            ->orderBy('id')
            ->get()
            ->groupBy('season')
            ->map(function (Collection $games): array {
                /** @var Game|null $game */
                $game = $games->last();
                if (! $game) {
                    return [];
                }

                return [(int) $game->home_score > (int) $game->away_score ? (int) $game->home_team_id : (int) $game->away_team_id];
            })
            ->all();
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function playoffTeamsBySeason(int $fromSeason, int $toSeason): array
    {
        return Game::query()
            ->whereBetween('season', [$fromSeason, $toSeason])
            ->where('season_type', 3)
            ->where('status', 'STATUS_FINAL')
            ->get()
            ->groupBy('season')
            ->map(function (Collection $games): array {
                return $games
                    ->flatMap(fn (Game $game): array => [(int) $game->home_team_id, (int) $game->away_team_id])
                    ->unique()
                    ->values()
                    ->all();
            })
            ->all();
    }

    /**
     * @param  array<int, array<int, int>>  $teamsBySeason
     * @return array<int, array<int, int>>
     */
    private function previousSeasonMap(array $teamsBySeason, int $fromSeason, int $toSeason): array
    {
        $mapped = [];

        for ($season = $fromSeason; $season <= $toSeason; $season++) {
            $mapped[$season] = $teamsBySeason[$season - 1] ?? [];
        }

        return $mapped;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withPrimaryQbContext(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $fromSeason = (int) $this->option('from-season');
        $gameIds = $rows->pluck('game_id')->map(fn (int $id): int => $id)->all();
        $stats = PlayerStat::query()
            ->with('game')
            ->whereIn('game_id', $gameIds)
            ->where('passing_attempts', '>', 0)
            ->get(['id', 'player_id', 'game_id', 'team_id', 'passing_attempts']);

        $primaryByGameTeam = [];

        foreach ($stats->groupBy(fn (PlayerStat $stat): string => $stat->game_id.':'.$stat->team_id) as $key => $group) {
            $primary = $group->sortByDesc(fn (PlayerStat $stat): int => (int) $stat->passing_attempts)->first();
            if ($primary) {
                $primaryByGameTeam[$key] = (int) $primary->player_id;
            }
        }

        $playerIds = array_values(array_unique(array_filter($primaryByGameTeam)));
        $firstSeasonByPlayer = [];

        if ($playerIds !== []) {
            PlayerStat::query()
                ->select('nfl_player_stats.player_id')
                ->selectRaw('min(nfl_games.season) as first_season')
                ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
                ->whereIn('nfl_player_stats.player_id', $playerIds)
                ->where('nfl_player_stats.passing_attempts', '>', 0)
                ->groupBy('nfl_player_stats.player_id')
                ->get()
                ->each(function (object $row) use (&$firstSeasonByPlayer): void {
                    $firstSeasonByPlayer[(int) $row->player_id] = (int) $row->first_season;
                });
        }

        return $rows->map(function (array $row) use ($fromSeason, $primaryByGameTeam, $firstSeasonByPlayer): array {
            foreach (['home', 'away'] as $side) {
                $teamId = (int) $row[$side.'_team_id'];
                $playerId = $primaryByGameTeam[$row['game_id'].':'.$teamId] ?? null;
                $firstSeason = $playerId ? ($firstSeasonByPlayer[$playerId] ?? null) : null;
                $row[$side.'_primary_qb_id'] = $playerId;
                $row[$side.'_primary_qb_first_recorded_season'] = $firstSeason !== null
                    && $firstSeason === (int) $row['season']
                    && (int) $row['season'] > $fromSeason;
            }

            return $row;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function angleRows(Collection $rows): array
    {
        $minGames = max(1, (int) $this->option('min-games'));
        $angles = [];

        foreach ($rows->groupBy('week') as $week => $group) {
            $this->addAngle($angles, 'Home teams ATS', (int) $week, $group, 'home_ats_margin', $minGames);
            $this->addAngle($angles, 'Away teams ATS', (int) $week, $group, 'away_ats_margin', $minGames);
            $this->addAngle($angles, 'Favorites ATS', (int) $week, $group->filter(fn (array $row): bool => $row['favorite_ats_margin'] !== null)->values(), 'favorite_ats_margin', $minGames);
            $this->addAngle($angles, 'Underdogs ATS', (int) $week, $group->filter(fn (array $row): bool => $row['dog_ats_margin'] !== null)->values(), 'dog_ats_margin', $minGames);
            $this->addAngle($angles, 'Overs', (int) $week, $group->filter(fn (array $row): bool => $row['over_margin'] !== null)->values(), 'over_margin', $minGames);
            $this->addAngle($angles, 'Unders', (int) $week, $group->filter(fn (array $row): bool => $row['over_margin'] !== null)->values(), 'over_margin', $minGames, invert: true);
        }

        usort($angles, function (array $left, array $right): int {
            return ((float) str_replace('%', '', $right[4]) <=> (float) str_replace('%', '', $left[4]))
                ?: ((int) $right[2] <=> (int) $left[2]);
        });

        return $angles;
    }

    /**
     * @param  array<int, array<int, string>>  $angles
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function addAngle(array &$angles, string $label, int $week, Collection $rows, string $marginKey, int $minGames, bool $invert = false): void
    {
        if ($rows->count() < $minGames) {
            return;
        }

        $margins = $rows->map(fn (array $row): float => (float) $row[$marginKey] * ($invert ? -1 : 1));
        $wins = $margins->filter(fn (float $margin): bool => $margin > 0.0001)->count();
        $pushes = $margins->filter(fn (float $margin): bool => abs($margin) <= 0.0001)->count();
        $losses = $rows->count() - $wins - $pushes;
        $graded = $wins + $losses;

        if ($graded === 0) {
            return;
        }

        $angles[] = [
            $label,
            'Week '.$week,
            (string) $rows->count(),
            "{$wins}-{$losses}-{$pushes}",
            number_format(($wins / $graded) * 100, 1).'%',
            $this->signed((float) $margins->avg(), 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function record(Collection $rows, string $marginKey): string
    {
        if ($rows->isEmpty()) {
            return 'n/a';
        }

        return $this->recordFromMargins($rows->map(fn (array $row): float => (float) $row[$marginKey]));
    }

    /**
     * @param  Collection<int, float|int|string|null>  $margins
     */
    private function recordFromMargins(Collection $margins): string
    {
        if ($margins->isEmpty()) {
            return 'n/a';
        }

        $wins = $margins->filter(fn (float|int|string|null $margin): bool => (float) $margin > 0.0001)->count();
        $pushes = $margins->filter(fn (float|int|string|null $margin): bool => abs((float) $margin) <= 0.0001)->count();
        $losses = $margins->count() - $wins - $pushes;
        $graded = $wins + $losses;
        $pct = $graded > 0 ? number_format(($wins / $graded) * 100, 1).'%' : 'n/a';

        return "{$wins}-{$losses}-{$pushes} ({$pct})";
    }

    /**
     * @return array{home_spread:?float,total:?float}
     */
    private function lineSet(Game $game): array
    {
        $oddsData = is_array($game->odds_data) ? $game->odds_data : null;
        $homeTeamName = (string) ($oddsData['home_team'] ?? '');
        $homeSpread = $this->homeSpread($oddsData, $homeTeamName);
        $total = $this->marketTotal($oddsData);

        if ($homeSpread === null || $total === null) {
            $snapshot = GameOddsSnapshot::query()
                ->where('sport', 'nfl')
                ->where('game_table', $game->getTable())
                ->where('game_id', (int) $game->getKey())
                ->orderBy('captured_at')
                ->first();

            $snapshotOdds = is_array($snapshot?->odds_data) ? $snapshot->odds_data : null;
            $snapshotHomeTeamName = (string) ($snapshotOdds['home_team'] ?? '');
            $homeSpread ??= $this->homeSpread($snapshotOdds, $snapshotHomeTeamName);
            $total ??= $this->marketTotal($snapshotOdds);
        }

        return [
            'home_spread' => $homeSpread,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oddsData
     */
    private function homeSpread(?array $oddsData, string $homeTeamName): ?float
    {
        if (! $oddsData || $homeTeamName === '') {
            return null;
        }

        foreach ($oddsData['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach ($market['outcomes'] ?? [] as $outcome) {
                    if (($outcome['name'] ?? null) === $homeTeamName && isset($outcome['point'])) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $oddsData
     */
    private function marketTotal(?array $oddsData): ?float
    {
        if (! $oddsData) {
            return null;
        }

        foreach ($oddsData['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                if (($market['key'] ?? null) !== 'totals') {
                    continue;
                }

                foreach ($market['outcomes'] ?? [] as $outcome) {
                    if (isset($outcome['point'])) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    private function signed(float $value, int $precision = 1): string
    {
        $formatted = number_format($value, $precision);

        return $value > 0 ? "+{$formatted}" : $formatted;
    }
}
