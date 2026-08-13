<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PerformanceStatistics
{
    /** @var array<string,array<string,int|float>> */
    private array $sportStatsMemo = [];

    /** @var array<string,int|null> */
    private array $latestGradedSeasonMemo = [];

    protected const SPORTS = ['nfl', 'cbb', 'nba', 'wcbb', 'wnba', 'mlb', 'cfb'];

    protected const SPORT_LABELS = [
        'nfl' => 'NFL',
        'cbb' => 'College Basketball',
        'nba' => 'NBA',
        'wcbb' => 'Women\'s College Basketball',
        'wnba' => 'WNBA',
        'mlb' => 'MLB',
        'cfb' => 'College Football',
    ];

    /**
     * Get overall performance statistics across all sports.
     */
    public function getOverallStats(?string $fromDate = null, ?string $toDate = null): array
    {
        $totalGraded = 0;
        $totalWinnerCorrect = 0;
        $spreadErrorSum = 0.0;
        $spreadErrorSamples = 0;
        $totalErrorSum = 0.0;
        $totalErrorSamples = 0;

        foreach (self::SPORTS as $sport) {
            $stats = $this->getSportStats($sport, $fromDate, $toDate);
            $totalGraded += $stats['total_graded'];
            $totalWinnerCorrect += $stats['winner_correct'];
            $spreadErrorSum += $stats['spread_error_sum'];
            $spreadErrorSamples += $stats['spread_error_samples'];
            $totalErrorSum += $stats['total_error_sum'];
            $totalErrorSamples += $stats['total_error_samples'];
        }

        return [
            'total_predictions' => $totalGraded,
            'winner_accuracy' => $totalGraded > 0 ? round(($totalWinnerCorrect / $totalGraded) * 100, 1) : 0,
            'avg_spread_error' => $spreadErrorSamples > 0 ? round($spreadErrorSum / $spreadErrorSamples, 2) : null,
            'avg_total_error' => $totalErrorSamples > 0 ? round($totalErrorSum / $totalErrorSamples, 2) : null,
            'win_record' => "{$totalWinnerCorrect}-".($totalGraded - $totalWinnerCorrect),
            'winner_sample_size' => $totalGraded,
            'spread_sample_size' => $spreadErrorSamples,
            'total_sample_size' => $totalErrorSamples,
        ];
    }

    /**
     * Get performance statistics by sport.
     */
    public function getStatsBySport(?string $fromDate = null, ?string $toDate = null): array
    {
        $results = [];

        foreach (self::SPORT_LABELS as $key => $label) {
            $stats = $this->getSportStats($key, $fromDate, $toDate);

            if ($stats['total_graded'] > 0) {
                $results[$key] = $this->sportSummary($label, $stats, includeErrorMetrics: true);
            }
        }

        return $results;
    }

    /**
     * Get statistics for a specific sport.
     */
    protected function getSportStats(
        string $sport,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $season = null,
    ): array {
        $memoKey = implode('|', [$sport, $fromDate, $toDate, $season]);
        if (isset($this->sportStatsMemo[$memoKey])) {
            return $this->sportStatsMemo[$memoKey];
        }

        $table = "{$sport}_predictions";
        $gamesTable = "{$sport}_games";

        $query = DB::table($table)
            ->join($gamesTable, "{$table}.game_id", '=', "{$gamesTable}.id")
            ->whereNotNull("{$table}.graded_at");

        if ($fromDate) {
            $query->whereDate("{$gamesTable}.game_date", '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate("{$gamesTable}.game_date", '<=', $toDate);
        }

        if ($season !== null) {
            $query->where("{$gamesTable}.season", $season);
        }

        $row = $query->selectRaw("
            COUNT(CASE WHEN {$table}.winner_correct IS NOT NULL THEN 1 END) AS winner_samples,
            SUM(CASE WHEN {$table}.winner_correct = 1 THEN 1 ELSE 0 END) AS winner_correct,
            COUNT({$table}.spread_error) AS spread_error_samples,
            COALESCE(SUM({$table}.spread_error), 0) AS spread_error_sum,
            COUNT({$table}.total_error) AS total_error_samples,
            COALESCE(SUM({$table}.total_error), 0) AS total_error_sum
        ")->first();

        return $this->sportStatsMemo[$memoKey] = [
            'total_graded' => (int) ($row->winner_samples ?? 0),
            'winner_correct' => (int) ($row->winner_correct ?? 0),
            'spread_error_samples' => (int) ($row->spread_error_samples ?? 0),
            'spread_error_sum' => (float) ($row->spread_error_sum ?? 0),
            'total_error_samples' => (int) ($row->total_error_samples ?? 0),
            'total_error_sum' => (float) ($row->total_error_sum ?? 0),
        ];
    }

    /**
     * Calculate verified ROI from immutable, pregame-safe decisions and settlements.
     */
    public function calculateROI(?string $fromDate = null, ?string $toDate = null): array
    {
        $query = DB::table('bet_decisions')
            ->join('bet_settlements', 'bet_settlements.bet_decision_id', '=', 'bet_decisions.id')
            ->where('bet_decisions.is_bet', true)
            ->where('bet_decisions.pregame_safe', true);

        if ($fromDate) {
            $query->whereDate('bet_decisions.decided_at', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('bet_decisions.decided_at', '<=', $toDate);
        }

        $row = $query->selectRaw("
            COUNT(*) AS total_bets,
            SUM(CASE WHEN bet_settlements.result_status = 'win' THEN 1 ELSE 0 END) AS total_wins,
            SUM(CASE WHEN bet_settlements.result_status = 'loss' THEN 1 ELSE 0 END) AS total_losses,
            SUM(CASE WHEN bet_settlements.result_status = 'push' THEN 1 ELSE 0 END) AS total_pushes,
            COALESCE(SUM(bet_settlements.profit_units), 0) AS total_profit_units,
            AVG(bet_settlements.clv) AS avg_clv
        ")->first();

        $totalBets = (int) ($row->total_bets ?? 0);
        $totalWins = (int) ($row->total_wins ?? 0);
        $totalLosses = (int) ($row->total_losses ?? 0);
        $totalPushes = (int) ($row->total_pushes ?? 0);
        $gradedDecisions = $totalWins + $totalLosses;
        $totalProfitUnits = round((float) ($row->total_profit_units ?? 0), 4);
        $totalWagered = $totalBets * 100;
        $totalProfit = round($totalProfitUnits * 100, 2);
        $roi = $totalWagered > 0 ? round(($totalProfit / $totalWagered) * 100, 2) : null;

        return [
            'total_bets' => $totalBets,
            'total_wins' => $totalWins,
            'total_losses' => $totalLosses,
            'total_pushes' => $totalPushes,
            'total_staked_units' => $totalBets,
            'total_wagered' => $totalWagered,
            'total_profit' => $totalProfit,
            'total_profit_units' => $totalProfitUnits,
            'roi_percentage' => $roi,
            'win_percentage' => $gradedDecisions > 0 ? round(($totalWins / $gradedDecisions) * 100, 1) : null,
            'avg_clv' => $row->avg_clv !== null ? round((float) $row->avg_clv, 4) : null,
            'verified' => true,
            'methodology' => 'settled_pregame_bet_decisions',
        ];
    }

    /**
     * Get recent performance (last 30 days).
     */
    public function getRecentPerformance(): array
    {
        $toDate = Carbon::now()->toDateString();
        $fromDate = Carbon::now()->subDays(29)->toDateString();

        return [
            'overall' => $this->getOverallStats($fromDate, $toDate),
            'by_sport' => $this->getStatsBySport($fromDate, $toDate),
            'roi' => $this->calculateROI($fromDate, $toDate),
        ];
    }

    /**
     * Get season-to-date performance.
     */
    public function getSeasonToDate(): array
    {
        $results = [];

        foreach (self::SPORTS as $sport) {
            $season = $this->latestGradedSeason($sport);
            if ($season === null) {
                continue;
            }

            $stats = $this->getSportStats($sport, season: $season);

            if ($stats['total_graded'] > 0) {
                $results[$sport] = [
                    ...$this->sportSummary(self::SPORT_LABELS[$sport], $stats),
                    'season' => $season,
                ];
            }
        }

        return $results;
    }

    private function latestGradedSeason(string $sport): ?int
    {
        if (array_key_exists($sport, $this->latestGradedSeasonMemo)) {
            return $this->latestGradedSeasonMemo[$sport];
        }

        $table = "{$sport}_predictions";
        $gamesTable = "{$sport}_games";
        $season = DB::table($table)
            ->join($gamesTable, "{$table}.game_id", '=', "{$gamesTable}.id")
            ->whereNotNull("{$table}.graded_at")
            ->whereNotNull("{$table}.winner_correct")
            ->max("{$gamesTable}.season");

        return $this->latestGradedSeasonMemo[$sport] = is_numeric($season)
            ? (int) $season
            : null;
    }

    /**
     * @param  array{total_graded:int,winner_correct:int,spread_error_samples:int,spread_error_sum:float,total_error_samples:int,total_error_sum:float}  $stats
     * @return array<string, mixed>
     */
    private function sportSummary(string $label, array $stats, bool $includeErrorMetrics = false): array
    {
        $summary = [
            'label' => $label,
            'total_graded' => $stats['total_graded'],
            'winner_correct' => $stats['winner_correct'],
            'winner_accuracy' => round(($stats['winner_correct'] / $stats['total_graded']) * 100, 1),
            'win_record' => "{$stats['winner_correct']}-".($stats['total_graded'] - $stats['winner_correct']),
            'winner_sample_size' => $stats['total_graded'],
        ];

        if (! $includeErrorMetrics) {
            return $summary;
        }

        return array_merge($summary, [
            'avg_spread_error' => $stats['spread_error_samples'] > 0
                ? round($stats['spread_error_sum'] / $stats['spread_error_samples'], 2)
                : null,
            'spread_sample_size' => $stats['spread_error_samples'],
            'avg_total_error' => $stats['total_error_samples'] > 0
                ? round($stats['total_error_sum'] / $stats['total_error_samples'], 2)
                : null,
            'total_sample_size' => $stats['total_error_samples'],
        ]);
    }
}
