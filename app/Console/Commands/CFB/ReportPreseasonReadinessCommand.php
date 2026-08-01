<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ReportPreseasonReadinessCommand extends Command
{
    protected $signature = 'cfb:report-preseason-readiness
        {--season= : Upcoming/current season to inspect; defaults to cfb.season.default}
        {--from-season= : First season for signal coverage/backtest; defaults to --season}
        {--to-season= : Last season for signal coverage/backtest; defaults to --season}
        {--limit=0 : Limit most recent final predictions for backtest, 0 means all}
        {--sample-missing=5 : Number of missing teams/games to include per section}
        {--json : Output the report as JSON}
        {--output= : Optional JSON report output path}
        {--fail-on-warnings : Return failure when Week 0/1 readiness warnings are present}';

    protected $description = 'Report CFB preseason signal coverage, early-week readiness, and week-bucket backtest metrics';

    /**
     * @var array<string, array<string, list<string>>>
     */
    private const SIGNAL_SOURCES = [
        'player_availability' => [
            'cfb_player_injuries' => [
                'status',
                'detail',
                'type',
                'raw_payload',
            ],
            'cfb_game_context_signals' => [
                'home_player_availability_score',
                'away_player_availability_score',
                'home_qb_availability_score',
                'away_qb_availability_score',
            ],
        ],
        'weather_venue' => [
            'cfb_game_weather' => [
                'temperature_f',
                'wind_speed_mph',
                'wind_gust_mph',
                'precipitation_inches',
                'precipitation_probability',
                'condition_code',
            ],
            'cfb_game_context_signals' => [
                'temperature_f',
                'wind_speed_mph',
                'wind_gust_mph',
                'precipitation_inches',
                'weather_condition',
            ],
        ],
        'rating_consensus' => [
            'cfb_team_metrics' => [
                'rating_consensus',
                'rating_consensus_sources',
                'fpi',
                'power_rating',
                'cfbd_wepa_net',
            ],
            'cfb_game_context_signals' => [
                'home_rating_consensus',
                'away_rating_consensus',
            ],
        ],
        'explosiveness_success' => [
            'cfb_team_metrics' => [
                'offensive_success_rate',
                'defensive_success_rate',
                'net_success_rate',
                'offensive_explosiveness',
                'defensive_explosiveness',
                'net_explosiveness',
                'offensive_havoc_rate',
                'defensive_havoc_rate',
                'net_havoc_rate',
            ],
            'cfb_game_context_signals' => [
                'home_explosiveness_score',
                'away_explosiveness_score',
            ],
        ],
        'line_qb_environment' => [
            'cfb_team_metrics' => [
                'offensive_line_rating',
                'qb_environment_rating',
                'defensive_front_rating',
            ],
            'cfb_preseason_team_signals' => [
                'transfer_qb_net_value',
                'transfer_ol_net_value',
                'returning_percent_passing_ppa',
                'qb_continuity_classification',
            ],
            'cfb_game_context_signals' => [
                'home_line_qb_score',
                'away_line_qb_score',
            ],
        ],
        'market_movement' => [
            'cfb_game_context_signals' => [
                'opening_home_spread',
                'current_home_spread',
                'closing_home_spread',
                'consensus_home_spread',
            ],
        ],
        'schedule_context' => [
            'cfb_game_context_signals' => [
                'home_rest_days',
                'away_rest_days',
                'schedule_context_payload',
            ],
        ],
        'returning_production' => [
            'cfb_preseason_team_signals' => [
                'returning_percent_ppa',
                'returning_percent_passing_ppa',
                'returning_percent_rushing_ppa',
                'returning_percent_receiving_ppa',
                'returning_usage',
                'returning_production_payload',
            ],
            'cfb_team_preseason_signals' => [
                'returning_production_share',
                'returning_production_percent_ppa',
                'returning_production_percent_passing_ppa',
                'returning_production_percent_rushing_ppa',
                'returning_production_percent_receiving_ppa',
            ],
            'cfb_returning_production' => [
                'percent_ppa',
                'percent_passing_ppa',
                'percent_rushing_ppa',
                'percent_receiving_ppa',
                'percent_usage',
            ],
            'cfb_team_metrics' => [
                'returning_production_share',
                'returning_production_percent_ppa',
                'cfbd_returning_production_payload',
            ],
        ],
        'portal_talent' => [
            'cfb_preseason_team_signals' => [
                'transfer_portal_payload',
                'talent_composite',
                'recruiting_points',
                'talent_payload',
                'recruiting_payload',
            ],
            'cfb_team_preseason_signals' => [
                'portal_net_rating',
                'portal_net_score',
                'transfer_portal_net',
                'talent_rating',
                'talent_composite',
                'recruiting_talent',
            ],
            'cfb_transfer_portal' => [
                'portal_net_rating',
                'portal_net_score',
                'transfer_rating',
                'rating',
                'stars',
            ],
            'cfb_team_talent' => [
                'talent',
                'talent_rating',
                'talent_composite',
                'composite_rating',
            ],
            'cfb_recruiting_talent' => [
                'talent',
                'talent_rating',
                'talent_composite',
                'recruiting_talent',
            ],
        ],
        'qb_continuity' => [
            'cfb_preseason_team_signals' => [
                'qb_continuity_classification',
                'qb_continuity_confidence',
                'projected_starting_qb_name',
                'qb_continuity_payload',
            ],
            'cfb_team_preseason_signals' => [
                'qb_continuity_signal',
                'returning_starting_qb',
                'starting_qb_status',
                'qb_experience_score',
                'qb_transfer_status',
            ],
            'cfb_qb_continuity' => [
                'qb_continuity_signal',
                'returning_starting_qb',
                'starting_qb_status',
                'qb_experience_score',
                'qb_transfer_status',
            ],
            'cfb_depth_chart_snapshots' => [
                'starting_qb_id',
                'starting_qb_name',
                'qb_experience_score',
            ],
        ],
        'coaching_continuity' => [
            'cfb_preseason_team_signals' => [
                'new_head_coach',
                'new_offensive_coordinator',
                'new_defensive_coordinator',
                'coordinator_continuity_score',
                'head_coach_name',
                'offensive_coordinator_name',
                'defensive_coordinator_name',
                'coaching_continuity_payload',
            ],
            'cfb_team_preseason_signals' => [
                'coach_continuity_signal',
                'head_coach_continuity',
                'new_head_coach',
                'head_coach',
                'offensive_coordinator',
                'defensive_coordinator',
            ],
            'cfb_team_coach_seasons' => [
                'coach_id',
                'head_coach_id',
                'head_coach',
                'offensive_coordinator',
                'defensive_coordinator',
            ],
            'cfb_coaches' => [
                'coach_id',
                'head_coach',
                'display_name',
            ],
        ],
        'coaching_scheme_detail' => [
            'cfb_preseason_team_signals' => [
                'coaching_continuity_payload',
            ],
            'cfb_team_preseason_signals' => [
                'scheme_continuity_score',
                'scheme_fit_score',
                'scheme_change_score',
                'offensive_scheme_change',
                'defensive_scheme_change',
                'tempo_change_score',
            ],
            'cfb_team_coach_seasons' => [
                'scheme_continuity_score',
                'scheme_change_score',
                'offensive_scheme',
                'defensive_scheme',
                'tempo_change_score',
            ],
        ],
        'special_teams' => [
            'cfb_fpi_ratings' => [
                'special_teams',
                'special_teams_fpi',
            ],
            'cfb_team_metrics' => [
                'special_teams',
                'special_teams_rating',
                'special_teams_fpi',
            ],
        ],
    ];

    private const CORE_METRIC_SOURCES = [
        'cfb_team_metrics' => [
            'fpi',
            'cfbd_wepa_net',
            'net_rating',
            'power_rating',
            'offensive_true_epa_per_play',
            'defensive_true_epa_per_play',
        ],
        'cfb_fpi_ratings' => [
            'fpi',
            'fpi_rating',
            'offense',
            'defense',
            'special_teams',
        ],
    ];

    /**
     * Context families can be sparse before lines/weather are published. Track
     * them in coverage, but only these preseason roster/program signals should
     * block Week 0/1 readiness.
     *
     * @var list<string>
     */
    private const EARLY_WEEK_REQUIRED_SIGNAL_FAMILIES = [
        'returning_production',
        'portal_talent',
        'qb_continuity',
        'coaching_continuity',
        'coaching_scheme_detail',
        'special_teams',
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $columnCache = [];

    public function handle(): int
    {
        $season = (int) ($this->option('season') ?: config('cfb.season.default'));
        $fromSeason = (int) ($this->option('from-season') ?: $season);
        $toSeason = (int) ($this->option('to-season') ?: $season);

        if ($fromSeason > $toSeason) {
            $this->error('--from-season must be less than or equal to --to-season.');

            return self::FAILURE;
        }

        $seasons = range($fromSeason, $toSeason);
        $coverage = collect($seasons)
            ->mapWithKeys(fn (int $reportSeason): array => [
                (string) $reportSeason => $this->signalCoverageForSeason($reportSeason),
            ])
            ->all();

        $backtestRows = $this->loadBacktestRows($fromSeason, $toSeason);
        $earlyReadiness = $this->earlyWeekReadiness($season);
        $report = [
            'report_type' => 'cfb_preseason_readiness',
            'season' => $season,
            'from_season' => $fromSeason,
            'to_season' => $toSeason,
            'generated_at' => now()->toIso8601String(),
            'signal_coverage' => $coverage,
            'backtest' => $this->summarizeBacktest($backtestRows),
            'spread_convention' => $this->spreadConventionReport($season),
            'early_week_readiness' => $earlyReadiness,
        ];

        if ($output = $this->option('output')) {
            $path = (string) $output;
            $directory = dirname($path);
            if (! is_dir($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->readinessExitCode($earlyReadiness);
        }

        $this->renderReport($report);

        return $this->readinessExitCode($earlyReadiness);
    }

    /**
     * @return array<string, mixed>
     */
    private function signalCoverageForSeason(int $season): array
    {
        $teamIds = $this->trackedTeamIds($season);
        $teams = Team::query()
            ->whereIn('id', $teamIds)
            ->get()
            ->keyBy('id');

        $families = [];
        foreach (self::SIGNAL_SOURCES as $family => $sources) {
            $coveredIds = $this->coveredTeamIds($sources, $season, $teamIds);
            $missingIds = collect($teamIds)->diff($coveredIds)->values();

            $families[$family] = [
                'tracked_teams' => count($teamIds),
                'covered_teams' => $coveredIds->count(),
                'coverage_pct' => $this->pct($coveredIds->count(), count($teamIds)),
                'detected_sources' => $this->detectedSources($sources),
                'missing_sample' => $missingIds
                    ->take($this->sampleLimit())
                    ->map(fn (int $teamId): string => $this->teamName($teams->get($teamId)))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'season' => $season,
            'tracked_teams' => count($teamIds),
            'families' => $families,
        ];
    }

    /**
     * @return list<int>
     */
    private function trackedTeamIds(int $season): array
    {
        $gameTeamIds = Game::query()
            ->where('season', $season)
            ->get(['home_team_id', 'away_team_id'])
            ->flatMap(fn (Game $game): array => [
                (int) $game->home_team_id,
                (int) $game->away_team_id,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($gameTeamIds !== []) {
            return $gameTeamIds;
        }

        return Team::query()
            ->where('division', config('cfb.teams.divisions.fbs', 'FBS'))
            ->pluck('id')
            ->map(fn (mixed $teamId): int => (int) $teamId)
            ->all();
    }

    /**
     * @param  array<string, list<string>>  $sources
     * @param  list<int>  $teamIds
     * @return Collection<int, int>
     */
    private function coveredTeamIds(array $sources, int $season, array $teamIds): Collection
    {
        if ($teamIds === []) {
            return collect();
        }

        $covered = collect();

        foreach ($sources as $table => $valueColumns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = $this->columns($table);
            $teamColumn = $this->firstExistingColumn($columns, [
                'team_id',
                'cfb_team_id',
                'destination_team_id',
                'school_id',
            ]);
            $gameTeamColumns = [
                'home' => in_array('home_team_id', $columns, true) ? 'home_team_id' : null,
                'away' => in_array('away_team_id', $columns, true) ? 'away_team_id' : null,
            ];

            if ($teamColumn === null && ($gameTeamColumns['home'] === null || $gameTeamColumns['away'] === null)) {
                continue;
            }

            $seasonColumn = $this->firstExistingColumn($columns, ['season', 'year']);
            $existingValueColumns = array_values(array_intersect($valueColumns, $columns));

            $query = DB::table($table);

            if ($teamColumn !== null) {
                $query->whereIn($teamColumn, $teamIds);
            } else {
                $query->where(function ($query) use ($teamIds, $gameTeamColumns): void {
                    $query->whereIn((string) $gameTeamColumns['home'], $teamIds)
                        ->orWhereIn((string) $gameTeamColumns['away'], $teamIds);
                });
            }

            if ($seasonColumn !== null) {
                $query->where($seasonColumn, $season);
            }

            if ($existingValueColumns !== []) {
                $query->where(function ($query) use ($existingValueColumns): void {
                    foreach ($existingValueColumns as $column) {
                        if ($column === 'qb_continuity_classification') {
                            $query->orWhere(function ($query) use ($column): void {
                                $query->whereNotNull($column)->where($column, '!=', 'unknown');
                            });

                            continue;
                        }

                        $query->orWhereNotNull($column);
                    }
                });
            }

            if ($teamColumn !== null) {
                $covered = $covered
                    ->merge($query->pluck($teamColumn)->map(fn (mixed $teamId): int => (int) $teamId))
                    ->unique()
                    ->values();

                continue;
            }

            $covered = $covered
                ->merge($query->pluck((string) $gameTeamColumns['home'])->map(fn (mixed $teamId): int => (int) $teamId))
                ->merge($query->pluck((string) $gameTeamColumns['away'])->map(fn (mixed $teamId): int => (int) $teamId))
                ->unique()
                ->values();
        }

        return $covered;
    }

    /**
     * @param  array<string, list<string>>  $sources
     * @return list<array{table:string,columns:list<string>}>
     */
    private function detectedSources(array $sources): array
    {
        $detected = [];

        foreach ($sources as $table => $valueColumns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = $this->columns($table);
            $existingValueColumns = array_values(array_intersect($valueColumns, $columns));

            if ($existingValueColumns === []) {
                continue;
            }

            $detected[] = [
                'table' => $table,
                'columns' => $existingValueColumns,
            ];
        }

        return $detected;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadBacktestRows(int $fromSeason, int $toSeason): Collection
    {
        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('predicted_spread')
            ->whereNotNull('win_probability')
            ->whereHas('game', function ($query) use ($fromSeason, $toSeason): void {
                $query->whereBetween('season', [$fromSeason, $toSeason])
                    ->whereNotNull('week')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');
            })
            ->latest();

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(function (Prediction $prediction): ?array {
                $game = $prediction->game;
                if (! $game) {
                    return null;
                }

                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $predictedSpread = (float) $prediction->predicted_spread;
                $modelPickedHome = $predictedSpread >= 0.0;
                $modelWinnerCorrect = $modelPickedHome ? $actualMargin > 0.0 : $actualMargin < 0.0;
                $favoriteProbability = max((float) $prediction->win_probability, 1.0 - (float) $prediction->win_probability);

                return [
                    'game_id' => (int) $game->id,
                    'season' => (int) $game->season,
                    'week' => (int) $game->week,
                    'week_bucket' => $this->weekBucket((int) $game->week),
                    'predicted_spread' => $predictedSpread,
                    'actual_margin' => $actualMargin,
                    'spread_error' => is_numeric($prediction->spread_error)
                        ? (float) $prediction->spread_error
                        : abs($actualMargin - $predictedSpread),
                    'winner_correct' => $prediction->winner_correct === null
                        ? $modelWinnerCorrect
                        : (bool) $prediction->winner_correct,
                    'favorite_probability' => $favoriteProbability,
                    'favorite_won' => $modelWinnerCorrect,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarizeBacktest(Collection $rows): array
    {
        $buckets = [];

        foreach (['week_0_1', 'week_2_4', 'week_5_8', 'week_9_plus'] as $bucket) {
            $bucketRows = $rows->where('week_bucket', $bucket)->values();

            $buckets[$bucket] = [
                'count' => $bucketRows->count(),
                'winner_accuracy' => $this->pct($bucketRows->where('winner_correct', true)->count(), $bucketRows->count()),
                'spread_mae' => $bucketRows->isEmpty() ? null : round((float) $bucketRows->avg('spread_error'), 2),
                'calibration_buckets' => $this->calibrationBuckets($bucketRows),
            ];
        }

        return [
            'count' => $rows->count(),
            'winner_accuracy' => $this->pct($rows->where('winner_correct', true)->count(), $rows->count()),
            'spread_mae' => $rows->isEmpty() ? null : round((float) $rows->avg('spread_error'), 2),
            'week_buckets' => $buckets,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, int|float|string|null>>
     */
    private function calibrationBuckets(Collection $rows): array
    {
        $buckets = [
            '50-59' => fn (float $probability): bool => $probability >= 0.50 && $probability < 0.60,
            '60-69' => fn (float $probability): bool => $probability >= 0.60 && $probability < 0.70,
            '70-79' => fn (float $probability): bool => $probability >= 0.70 && $probability < 0.80,
            '80-89' => fn (float $probability): bool => $probability >= 0.80 && $probability < 0.90,
            '90+' => fn (float $probability): bool => $probability >= 0.90,
        ];

        $calibration = [];
        foreach ($buckets as $label => $filter) {
            $group = $rows
                ->filter(fn (array $row): bool => $filter((float) $row['favorite_probability']))
                ->values();

            if ($group->isEmpty()) {
                continue;
            }

            $avgProbability = round((float) $group->avg('favorite_probability') * 100, 1);
            $actualRate = $this->pct($group->where('favorite_won', true)->count(), $group->count());

            $calibration[] = [
                'bucket' => $label,
                'count' => $group->count(),
                'avg_model_probability' => $avgProbability,
                'actual_win_rate' => $actualRate,
                'calibration_error' => round($actualRate - $avgProbability, 1),
            ];
        }

        return $calibration;
    }

    /**
     * @return array<string, mixed>
     */
    private function earlyWeekReadiness(int $season): array
    {
        $teamIds = $this->trackedTeamIds($season);
        $coveredByFamily = [];
        foreach (array_intersect_key(self::SIGNAL_SOURCES, array_flip(self::EARLY_WEEK_REQUIRED_SIGNAL_FAMILIES)) as $family => $sources) {
            $coveredByFamily[$family] = $this->coveredTeamIds($sources, $season, $teamIds)->all();
        }
        $coreMetricTeams = $this->coveredTeamIds(self::CORE_METRIC_SOURCES, $season, $teamIds)->all();

        $warnings = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('predicted_spread')
            ->whereHas('game', function ($query) use ($season): void {
                $query->where('season', $season)
                    ->whereIn('week', [0, 1])
                    ->where('status', '!=', config('cfb.statuses.final', 'STATUS_FINAL'));
            })
            ->get()
            ->map(function (Prediction $prediction) use ($coveredByFamily, $coreMetricTeams): ?array {
                $game = $prediction->game;
                if (! $game) {
                    return null;
                }

                $homeTeamId = (int) $game->home_team_id;
                $awayTeamId = (int) $game->away_team_id;
                $missingFamilies = [];
                $availableFamilies = [];

                foreach ($coveredByFamily as $family => $coveredTeamIds) {
                    $homeCovered = in_array($homeTeamId, $coveredTeamIds, true);
                    $awayCovered = in_array($awayTeamId, $coveredTeamIds, true);

                    if ($homeCovered && $awayCovered) {
                        $availableFamilies[] = $family;
                    } else {
                        $missingFamilies[] = $family;
                    }
                }

                $hasCoreMetrics = $this->predictionHasCoreMetrics($prediction)
                    || (in_array($homeTeamId, $coreMetricTeams, true) && in_array($awayTeamId, $coreMetricTeams, true));
                $hasAnyPreseasonFamily = $availableFamilies !== [];
                $eloOnly = ! $hasCoreMetrics && ! $hasAnyPreseasonFamily && $prediction->home_elo !== null && $prediction->away_elo !== null;

                if (! $eloOnly && $missingFamilies === []) {
                    return null;
                }

                return [
                    'game_id' => (int) $game->id,
                    'week' => (int) $game->week,
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'elo_only' => $eloOnly,
                    'available_preseason_signal_families' => $availableFamilies,
                    'missing_preseason_signal_families' => $missingFamilies,
                    'reason' => $eloOnly
                        ? 'week_0_1_prediction_relies_only_on_elo'
                        : 'week_0_1_prediction_missing_preseason_signal_family',
                ];
            })
            ->filter()
            ->values();

        return [
            'season' => $season,
            'weeks' => [0, 1],
            'warning_count' => $warnings->count(),
            'elo_only_count' => $warnings->where('elo_only', true)->count(),
            'warnings' => $warnings->take($this->sampleLimit())->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spreadConventionReport(int $season): array
    {
        $samples = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('predicted_spread')
            ->whereHas('game', fn ($query) => $query->where('season', $season))
            ->latest()
            ->limit($this->sampleLimit())
            ->get()
            ->map(function (Prediction $prediction): array {
                $game = $prediction->game;
                $predictedSpread = (float) $prediction->predicted_spread;
                $homeLine = round(-$predictedSpread, 1);

                return [
                    'game_id' => (int) ($game?->id ?? 0),
                    'game' => $this->teamName($game?->awayTeam).' @ '.$this->teamName($game?->homeTeam),
                    'predicted_spread_home_margin' => round($predictedSpread, 1),
                    'ui_home_line' => $homeLine,
                    'home_line_formula' => 'ui_home_line = -predicted_spread',
                    'home_team_is_model_favorite' => $predictedSpread > 0,
                    'home_team_is_sportsbook_favorite' => $homeLine < 0,
                ];
            })
            ->values()
            ->all();

        return [
            'backend_predicted_spread_convention' => 'home_margin_positive_home_favored',
            'ui_home_line_convention' => 'sportsbook_home_line_negative_home_favored',
            'home_line_formula' => 'ui_home_line = -predicted_spread',
            'samples' => $samples,
        ];
    }

    private function predictionHasCoreMetrics(Prediction $prediction): bool
    {
        return is_numeric($prediction->home_fpi)
            || is_numeric($prediction->away_fpi);
    }

    private function readinessExitCode(array $earlyReadiness): int
    {
        if ((bool) $this->option('fail-on-warnings') && (int) $earlyReadiness['warning_count'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->info('CFB Preseason Readiness Report');
        $this->line("Season: {$report['season']} | Historical scope: {$report['from_season']}-{$report['to_season']}");
        $this->newLine();

        $this->info('Signal Coverage');
        foreach ($report['signal_coverage'] as $season => $coverage) {
            $rows = [];
            foreach ($coverage['families'] as $family => $familyCoverage) {
                $rows[] = [
                    $family,
                    (string) $familyCoverage['covered_teams'].'/'.$familyCoverage['tracked_teams'],
                    number_format((float) $familyCoverage['coverage_pct'], 1).'%',
                    collect($familyCoverage['detected_sources'])
                        ->map(fn (array $source): string => $source['table'].($source['columns'] === [] ? '' : ':'.implode(',', $source['columns'])))
                        ->implode('; ') ?: 'missing storage',
                ];
            }

            $this->line("Season {$season}");
            $this->table(['Family', 'Covered', 'Coverage', 'Detected source'], $rows);
        }

        $this->info('Backtest By Week Bucket');
        $this->table(
            ['Bucket', 'Games', 'Winner %', 'Spread MAE'],
            collect($report['backtest']['week_buckets'])
                ->map(fn (array $bucket, string $label): array => [
                    $label,
                    (string) $bucket['count'],
                    number_format((float) $bucket['winner_accuracy'], 1).'%',
                    $bucket['spread_mae'] === null ? '-' : number_format((float) $bucket['spread_mae'], 2),
                ])
                ->values()
                ->all()
        );

        $warningCount = (int) $report['early_week_readiness']['warning_count'];
        if ($warningCount > 0) {
            $this->warn("Week 0/1 readiness warnings: {$warningCount}");
            $this->table(
                ['Game', 'Week', 'Reason', 'Missing families'],
                collect($report['early_week_readiness']['warnings'])
                    ->map(fn (array $warning): array => [
                        $warning['game'],
                        (string) $warning['week'],
                        $warning['reason'],
                        implode(', ', $warning['missing_preseason_signal_families']),
                    ])
                    ->all()
            );
        } else {
            $this->info('Week 0/1 readiness warnings: 0');
        }

        if ($this->option('output')) {
            $this->newLine();
            $this->info('Wrote report to '.(string) $this->option('output'));
        }
    }

    private function weekBucket(int $week): string
    {
        return match (true) {
            $week <= 1 => 'week_0_1',
            $week <= 4 => 'week_2_4',
            $week <= 8 => 'week_5_8',
            default => 'week_9_plus',
        };
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $candidates
     */
    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        if (! array_key_exists($table, $this->columnCache)) {
            $this->columnCache[$table] = Schema::hasTable($table)
                ? Schema::getColumnListing($table)
                : [];
        }

        return $this->columnCache[$table];
    }

    private function pct(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }

    private function sampleLimit(): int
    {
        return max(1, (int) $this->option('sample-missing'));
    }

    private function teamName(mixed $team): string
    {
        if (! $team) {
            return 'Unknown';
        }

        $school = trim((string) ($team->school ?? $team->location ?? ''));
        $mascot = trim((string) ($team->mascot ?? $team->name ?? ''));
        $fullName = trim("{$school} {$mascot}");

        return $fullName !== '' ? $fullName : (string) ($team->abbreviation ?? 'UNK');
    }
}
