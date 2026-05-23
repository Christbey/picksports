<?php

namespace App\Console\Commands\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AnalyzePointProjectionsCommand extends Command
{
    protected $signature = 'nfl:analyze-point-projections
        {--season= : Season to analyze}
        {--from-season= : Analyze starting with this NFL season}
        {--to-season= : Analyze through this NFL season}
        {--limit=0 : Limit number of most recent final predictions to inspect}
        {--layers : Show performance by active model layer}
        {--detailed : Show biggest point projection misses}';

    protected $description = 'Analyze NFL predicted spreads, totals, and implied team score projections against final scores';

    public function handle(): int
    {
        try {
            $scope = $this->resolveScope();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = $this->loadRows($scope);

        if ($rows->isEmpty()) {
            $this->warn('No final NFL predictions with point projections found for '.$scope['label'].'.');

            return self::SUCCESS;
        }

        $summary = $this->summary($rows);

        $this->info('NFL Point Projection Audit');
        $this->line('Scope: '.$scope['label']);
        $this->line('Rows: '.$rows->count());
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Winner accuracy', number_format($summary['winner_accuracy'], 1).'%'],
                ['Spread MAE', number_format($summary['spread_mae'], 2)],
                ['Spread RMSE', number_format($summary['spread_rmse'], 2)],
                ['Spread bias', $this->signed($summary['spread_bias'], 2)],
                ['Total MAE', number_format($summary['total_mae'], 2)],
                ['Total RMSE', number_format($summary['total_rmse'], 2)],
                ['Total bias', $this->signed($summary['total_bias'], 2)],
                ['Home score MAE', number_format($summary['home_score_mae'], 2)],
                ['Home score bias', $this->signed($summary['home_score_bias'], 2)],
                ['Away score MAE', number_format($summary['away_score_mae'], 2)],
                ['Away score bias', $this->signed($summary['away_score_bias'], 2)],
                ['Within 3 pts spread', number_format($summary['spread_within_3'], 1).'%'],
                ['Within 7 pts spread', number_format($summary['spread_within_7'], 1).'%'],
                ['Within 6 pts total', number_format($summary['total_within_6'], 1).'%'],
                ['Within 10 pts total', number_format($summary['total_within_10'], 1).'%'],
            ]
        );

        $this->newLine();
        $this->info('By Season');
        $this->table(
            ['Season', 'Games', 'Win Acc', 'Spread MAE', 'Spread Bias', 'Total MAE', 'Total Bias', 'Score MAE'],
            $this->groupRows($rows, fn (array $row): int => (int) $row['season'])
        );

        $this->newLine();
        $this->info('By Week Bucket');
        $this->table(
            ['Week Bucket', 'Games', 'Win Acc', 'Spread MAE', 'Spread Bias', 'Total MAE', 'Total Bias', 'Score MAE'],
            $this->groupRows($rows, fn (array $row): string => $this->weekBucket((int) $row['week']))
        );

        $this->newLine();
        $this->info('Point Projection Buckets');
        $this->table(
            ['Bucket', 'Games', 'Win Acc', 'Spread MAE', 'Spread Bias', 'Total MAE', 'Total Bias', 'Score MAE'],
            $this->projectionBucketRows($rows)
        );

        $marketRows = $rows->filter(fn (array $row): bool => $row['market_total'] !== null || $row['market_spread'] !== null)->values();
        if ($marketRows->isNotEmpty()) {
            $market = $this->marketSummary($marketRows);
            $this->newLine();
            $this->info('Model vs Market');
            $this->table(
                ['Metric', 'Model', 'Market'],
                [
                    ['Spread MAE', number_format($summary['spread_mae'], 2), $market['spread_mae'] !== null ? number_format($market['spread_mae'], 2) : 'n/a'],
                    ['Spread Bias', $this->signed($summary['spread_bias'], 2), $market['spread_bias'] !== null ? $this->signed($market['spread_bias'], 2) : 'n/a'],
                    ['Total MAE', number_format($summary['total_mae'], 2), $market['total_mae'] !== null ? number_format($market['total_mae'], 2) : 'n/a'],
                    ['Total Bias', $this->signed($summary['total_bias'], 2), $market['total_bias'] !== null ? $this->signed($market['total_bias'], 2) : 'n/a'],
                ]
            );
        }

        if ($this->option('layers')) {
            $this->newLine();
            $this->info('By Active Model Layer');
            $this->table(
                ['Layer', 'Games', 'Win Acc', 'Spread MAE', 'Spread Bias', 'Total MAE', 'Total Bias', 'Score MAE'],
                $this->layerRows($rows)
            );
        }

