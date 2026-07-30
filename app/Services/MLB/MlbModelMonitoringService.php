<?php

namespace App\Services\MLB;

use App\Models\BetDecision;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\EvaluationReportNormalizer;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class MlbModelMonitoringService
{
    public function __construct(
        private readonly ModelArtifactRegistry $artifacts,
        private readonly EvaluationReportNormalizer $reports,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?string $artifactId = null): array
    {
        $currentSeason = $this->currentSeason();
        $artifacts = ModelArtifact::query()
            ->with('trainingRun')
            ->where('sport', 'mlb')
            ->latest('created_at')
            ->get();
        $artifact = $this->selectArtifact($artifacts, $artifactId);
        $active = $artifacts->firstWhere('status', 'promoted');
        $challenger = $artifacts->first(
            fn (ModelArtifact $item): bool => $item->status !== 'promoted'
        );
        $outputs = $artifact
            ? ShadowModelOutput::query()
                ->with(['featureSnapshot', 'inferenceRun', 'betDecisions.settlement'])
                ->where('model_artifact_id', $artifact->id)
                ->latest('generated_at')
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();
        $metricOutputs = $artifact
            ? ShadowModelOutput::query()
                ->with('featureSnapshot')
                ->where('model_artifact_id', $artifact->id)
                ->whereIn(
                    'game_id',
                    Game::query()->select('id')->where('season', $currentSeason),
                )
                ->get()
            : collect();
        $decisions = $this->decisionQuery($artifact, $currentSeason)
            ->with(['settlement', 'shadowOutput.featureSnapshot'])
            ->get();
        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereIn('id', $outputs->pluck('game_id')->unique()->values())
            ->get()
            ->keyBy('id');
        $report = $artifact ? $this->evaluationReport($artifact) : [];

        return [
            'artifacts' => $artifacts
                ->map(fn (ModelArtifact $item): array => $this->artifactOption($item))
                ->values()
                ->all(),
            'artifact' => $artifact
                ? $this->artifactPayload($artifact, $report, $outputs)
                : null,
            'lineage' => [
                'active' => $active ? $this->artifactLineage($active) : null,
                'challenger' => $challenger ? $this->artifactLineage($challenger) : null,
            ],
            'data_health' => $this->dataHealth($currentSeason),
            'summary' => $this->summary($artifact, $decisions),
            'market_performance' => $this->marketPerformance($decisions),
            'weekly_performance' => $this->weeklyPerformance($metricOutputs),
            'evaluation_windows' => array_values((array) ($report['windows'] ?? [])),
            'observations' => $outputs
                ->map(fn (ShadowModelOutput $output): array => $this->observation(
                    $output,
                    $games->get($output->game_id),
                ))
                ->values()
                ->all(),
            'no_bet_reasons' => $this->noBetReasons($decisions),
            'inference_failures' => $this->recentInferenceFailures(),
        ];
    }

    /**
     * @param  Collection<int, ModelArtifact>  $artifacts
     */
    private function selectArtifact(Collection $artifacts, ?string $artifactId): ?ModelArtifact
    {
        if ($artifactId) {
            $selected = $artifacts->firstWhere('id', $artifactId);
            if ($selected instanceof ModelArtifact) {
                return $selected;
            }
        }

        return $artifacts->firstWhere('status', 'promoted') ?? $artifacts->first();
    }

    /**
     * @return Builder<BetDecision>
     */
    private function decisionQuery(?ModelArtifact $artifact, int $season): Builder
    {
        return BetDecision::query()
            ->where('sport', 'mlb')
            ->whereIn(
                'game_id',
                Game::query()
                    ->select('id')
                    ->where('season', $season),
            )
            ->when(
                $artifact,
                fn (Builder $query) => $query->where('model_artifact_id', $artifact->id),
                fn (Builder $query) => $query->whereNotNull('model_artifact_id'),
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactOption(ModelArtifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'model_version' => $artifact->model_version,
            'model_type' => $artifact->model_type,
            'market_type' => $artifact->market_type,
            'status' => $artifact->status,
            'created_at' => $artifact->created_at?->toIso8601String(),
            'promoted_at' => $artifact->promoted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactLineage(ModelArtifact $artifact): array
    {
        return [
            ...$this->artifactOption($artifact),
            'training_run_id' => $artifact->training_run_id,
            'feature_version' => $artifact->feature_version,
            'artifact_hash' => $artifact->artifact_hash,
            'dataset_hash' => $artifact->dataset_hash,
            'config_hash' => $artifact->trainingRun?->config_hash,
            'code_version' => $artifact->trainingRun?->code_version,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  Collection<int, ShadowModelOutput>  $outputs
     * @return array<string, mixed>
     */
    private function artifactPayload(
        ModelArtifact $artifact,
        array $report,
        Collection $outputs,
    ): array {
        $promotionDecision = (array) $artifact->promotion_decision;

        return [
            ...$this->artifactLineage($artifact),
            'sport' => $artifact->sport,
            'evaluation_report_hash' => $artifact->evaluation_report_hash,
            'run_type' => $artifact->trainingRun?->run_type,
            'metrics' => $artifact->metrics,
            'evaluation_summary' => (array) ($report['summary'] ?? []),
            'promotion_checks' => (array) ($promotionDecision['checks'] ?? []),
            'promotion_markets' => (array) ($promotionDecision['markets'] ?? []),
            'promoted_markets' => $artifact->promotedMarkets(),
            'promotion_summary' => (array) ($report['promotion_summary'] ?? []),
            'delta_convention' => (array) ($report['delta_convention'] ?? []),
            'public_output_changed' => $outputs->contains(
                fn (ShadowModelOutput $output): bool => (bool) data_get(
                    $output->explanation,
                    'public_output_changed',
                    false,
                )
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dataHealth(int $currentSeason): array
    {
        $upcoming = Game::query()
            ->where('season', $currentSeason)
            ->whereDate('game_date', '>=', today())
            ->whereDate('game_date', '<=', today()->copy()->addDays(7))
            ->whereNotIn('status', ['STATUS_FINAL', 'final', 'Final'])
            ->get([
                'id',
                'probable_home_pitcher_espn_id',
                'probable_away_pitcher_espn_id',
            ]);
        $upcomingIds = $upcoming->pluck('id');
        $pregameSnapshots = PredictionFeatureSnapshot::query()
            ->where('sport', 'mlb')
            ->where('pregame_safe', true)
            ->where('availability_status', 'observed_pregame')
            ->whereIn('game_id', $upcomingIds);
        $quotes = MarketQuote::query()
            ->where('sport', 'mlb')
            ->where('game_table', 'mlb_games')
            ->where('is_pregame', true)
            ->whereIn('game_id', $upcomingIds);
        $quotedGameIds = (clone $quotes)
            ->distinct()
            ->pluck('game_id');
        $pitcherReady = $upcoming->filter(
            fn (Game $game): bool => filled($game->probable_home_pitcher_espn_id)
                && filled($game->probable_away_pitcher_espn_id)
        )->count();
        $marketCoverage = (clone $quotes)
            ->selectRaw('market_key, COUNT(*) as quote_count, COUNT(DISTINCT game_id) as game_count')
            ->groupBy('market_key')
            ->orderBy('market_key')
            ->get()
            ->map(fn (MarketQuote $quote): array => [
                'market' => $this->marketLabel($quote->market_key),
                'quote_count' => (int) $quote->getAttribute('quote_count'),
                'game_count' => (int) $quote->getAttribute('game_count'),
            ])
            ->values()
            ->all();
        $latestSnapshot = (clone $pregameSnapshots)->max('generated_at');
        $latestQuote = (clone $quotes)->max('captured_at');

        return [
            'season' => $currentSeason,
            'pregame_safe_snapshots' => (clone $pregameSnapshots)->count(),
            'latest_pregame_snapshot_at' => $this->dateString($latestSnapshot),
            'snapshot_age_hours' => $this->ageHours($latestSnapshot),
            'pregame_market_quotes' => (clone $quotes)->count(),
            'latest_market_quote_at' => $this->dateString($latestQuote),
            'quote_age_hours' => $this->ageHours($latestQuote),
            'upcoming_games' => $upcoming->count(),
            'probable_pitchers_ready' => $pitcherReady,
            'probable_pitcher_coverage' => $this->ratio($pitcherReady, $upcoming->count()),
            'games_with_market_quotes' => $quotedGameIds->count(),
            'market_quote_coverage' => $this->ratio($quotedGameIds->count(), $upcoming->count()),
            'market_coverage' => $marketCoverage,
        ];
    }

    /**
     * @param  Collection<int, BetDecision>  $decisions
     * @return array<string, int|float|null>
     */
    private function summary(?ModelArtifact $artifact, Collection $decisions): array
    {
        $settled = $decisions->filter(
            fn (BetDecision $decision): bool => $decision->settlement !== null
        );
        $settledBets = $settled->where('is_bet', true);
        $profit = (float) $settledBets->sum(
            fn (BetDecision $decision): float => (float) $decision->settlement?->profit_units
        );
        $clvRows = $settled->filter(
            fn (BetDecision $decision): bool => $decision->settlement?->clv !== null
        );
        $calibration = $this->calibration($settled);

        return [
            'shadow_observations' => $artifact
                ? ShadowModelOutput::query()->where('model_artifact_id', $artifact->id)->count()
                : 0,
            'decisions' => $decisions->count(),
            'tracking_bets' => $decisions->where('is_bet', true)->count(),
            'no_bets' => $decisions->where('is_bet', false)->count(),
            'settled_decisions' => $settled->count(),
            'pending_decisions' => $decisions->count() - $settled->count(),
            'profit_units' => $profit,
            'roi' => $settledBets->isEmpty() ? null : $profit / $settledBets->count(),
            'average_clv' => $clvRows->isEmpty()
                ? null
                : (float) $clvRows->avg(
                    fn (BetDecision $decision): float => (float) $decision->settlement?->clv
                ),
            ...$calibration,
        ];
    }

    /**
     * @param  Collection<int, BetDecision>  $decisions
     * @return list<array<string, mixed>>
     */
    private function marketPerformance(Collection $decisions): array
    {
        return $decisions
            ->groupBy(fn (BetDecision $decision): string => $this->marketLabel($decision->market_type))
            ->map(function (Collection $marketDecisions, string $market): array {
                $settled = $marketDecisions->filter(
                    fn (BetDecision $decision): bool => $decision->settlement !== null
                );
                $settledBets = $settled->where('is_bet', true);
                $profit = (float) $settledBets->sum(
                    fn (BetDecision $decision): float => (float) $decision->settlement?->profit_units
                );
                $clvRows = $settled->filter(
                    fn (BetDecision $decision): bool => $decision->settlement?->clv !== null
                );

                return [
                    'market' => $market,
                    'decisions' => $marketDecisions->count(),
                    'bets' => $marketDecisions->where('is_bet', true)->count(),
                    'no_bets' => $marketDecisions->where('is_bet', false)->count(),
                    'settled' => $settled->count(),
                    'pending' => $marketDecisions->count() - $settled->count(),
                    'profit_units' => $profit,
                    'roi' => $settledBets->isEmpty() ? null : $profit / $settledBets->count(),
                    'average_clv' => $clvRows->isEmpty()
                        ? null
                        : (float) $clvRows->avg(
                            fn (BetDecision $decision): float => (float) $decision->settlement?->clv
                        ),
                    ...$this->calibration($settled),
                ];
            })
            ->sortBy('market')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, BetDecision>  $settled
     * @return array<string, int|float|null>
     */
    private function calibration(Collection $settled): array
    {
        $rows = $settled
            ->filter(fn (BetDecision $decision): bool => $decision->shadowOutput !== null
                && in_array($this->marketLabel($decision->market_type), ['Moneyline', 'Win Probability'], true)
                && $decision->settlement?->result_value !== null)
            ->values();

        if ($rows->isEmpty()) {
            return [
                'calibration_games' => 0,
                'baseline_brier' => null,
                'challenger_brier' => null,
                'brier_delta' => null,
            ];
        }

        $baselineBrier = (float) $rows->avg(function (BetDecision $decision): float {
            $target = (float) $decision->settlement?->result_value > 0 ? 1.0 : 0.0;

            return ((float) $decision->shadowOutput?->baseline_output - $target) ** 2;
        });
        $challengerBrier = (float) $rows->avg(function (BetDecision $decision): float {
            $target = (float) $decision->settlement?->result_value > 0 ? 1.0 : 0.0;

            return ((float) $decision->shadowOutput?->challenger_output - $target) ** 2;
        });

        return [
            'calibration_games' => $rows->count(),
            'baseline_brier' => round($baselineBrier, 12),
            'challenger_brier' => round($challengerBrier, 12),
            'brier_delta' => round($baselineBrier - $challengerBrier, 12),
        ];
    }

    /**
     * @param  Collection<int, ShadowModelOutput>  $outputs
     * @return list<array<string, mixed>>
     */
    private function weeklyPerformance(Collection $outputs): array
    {
        $games = Game::query()
            ->whereIn('id', $outputs->pluck('game_id')->unique()->values())
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->get()
            ->keyBy('id');

        return $outputs
            ->filter(fn (ShadowModelOutput $output): bool => $games->has($output->game_id))
            ->sortByDesc('generated_at')
            ->unique('game_id')
            ->groupBy(fn (ShadowModelOutput $output): string => $output->generated_at
                ?->copy()
                ->startOfWeek()
                ->toDateString() ?? 'Unknown')
            ->map(function (Collection $weekOutputs, string $weekStart) use ($games): array {
                $rows = $weekOutputs->map(function (ShadowModelOutput $output) use ($games): array {
                    $game = $games->get($output->game_id);
                    $snapshotOutputs = (array) $output->featureSnapshot?->outputs;
                    $explanation = (array) $output->explanation;
                    $baselineProbability = $this->floatOrNull(
                        data_get($explanation, 'baseline_outputs.home_win_probability')
                            ?? data_get($explanation, 'baseline_outputs.win_probability')
                            ?? ($snapshotOutputs['baseline_win_probability'] ?? null)
                            ?? ($output->market_type === 'win_probability' ? $output->baseline_output : null)
                    );
                    $challengerProbability = $this->floatOrNull(
                        data_get($explanation, 'challenger_outputs.home_win_probability')
                            ?? data_get($explanation, 'challenger_outputs.win_probability')
                            ?? ($snapshotOutputs['challenger_win_probability'] ?? null)
                            ?? ($output->market_type === 'win_probability' ? $output->challenger_output : null)
                    );

                    return [
                        'target' => (float) $game->home_score > (float) $game->away_score ? 1.0 : 0.0,
                        'actual_margin' => (float) $game->home_score - (float) $game->away_score,
                        'actual_total' => (float) $game->home_score + (float) $game->away_score,
                        'baseline_probability' => $baselineProbability,
                        'challenger_probability' => $challengerProbability,
                        'baseline_margin' => $this->floatOrNull(
                            data_get($explanation, 'baseline_outputs.predicted_spread')
                                ?? data_get($explanation, 'baseline_outputs.expected_home_margin')
                                ?? ($snapshotOutputs['baseline_predicted_spread'] ?? null)
                        ),
                        'challenger_margin' => $this->floatOrNull(
                            data_get($explanation, 'challenger_outputs.predicted_spread')
                                ?? data_get($explanation, 'challenger_outputs.expected_home_margin')
                                ?? ($snapshotOutputs['challenger_predicted_spread'] ?? null)
                        ),
                        'baseline_total' => $this->floatOrNull(
                            data_get($explanation, 'baseline_outputs.predicted_total')
                                ?? data_get($explanation, 'baseline_outputs.expected_total')
                                ?? ($snapshotOutputs['baseline_predicted_total'] ?? null)
                        ),
                        'challenger_total' => $this->floatOrNull(
                            data_get($explanation, 'challenger_outputs.predicted_total')
                                ?? data_get($explanation, 'challenger_outputs.expected_total')
                                ?? ($snapshotOutputs['challenger_predicted_total'] ?? null)
                        ),
                    ];
                });
                $probabilityRows = $rows->filter(
                    fn (array $row): bool => $row['baseline_probability'] !== null
                        && $row['challenger_probability'] !== null
                );
                $marginRows = $rows->filter(
                    fn (array $row): bool => $row['baseline_margin'] !== null
                        && $row['challenger_margin'] !== null
                );
                $totalRows = $rows->filter(
                    fn (array $row): bool => $row['baseline_total'] !== null
                        && $row['challenger_total'] !== null
                );

                return [
                    'week_start' => $weekStart,
                    'games' => $rows->count(),
                    'baseline_brier' => $this->averageMetric(
                        $probabilityRows,
                        fn (array $row): float => ($row['baseline_probability'] - $row['target']) ** 2,
                    ),
                    'challenger_brier' => $this->averageMetric(
                        $probabilityRows,
                        fn (array $row): float => ($row['challenger_probability'] - $row['target']) ** 2,
                    ),
                    'baseline_log_loss' => $this->averageMetric(
                        $probabilityRows,
                        fn (array $row): float => $this->logLoss($row['baseline_probability'], $row['target']),
                    ),
                    'challenger_log_loss' => $this->averageMetric(
                        $probabilityRows,
                        fn (array $row): float => $this->logLoss($row['challenger_probability'], $row['target']),
                    ),
                    'baseline_accuracy' => $this->averageMetric(
                        $probabilityRows,
                        fn (array $row): float => ($row['baseline_probability'] >= 0.5) === ($row['target'] === 1.0) ? 1.0 : 0.0,
                    ),
                    'challenger_accuracy' => $this->averageMetric(
                        $probabilityRows,
                        fn (array $row): float => ($row['challenger_probability'] >= 0.5) === ($row['target'] === 1.0) ? 1.0 : 0.0,
                    ),
                    'baseline_margin_mae' => $this->averageMetric(
                        $marginRows,
                        fn (array $row): float => abs($row['baseline_margin'] - $row['actual_margin']),
                    ),
                    'challenger_margin_mae' => $this->averageMetric(
                        $marginRows,
                        fn (array $row): float => abs($row['challenger_margin'] - $row['actual_margin']),
                    ),
                    'baseline_total_mae' => $this->averageMetric(
                        $totalRows,
                        fn (array $row): float => abs($row['baseline_total'] - $row['actual_total']),
                    ),
                    'challenger_total_mae' => $this->averageMetric(
                        $totalRows,
                        fn (array $row): float => abs($row['challenger_total'] - $row['actual_total']),
                    ),
                ];
            })
            ->sortByDesc('week_start')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(ShadowModelOutput $output, ?Game $game): array
    {
        $decision = $output->betDecisions->sortByDesc('id')->first();
        $settlement = $decision?->settlement;
        $snapshot = $output->featureSnapshot;

        return [
            'id' => $output->id,
            'game_id' => $output->game_id,
            'matchup' => trim(
                ($game?->awayTeam?->abbreviation ?? 'Away')
                .' @ '
                .($game?->homeTeam?->abbreviation ?? 'Home')
            ),
            'game_date' => $game?->game_date?->toDateString(),
            'market_type' => $this->marketLabel($output->market_type),
            'baseline_output' => (float) $output->baseline_output,
            'challenger_output' => (float) $output->challenger_output,
            'output_delta' => (float) $output->output_delta,
            'status' => $output->status,
            'generated_at' => $output->generated_at?->toIso8601String(),
            'pregame_safe' => (bool) $snapshot?->pregame_safe,
            'feature_hash' => $snapshot?->feature_hash,
            'inference_run_id' => $output->inference_run_id,
            'decision' => $decision ? [
                'status' => $decision->status,
                'side' => $decision->side,
                'price' => $decision->price,
                'edge' => $this->floatOrNull($decision->edge),
                'is_bet' => (bool) $decision->is_bet,
                'is_public' => (bool) $decision->is_public,
                'is_tracking_only' => (bool) $decision->is_tracking_only,
                'reasons' => array_values((array) $decision->eligibility_reasons),
            ] : null,
            'settlement' => $settlement ? [
                'status' => $settlement->result_status,
                'profit_units' => $this->floatOrNull($settlement->profit_units),
                'clv' => $this->floatOrNull($settlement->clv),
                'settled_at' => $settlement->settled_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, BetDecision>  $decisions
     * @return list<array{reason:string,count:int}>
     */
    private function noBetReasons(Collection $decisions): array
    {
        return $decisions
            ->where('is_bet', false)
            ->flatMap(fn (BetDecision $decision): array => (array) $decision->eligibility_reasons)
            ->filter()
            ->countBy()
            ->sortDesc()
            ->map(fn (int $count, string $reason): array => [
                'reason' => $reason,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentInferenceFailures(): array
    {
        return ModelRun::query()
            ->where('sport', 'mlb')
            ->where('run_type', 'like', '%inference%')
            ->whereIn('status', ['failed', 'failure', 'error'])
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (ModelRun $run): array => [
                'id' => $run->id,
                'run_type' => $run->run_type,
                'model_version' => $run->model_version,
                'feature_version' => $run->feature_version,
                'status' => $run->status,
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'error' => data_get($run->metadata, 'error')
                    ?? data_get($run->metadata, 'message')
                    ?? data_get($run->metadata, 'stderr')
                    ?? 'No error message recorded.',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluationReport(ModelArtifact $artifact): array
    {
        try {
            $path = $this->artifacts->materializeEvaluationReport($artifact);
        } catch (\RuntimeException) {
            return [];
        }

        $report = json_decode((string) File::get($path), true);

        return is_array($report) ? $this->reports->normalize($report) : [];
    }

    private function marketLabel(?string $market): string
    {
        return match (ModelArtifact::normalizeMarketType((string) $market)) {
            'win_probability' => 'Moneyline',
            'spread' => 'Run Line',
            'total' => 'Total',
            'multi_market' => 'Multi-Market',
            default => str((string) $market)->replace('_', ' ')->title()->toString(),
        };
    }

    private function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : $numerator / $denominator;
    }

    private function currentSeason(): int
    {
        return (int) (Game::query()->max('season') ?: now()->year);
    }

    private function dateString(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function ageHours(mixed $value): ?float
    {
        return filled($value)
            ? round(Carbon::parse($value)->diffInMinutes(now(), true) / 60, 2)
            : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function logLoss(float $probability, float $target): float
    {
        $probability = max(0.000001, min(0.999999, $probability));

        return -($target * log($probability) + (1 - $target) * log(1 - $probability));
    }

    private function averageMetric(Collection $rows, callable $metric): ?float
    {
        return $rows->isEmpty() ? null : round((float) $rows->avg($metric), 12);
    }
}
