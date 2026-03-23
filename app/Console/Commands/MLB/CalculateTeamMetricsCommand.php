<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\CalculateTeamMetrics;
use App\Console\Commands\Sports\AbstractCalculateTeamMetricsCommand;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use Illuminate\Support\Facades\DB;

class CalculateTeamMetricsCommand extends AbstractCalculateTeamMetricsCommand
{
    protected const COMMAND_NAME = 'mlb:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate MLB team metrics (ratings, run profile, OBP/SLG/OPS, K/G, WHIP)';

    protected const CALCULATE_METRICS_ACTION_CLASS = CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['location', 'name'];

    protected function topTeamsTitle(): string
    {
        return 'Top 10 Teams by Offensive Rating:';
    }

    protected function topRatingColumn(): string
    {
        return 'offensive_rating';
    }

    protected function topTableHeaders(): array
    {
        return ['Rank', 'Team', 'Off Rtg', 'Pitch Rtg', 'Def Rtg', 'R/G', 'RA/G', 'OPS', 'WHIP', 'SOS'];
    }

    protected function topTableFields(): array
    {
        return [
            'offensive_rating' => 2,
            'pitching_rating' => 2,
            'defensive_rating' => 2,
            'runs_per_game' => 2,
            'runs_allowed_per_game' => 2,
            'ops' => 3,
            'whip' => 3,
            'strength_of_schedule' => 3,
        ];
    }

    protected function displayTeamMetric(TeamMetric $metric): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Offensive Rating', round($metric->offensive_rating, 2)],
                ['Pitching Rating', round($metric->pitching_rating, 2)],
                ['Defensive Rating', round($metric->defensive_rating, 2)],
                ['Runs Per Game', round($metric->runs_per_game, 2)],
                ['Runs Allowed Per Game', round($metric->runs_allowed_per_game, 2)],
                ['Run Differential/Game', round((float) ($metric->run_differential_per_game ?? 0), 2)],
                ['Home Runs/Game', round((float) ($metric->home_runs_per_game ?? 0), 2)],
                ['Batting Average', round($metric->batting_average, 3)],
                ['On-Base Percentage', round((float) ($metric->on_base_percentage ?? 0), 3)],
                ['Slugging Percentage', round((float) ($metric->slugging_percentage ?? 0), 3)],
                ['OPS', round((float) ($metric->ops ?? 0), 3)],
                ['Team ERA', round($metric->team_era, 2)],
                ['Strikeouts Pitched/Game', round((float) ($metric->strikeouts_pitched_per_game ?? 0), 2)],
                ['WHIP', round((float) ($metric->whip ?? 0), 3)],
                ['Strength of Schedule', $metric->strength_of_schedule ?? 'N/A'],
                ['Calculation Date', $metric->calculation_date->format('Y-m-d')],
            ]
        );
    }

    protected function displayNoRecalculationDiagnostics(int|string $season): void
    {
        $seasonValue = (int) $season;
        $finalStatus = (string) config('mlb.statuses.final');
        $configuredAnalyticsTypes = config('mlb.season.analytics_types', []);
        $analyticsCandidates = $this->resolveAnalyticsTypeCandidates();

        $baseQuery = DB::table('mlb_games')->where('season', $seasonValue);
        $totalGames = (clone $baseQuery)->count();
        $finalGames = (clone $baseQuery)->where('status', $finalStatus)->count();
        $finalAnalyticsGames = (clone $baseQuery)
            ->where('status', $finalStatus)
            ->when(
                $analyticsCandidates !== [],
                fn ($query) => $query->whereIn('season_type', $analyticsCandidates)
            )
            ->count();

        $this->newLine();
        $this->warn("MLB diagnostics for season {$seasonValue}:");
        $this->line('Configured final status: '.json_encode($finalStatus));
        $this->line('Configured analytics_types: '.json_encode($configuredAnalyticsTypes));
        $this->line('Resolved analytics season_type candidates: '.json_encode($analyticsCandidates));
        $this->line("Counts => total season games: {$totalGames}, final-status games: {$finalGames}, final+analytics-matched games: {$finalAnalyticsGames}");

        $statusBreakdown = (clone $baseQuery)
            ->select('status', DB::raw('COUNT(*) as games'))
            ->groupBy('status')
            ->orderByDesc('games')
            ->get();

        if ($statusBreakdown->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Status', 'Games'],
                $statusBreakdown->map(fn ($row) => [
                    (string) $row->status,
                    (int) $row->games,
                ])->all()
            );
        }

        $seasonTypeBreakdown = (clone $baseQuery)
            ->select('season_type', DB::raw('COUNT(*) as games'))
            ->groupBy('season_type')
            ->orderByDesc('games')
            ->get();

        if ($seasonTypeBreakdown->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Season Type', 'Games'],
                $seasonTypeBreakdown->map(fn ($row) => [
                    (string) ($row->season_type ?? 'NULL'),
                    (int) $row->games,
                ])->all()
            );
        }
    }

    /**
     * @return array<int, int|string>
     */
    private function resolveAnalyticsTypeCandidates(): array
    {
        $configuredTypes = config('mlb.season.analytics_types');
        if (! is_array($configuredTypes) || $configuredTypes === []) {
            return [];
        }

        $typeNames = config('mlb.season.type_names', []);
        $typesByKey = config('mlb.season.types', []);
        $candidates = [];

        foreach ($configuredTypes as $type) {
            if ($type === null || $type === '') {
                continue;
            }

            $candidates[] = $type;
            $candidates[] = (string) $type;

            if (is_string($type) && isset($typeNames[$type])) {
                $candidates[] = $typeNames[$type];
            }

            if (is_string($type) && isset($typesByKey[$type])) {
                $resolved = $typesByKey[$type];
                $candidates[] = $resolved;
                $candidates[] = (string) $resolved;
            }

            if (is_numeric($type)) {
                $code = (int) $type;
                $matchedKey = array_search($code, $typesByKey, true);
                if ($matchedKey !== false && isset($typeNames[$matchedKey])) {
                    $candidates[] = $typeNames[$matchedKey];
                }
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($value) => $value !== null && $value !== ''
        )));
    }
}
