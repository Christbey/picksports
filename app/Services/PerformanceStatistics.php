<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PerformanceStatistics
{
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
        $spreadErrors = [];
        $totalErrors = [];

        foreach (self::SPORTS as $sport) {
            $stats = $this->getSportStats($sport, $fromDate, $toDate);
            $totalGraded += $stats['total_graded'];
            $totalWinnerCorrect += $stats['winner_correct'];
            $spreadErrors = array_merge($spreadErrors, $stats['spread_errors']);
            $totalErrors = array_merge($totalErrors, $stats['total_errors']);
        }

        return [
            'total_predictions' => $totalGraded,
            'winner_accuracy' => $totalGraded > 0 ? round(($totalWinnerCorrect / $totalGraded) * 100, 1) : 0,
            'avg_spread_error' => !empty($spreadErrors) ? round(array_sum($spreadErrors) / count($spreadErrors), 2) : 0,
            'avg_total_error' => !empty($totalErrors) ? round(array_sum($totalErrors) / count($totalErrors), 2) : 0,
            'win_record' => "{$totalWinnerCorrect}-" . ($totalGraded - $totalWinnerCorrect),
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
    protected function getSportStats(string $sport, ?string $fromDate = null, ?string $toDate = null): array
    {
        $table = "{$sport}_predictions";
        $gamesTable = "{$sport}_games";

        $query = DB::table($table)
            ->join($gamesTable, "{$table}.game_id", '=', "{$gamesTable}.id")
            ->whereNotNull("{$table}.graded_at")
            ->whereNotNull("{$table}.actual_spread")
            ->whereNotNull("{$table}.actual_total");

        if ($fromDate) {
            $query->where("{$gamesTable}.game_date", '>=', $fromDate);
        }

        if ($toDate) {
            $query->where("{$gamesTable}.game_date", '<=', $toDate);
        }

        $predictions = $query->select([
            "{$table}.winner_correct",
            "{$table}.spread_error",
            "{$table}.total_error",
        ])->get();

        return [
            'total_graded' => $predictions->count(),
            'winner_correct' => $predictions->where('winner_correct', true)->count(),
            'spread_errors' => $predictions->pluck('spread_error')->filter()->toArray(),
            'total_errors' => $predictions->pluck('total_error')->filter()->toArray(),
        ];
    }

    /**
     * Calculate ROI if user followed all predictions with $100 bets.
     * Assumes standard -110 betting odds.
     */
    public function calculateROI(?string $fromDate = null, ?string $toDate = null): array
    {
        $totalBets = 0;
        $totalWins = 0;

        foreach (self::SPORTS as $sport) {
            $stats = $this->getSportStats($sport, $fromDate, $toDate);
            $totalBets += $stats['total_graded'];
            $totalWins += $stats['winner_correct'];
        }

        // Standard bet: $100 at -110 odds
        // Win pays: $90.91 profit ($100 stake + $90.91 = $190.91 total)
        // Loss: -$100
        $betAmount = 100;
        $winProfit = 90.91; // -110 odds

        $totalWagered = $totalBets * $betAmount;
        $totalProfit = ($totalWins * ($betAmount + $winProfit)) - $totalWagered;
        $roi = $totalWagered > 0 ? round(($totalProfit / $totalWagered) * 100, 2) : 0;

        return [
            'total_bets' => $totalBets,
            'total_wins' => $totalWins,
            'total_losses' => $totalBets - $totalWins,
            'total_wagered' => $totalWagered,
            'total_profit' => round($totalProfit, 2),
            'roi_percentage' => $roi,
            'win_percentage' => $totalBets > 0 ? round(($totalWins / $totalBets) * 100, 1) : 0,
        ];
    }

    /**
     * Get recent performance (last 30 days).
     */
    public function getRecentPerformance(): array
    {
        $toDate = Carbon::now()->toDateString();
        $fromDate = Carbon::now()->subDays(30)->toDateString();

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
        // Define season start dates for each sport
        $seasonStarts = [
            'nfl' => Carbon::create(null, 9, 1)->toDateString(), // September 1
            'cbb' => Carbon::create(null, 11, 1)->toDateString(), // November 1
            'nba' => Carbon::create(null, 10, 1)->toDateString(), // October 1
            'wcbb' => Carbon::create(null, 11, 1)->toDateString(), // November 1
            'wnba' => Carbon::create(null, 5, 1)->toDateString(), // May 1
            'mlb' => Carbon::create(null, 3, 1)->toDateString(), // March 1
            'cfb' => Carbon::create(null, 8, 1)->toDateString(), // August 1
        ];

        $results = [];

        foreach ($seasonStarts as $sport => $startDate) {
            $stats = $this->getSportStats($sport, $startDate, Carbon::now()->toDateString());

            if ($stats['total_graded'] > 0) {
                $results[$sport] = $this->sportSummary(self::SPORT_LABELS[$sport], $stats);
            }
        }

        return $results;
    }

    /**
     * @param  array{total_graded:int,winner_correct:int,spread_errors:array<int, mixed>,total_errors:array<int, mixed>}  $stats
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
        ];

        if (! $includeErrorMetrics) {
            return $summary;
        }

        return array_merge($summary, [
            'avg_spread_error' => ! empty($stats['spread_errors'])
                ? round(array_sum($stats['spread_errors']) / count($stats['spread_errors']), 2)
                : 0,
            'avg_total_error' => ! empty($stats['total_errors'])
                ? round(array_sum($stats['total_errors']) / count($stats['total_errors']), 2)
                : 0,
        ]);
    }
}
