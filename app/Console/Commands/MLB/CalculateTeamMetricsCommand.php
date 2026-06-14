<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\CalculateTeamMetrics;
use App\Console\Commands\Sports\AbstractCalculateTeamMetricsCommand;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CalculateTeamMetricsCommand extends AbstractCalculateTeamMetricsCommand
{
    protected const COMMAND_NAME = 'mlb:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate MLB team metrics (ratings, run profile, OBP/SLG/OPS, K/G, WHIP)';

    protected const CALCULATE_METRICS_ACTION_CLASS = CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['location', 'name'];

    private ?string $activeSeasonType = null;

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--season= : Calculate metrics for a specific season (defaults to current year)}\n {--team= : Calculate metrics for a specific team ID}\n {--season-type= : Limit MLB team metrics to a specific season type}",
            $this->commandName()
        );
    }

    public function handle(): int
    {
        $calculateMetrics = app($this->calculateMetricsActionClass());
        $season = (int) ($this->option('season') ?? date('Y'));
        $seasonTypes = $this->seasonTypesForRun($season);

        if ($seasonTypes === []) {
            $this->info("Calculating metrics for all teams ({$season})...");
            $this->warn('No completed MLB games found for the requested season type(s).');
            $this->displayNoRecalculationDiagnostics($season);

            return self::SUCCESS;
        }

        $singleTeamResult = $this->handleSingleTeamMetricsCalculation(
            $season,
            function (Model $team, int|string $seasonValue) use ($calculateMetrics, $seasonTypes) {
                $metrics = collect();

                foreach ($seasonTypes as $seasonType) {
                    $metric = $calculateMetrics->execute($team, (int) $seasonValue, $seasonType);
                    if ($metric) {
                        $metrics->push($metric);
                    }
                }

                if ($metrics->isEmpty()) {
                    return null;
                }

                foreach ($metrics as $metric) {
                    $this->line('Season Type: '.(string) $metric->season_type);
                    $this->displayTeamMetric($metric);
                }

                return $metrics->first();
            },
            fn (Model $team) => $this->teamDisplayName($team)
        );
        if ($singleTeamResult !== null) {
            return $singleTeamResult;
        }

        $this->info("Calculating metrics for all teams ({$season})...");

        $teamModelClass = $this->teamModelClass();
        $teams = $this->modifyTeamsQuery($teamModelClass::query(), $season)->get();
        $calculated = 0;
        $attempted = 0;
        $bar = $this->output->createProgressBar($teams->count() * count($seasonTypes));
        $bar->start();

        foreach ($teams as $team) {
            foreach ($seasonTypes as $seasonType) {
                $attempted++;
                if ($calculateMetrics->execute($team, $season, $seasonType)) {
                    $calculated++;
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Recalculated metrics for {$calculated} of {$attempted} team/season-type combinations.");

        $this->activeSeasonType = $this->displaySeasonType($seasonTypes);

        $this->newLine();
        $this->info($this->topTeamsTitle());

        $this->displayTopTeamsByRating(
            $season,
            $this->teamMetricModelClass(),
            $this->topRatingColumn(),
            10,
            [
                'headers' => $this->topTableHeaders(),
                'fields' => $this->topTableFields(),
            ]
        );

        return self::SUCCESS;
    }

    protected function topTeamsTitle(): string
    {
        return 'Top 10 Teams by Offense+:';
    }

    protected function topRatingColumn(): string
    {
        return 'offensive_rating';
    }

    protected function topTableHeaders(): array
    {
        return ['Rank', 'Team', 'Off+', 'Pitch+', 'Field+', 'R/G', 'RA/G', 'OPS', 'WHIP', 'SOS Elo'];
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
                ['Season Type', $metric->season_type ?? 'N/A'],
                ['Offense+', round($metric->offensive_rating, 2)],
                ['Pitching+', round($metric->pitching_rating, 2)],
                ['Fielding+', round($metric->defensive_rating, 2)],
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

    protected function modifyTopTeamsQuery(mixed $query, int|string $season): mixed
    {
        if ($this->activeSeasonType !== null) {
            $query->where('season_type', $this->activeSeasonType);
        }

        return $query;
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

    /**
     * @return array<int, string>
     */
    private function seasonTypesForRun(int $season): array
    {
        $requestedSeasonType = $this->option('season-type');

        if (is_string($requestedSeasonType) && trim($requestedSeasonType) !== '') {
            return [trim($requestedSeasonType)];
        }

        return DB::table('mlb_games')
            ->where('season', $season)
            ->where('status', config('mlb.statuses.final'))
            ->whereNotNull('season_type')
            ->distinct()
            ->orderBy('season_type')
            ->pluck('season_type')
            ->map(fn ($seasonType) => (string) $seasonType)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $seasonTypes
     */
    private function displaySeasonType(array $seasonTypes): ?string
    {
        $requestedSeasonType = $this->option('season-type');
        if (is_string($requestedSeasonType) && trim($requestedSeasonType) !== '') {
            return trim($requestedSeasonType);
        }

        $regularSeasonType = (string) config('mlb.season.types.regular', 2);

        if (in_array($regularSeasonType, $seasonTypes, true)) {
            return $regularSeasonType;
        }

        return $seasonTypes[0] ?? null;
    }
}