        $this->newLine();
        $this->info('Tuning Read');
        foreach ($this->tuningNotes($rows, $summary) as $note) {
            $this->line('- '.$note);
        }

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Largest Projection Misses');
            $this->table(
                ['Date', 'Game', 'Actual', 'Projected', 'Spread Err', 'Total Err'],
                $rows
                    ->sortByDesc(fn (array $row): float => (float) $row['score_error'])
                    ->take(12)
                    ->map(fn (array $row): array => [
                        $row['date'],
                        $row['away'].' @ '.$row['home'],
                        "{$row['away_score']}-{$row['home_score']}",
                        number_format((float) $row['pred_away_score'], 1).'-'.number_format((float) $row['pred_home_score'], 1),
                        number_format((float) $row['spread_error'], 1),
                        number_format((float) $row['total_error'], 1),
                    ])
                    ->values()
                    ->all()
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array{season:?int,from_season:?int,to_season:?int,label:string}
     */
    private function resolveScope(): array
    {
        $season = $this->option('season');
        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');

        if ($season !== null && ($fromSeason !== null || $toSeason !== null)) {
            throw new \InvalidArgumentException('Use either --season or --from-season/--to-season, not both.');
        }

        if ($fromSeason !== null || $toSeason !== null) {
            $start = (int) ($fromSeason ?? $toSeason);
            $end = (int) ($toSeason ?? $fromSeason);

            if ($start > $end) {
                throw new \InvalidArgumentException('--from-season must be less than or equal to --to-season.');
            }

            return [
                'season' => null,
                'from_season' => $start,
                'to_season' => $end,
                'label' => "seasons {$start}-{$end}",
            ];
        }

        if ($season !== null) {
            return [
                'season' => (int) $season,
                'from_season' => null,
                'to_season' => null,
                'label' => "season {$season}",
            ];
        }

        return [
            'season' => null,
            'from_season' => null,
            'to_season' => null,
            'label' => 'all seasons',
        ];
    }

    /**
     * @param  array{season:?int,from_season:?int,to_season:?int,label:string}  $scope
     * @return Collection<int,array<string,mixed>>
     */
    private function loadRows(array $scope): Collection
    {
        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('predicted_spread')
            ->whereNotNull('predicted_total')
            ->whereHas('game', function ($query) use ($scope): void {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');

                if ($scope['season'] !== null) {
                    $query->where('season', $scope['season']);
                }

                if ($scope['from_season'] !== null) {
                    $query->where('season', '>=', $scope['from_season']);
                }

                if ($scope['to_season'] !== null) {
                    $query->where('season', '<=', $scope['to_season']);
                }
            })
            ->latest();

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(function (Prediction $prediction): ?array {
                $game = $prediction->game;
                if (! $game) {
                    return null;
                }

                $predictedSpread = (float) $prediction->predicted_spread;
                $predictedTotal = (float) $prediction->predicted_total;
                $actualSpread = (float) $game->home_score - (float) $game->away_score;
                $actualTotal = (float) $game->home_score + (float) $game->away_score;
                $predictedHomeScore = ($predictedTotal + $predictedSpread) / 2;
                $predictedAwayScore = ($predictedTotal - $predictedSpread) / 2;
                $market = $this->marketLines($game);

                return [
                    'date' => $game->game_date?->format('Y-m-d') ?? '',
                    'season' => (int) $game->season,
                    'week' => (int) $game->week,
                    'home' => (string) ($game->homeTeam?->abbreviation ?? 'UNK'),
                    'away' => (string) ($game->awayTeam?->abbreviation ?? 'UNK'),
                    'home_score' => (float) $game->home_score,
                    'away_score' => (float) $game->away_score,
                    'pred_home_score' => $predictedHomeScore,
                    'pred_away_score' => $predictedAwayScore,
                    'actual_spread' => $actualSpread,
                    'predicted_spread' => $predictedSpread,
                    'spread_error' => abs($predictedSpread - $actualSpread),
                    'spread_residual' => $predictedSpread - $actualSpread,
                    'actual_total' => $actualTotal,
                    'predicted_total' => $predictedTotal,
                    'total_error' => abs($predictedTotal - $actualTotal),
                    'total_residual' => $predictedTotal - $actualTotal,
                    'home_score_error' => abs($predictedHomeScore - (float) $game->home_score),
                    'home_score_residual' => $predictedHomeScore - (float) $game->home_score,
                    'away_score_error' => abs($predictedAwayScore - (float) $game->away_score),
                    'away_score_residual' => $predictedAwayScore - (float) $game->away_score,
                    'score_error' => (abs($predictedHomeScore - (float) $game->home_score) + abs($predictedAwayScore - (float) $game->away_score)) / 2,
                    'winner_correct' => ($actualSpread > 0 && $predictedSpread > 0) || ($actualSpread < 0 && $predictedSpread < 0),
                    'market_spread' => $market['spread'],
                    'market_total' => $market['total'],
                    'metadata' => is_array($prediction->model_metadata) ? $prediction->model_metadata : [],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,float>
     */
    private function summary(Collection $rows): array
    {
        return [
            'winner_accuracy' => $this->rate($rows->where('winner_correct', true)->count(), $rows->count()),
            'spread_mae' => (float) $rows->avg('spread_error'),
            'spread_rmse' => $this->rmse($rows, 'spread_residual'),
            'spread_bias' => (float) $rows->avg('spread_residual'),
            'total_mae' => (float) $rows->avg('total_error'),
            'total_rmse' => $this->rmse($rows, 'total_residual'),
            'total_bias' => (float) $rows->avg('total_residual'),
            'home_score_mae' => (float) $rows->avg('home_score_error'),
            'home_score_bias' => (float) $rows->avg('home_score_residual'),
            'away_score_mae' => (float) $rows->avg('away_score_error'),
            'away_score_bias' => (float) $rows->avg('away_score_residual'),
            'spread_within_3' => $this->rate($rows->filter(fn (array $row): bool => (float) $row['spread_error'] <= 3.0)->count(), $rows->count()),
            'spread_within_7' => $this->rate($rows->filter(fn (array $row): bool => (float) $row['spread_error'] <= 7.0)->count(), $rows->count()),
            'total_within_6' => $this->rate($rows->filter(fn (array $row): bool => (float) $row['total_error'] <= 6.0)->count(), $rows->count()),
            'total_within_10' => $this->rate($rows->filter(fn (array $row): bool => (float) $row['total_error'] <= 10.0)->count(), $rows->count()),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<int,array<int,string|int>>
     */
    private function groupRows(Collection $rows, callable $groupBy): array
    {
        return $rows
            ->groupBy($groupBy)
            ->sortKeys()
            ->map(fn (Collection $group, int|string $label): array => $this->summaryRow((string) $label, $group))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<int,string|int>
     */
    private function summaryRow(string $label, Collection $rows): array
    {
        $summary = $this->summary($rows);

        return [
            $label,
            $rows->count(),
            number_format($summary['winner_accuracy'], 1).'%',
            number_format($summary['spread_mae'], 2),
            $this->signed($summary['spread_bias'], 2),
            number_format($summary['total_mae'], 2),
            $this->signed($summary['total_bias'], 2),
            number_format(($summary['home_score_mae'] + $summary['away_score_mae']) / 2, 2),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<int,array<int,string|int>>
     */
    private function projectionBucketRows(Collection $rows): array
    {
        $buckets = [
            'Low total <40' => fn (array $row): bool => (float) $row['predicted_total'] < 40.0,
            'Mid total 40-47' => fn (array $row): bool => (float) $row['predicted_total'] >= 40.0 && (float) $row['predicted_total'] < 47.0,
            'High total 47+' => fn (array $row): bool => (float) $row['predicted_total'] >= 47.0,
            'Tight spread <3' => fn (array $row): bool => abs((float) $row['predicted_spread']) < 3.0,
            'Medium spread 3-7' => fn (array $row): bool => abs((float) $row['predicted_spread']) >= 3.0 && abs((float) $row['predicted_spread']) < 7.0,
            'Big spread 7+' => fn (array $row): bool => abs((float) $row['predicted_spread']) >= 7.0,
        ];

        return collect($buckets)
            ->map(function (callable $filter, string $label) use ($rows): ?array {
                $group = $rows->filter($filter)->values();

                return $group->isEmpty() ? null : $this->summaryRow($label, $group);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,?float>
     */
    private function marketSummary(Collection $rows): array
    {
        $spreadRows = $rows->filter(fn (array $row): bool => $row['market_spread'] !== null)->values();
        $totalRows = $rows->filter(fn (array $row): bool => $row['market_total'] !== null)->values();

        return [
            'spread_mae' => $spreadRows->isNotEmpty()
                ? (float) $spreadRows->avg(fn (array $row): float => abs((float) $row['market_spread'] - (float) $row['actual_spread']))
                : null,
            'spread_bias' => $spreadRows->isNotEmpty()
                ? (float) $spreadRows->avg(fn (array $row): float => (float) $row['market_spread'] - (float) $row['actual_spread'])
                : null,
            'total_mae' => $totalRows->isNotEmpty()
                ? (float) $totalRows->avg(fn (array $row): float => abs((float) $row['market_total'] - (float) $row['actual_total']))
                : null,
            'total_bias' => $totalRows->isNotEmpty()
                ? (float) $totalRows->avg(fn (array $row): float => (float) $row['market_total'] - (float) $row['actual_total'])
                : null,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<int,array<int,string|int>>
     */
    private function layerRows(Collection $rows): array
    {
        $layers = [
            'true_epa',
            'preseason_signal',
            'rolling_efficiency',
            'opponent_adjusted_efficiency',
            'total_environment',
            'qb_form',
            'line_matchup',
            'contextual_factors',
            'actual_weather',
            'depth_chart_injuries',
            'adaptive_point_calibration',
            'market_blend',
        ];

        return collect($layers)
            ->map(function (string $layer) use ($rows): ?array {
                $group = $rows->filter(fn (array $row): bool => (bool) data_get($row['metadata'], "{$layer}.applied", false))->values();

                return $group->isEmpty() ? null : $this->summaryRow($layer, $group);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @param  array<string,float>  $summary
     * @return array<int,string>
     */
    private function tuningNotes(Collection $rows, array $summary): array
    {
        $notes = [];
        $spreadBias = (float) $summary['spread_bias'];
        $totalBias = (float) $summary['total_bias'];

        if (abs($spreadBias) >= 1.0) {
            $direction = $spreadBias > 0 ? 'home teams too high' : 'away teams too high';
            $hfaDelta = -$spreadBias / max(0.001, (float) config('nfl.predictions.points_per_elo', 0.09));
            $notes[] = "Spread bias is {$this->signed($spreadBias, 2)} points ({$direction}). A first tuning test is moving NFL_ELO_HOME_FIELD_ADVANTAGE by about {$this->signed($hfaDelta, 0)} Elo points, then rerunning the backfill.";
        } else {
            $notes[] = 'Spread bias is small overall; tune layer weights by bucket before changing home-field advantage.';
        }

        if (abs($totalBias) >= 1.0) {
            $direction = $totalBias > 0 ? 'too high' : 'too low';
            $notes[] = "Total bias is {$this->signed($totalBias, 2)} points ({$direction}). A first tuning test is shifting NFL prediction average_total by {$this->signed(-$totalBias, 2)} points.";
        } else {
            $notes[] = 'Total bias is small overall; totals need variance reduction more than a baseline move.';
        }

        $worstSeason = $rows
            ->groupBy(fn (array $row): int => (int) $row['season'])
            ->map(fn (Collection $group, int $season): array => ['season' => $season, 'spread_mae' => (float) $group->avg('spread_error'), 'total_mae' => (float) $group->avg('total_error')])
            ->sortByDesc(fn (array $row): float => $row['spread_mae'] + $row['total_mae'])
            ->first();

        if ($worstSeason) {
            $notes[] = "Worst combined point season is {$worstSeason['season']} with spread MAE ".number_format($worstSeason['spread_mae'], 2).' and total MAE '.number_format($worstSeason['total_mae'], 2).'. Use that as the first regression-check season after every tuning sweep.';
        }

        return $notes;
    }

    private function weekBucket(int $week): string
    {
        return match (true) {
            $week <= 4 => 'Weeks 1-4',
            $week <= 9 => 'Weeks 5-9',
            $week <= 14 => 'Weeks 10-14',
            default => 'Weeks 15+',
        };
    }

    /**
     * @return array{spread:?float,total:?float}
     */
    private function marketLines(Game $game): array
    {
        $oddsData = is_array($game->odds_data) ? $game->odds_data : null;
        $spread = $this->homeMarketSpread($oddsData);
        $total = $this->marketTotal($oddsData);

        if ($spread !== null || $total !== null) {
            return ['spread' => $spread !== null ? -$spread : null, 'total' => $total];
        }

        $snapshot = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', (int) $game->getKey())
            ->orderBy('captured_at')
            ->first();

        $snapshotOdds = is_array($snapshot?->odds_data) ? $snapshot->odds_data : null;
        $spread = $this->homeMarketSpread($snapshotOdds);

        return [
            'spread' => $spread !== null ? -$spread : null,
            'total' => $this->marketTotal($snapshotOdds),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $oddsData
     */
    private function homeMarketSpread(?array $oddsData): ?float
    {
        if (! $oddsData) {
            return null;
        }

        $homeTeamName = (string) ($oddsData['home_team'] ?? '');
        if ($homeTeamName === '') {
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
     * @param  array<string,mixed>|null  $oddsData
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

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function rmse(Collection $rows, string $residualKey): float
    {
        return sqrt((float) $rows->avg(fn (array $row): float => ((float) $row[$residualKey]) ** 2));
    }

    private function rate(int $count, int $total): float
    {
        return $total > 0 ? ($count / $total) * 100 : 0.0;
    }

    private function signed(float $value, int $precision = 1): string
    {
        $formatted = number_format($value, $precision);

        return $value > 0 ? "+{$formatted}" : $formatted;
    }
}
