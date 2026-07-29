<?php

namespace App\Services\NFL;

use App\Models\BetDecision;
use App\Models\ModelArtifact;
use App\Models\NFL\Game;
use App\Models\ShadowModelOutput;
use App\Services\ML\EvaluationReportNormalizer;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class NflModelMonitoringService
{
    /**
     * @var array<string, string>
     */
    private const SIGNAL_GRADE_CATEGORIES = [
        'reason_code' => 'Reason Codes',
        'risk_flag' => 'Risk Flags',
        'matched_rule' => 'Matched Rules',
        'pass_rule' => 'Pass Rules',
        'validated_combo' => 'Validated Combinations',
    ];

    public function __construct(
        private readonly ModelArtifactRegistry $artifacts,
        private readonly NflSignalGradeReportService $signalGrades,
        private readonly EvaluationReportNormalizer $reports,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?string $artifactId = null): array
    {
        $artifacts = ModelArtifact::query()
            ->with('trainingRun')
            ->where('sport', 'nfl')
            ->latest('created_at')
            ->get();
        $artifact = $this->selectArtifact($artifacts, $artifactId);

        if (! $artifact) {
            return [
                'artifacts' => [],
                'artifact' => null,
                'summary' => $this->emptySummary(),
                'observations' => [],
                'no_bet_reasons' => [],
                'evaluation_windows' => [],
                'signal_grades' => $this->signalGradePayload(),
            ];
        }

        $outputsQuery = ShadowModelOutput::query()
            ->where('model_artifact_id', $artifact->id);
        $shadowObservationCount = (clone $outputsQuery)->count();
        $outputs = $outputsQuery
            ->with([
                'featureSnapshot',
                'inferenceRun',
                'betDecisions.settlement',
            ])
            ->latest('generated_at')
            ->latest('id')
            ->limit(100)
            ->get();
        $decisions = BetDecision::query()
            ->with(['settlement', 'shadowOutput'])
            ->where('model_artifact_id', $artifact->id)
            ->get();
        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereIn('id', $outputs->pluck('game_id')->unique()->values())
            ->get()
            ->keyBy('id');
        $report = $this->evaluationReport($artifact);

        return [
            'artifacts' => $artifacts->map(fn (ModelArtifact $item): array => [
                'id' => $item->id,
                'model_version' => $item->model_version,
                'model_type' => $item->model_type,
                'market_type' => $item->market_type,
                'status' => $item->status,
                'created_at' => $item->created_at?->toIso8601String(),
                'promoted_at' => $item->promoted_at?->toIso8601String(),
            ])->values()->all(),
            'artifact' => $this->artifactPayload($artifact, $report, $outputs),
            'summary' => $this->summary($shadowObservationCount, $decisions),
            'observations' => $outputs
                ->map(fn (ShadowModelOutput $output): array => $this->observation($output, $games->get($output->game_id)))
                ->values()
                ->all(),
            'no_bet_reasons' => $this->noBetReasons($decisions),
            'evaluation_windows' => array_values((array) ($report['windows'] ?? [])),
            'signal_grades' => $this->signalGradePayload(),
        ];
    }

    /**
     * @return list<array{
     *     signal_type:string,
     *     label:string,
     *     signals:list<array<string,mixed>>,
     *     windows:list<array<string,mixed>>
     * }>
     */
    private function signalGradePayload(): array
    {
        return collect(self::SIGNAL_GRADE_CATEGORIES)
            ->map(function (string $label, string $signalType): array {
                $report = $this->signalGrades->report([
                    'signal_type' => $signalType,
                    'pregame_safe' => true,
                    'limit' => 25,
                ]);

                return [
                    'signal_type' => $signalType,
                    'label' => $label,
                    'signals' => $report['signals'],
                    'windows' => $report['windows'],
                ];
            })
            ->values()
            ->all();
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
     * @param  array<string, mixed>  $report
     * @param  Collection<int, ShadowModelOutput>  $outputs
     * @return array<string, mixed>
     */
    private function artifactPayload(ModelArtifact $artifact, array $report, Collection $outputs): array
    {
        $trainingRun = $artifact->trainingRun;
        $promotionDecision = (array) $artifact->promotion_decision;

        return [
            'id' => $artifact->id,
            'sport' => $artifact->sport,
            'market_type' => $artifact->market_type,
            'model_type' => $artifact->model_type,
            'model_version' => $artifact->model_version,
            'feature_version' => $artifact->feature_version,
            'status' => $artifact->status,
            'artifact_hash' => $artifact->artifact_hash,
            'dataset_hash' => $artifact->dataset_hash,
            'evaluation_report_hash' => $artifact->evaluation_report_hash,
            'training_run_id' => $artifact->training_run_id,
            'config_hash' => $trainingRun?->config_hash,
            'code_version' => $trainingRun?->code_version,
            'run_type' => $trainingRun?->run_type,
            'promoted_at' => $artifact->promoted_at?->toIso8601String(),
            'created_at' => $artifact->created_at?->toIso8601String(),
            'metrics' => $artifact->metrics,
            'evaluation_summary' => (array) ($report['summary'] ?? []),
            'promotion_checks' => (array) ($promotionDecision['checks'] ?? []),
            'promotion_markets' => (array) ($promotionDecision['markets'] ?? []),
            'promoted_markets' => $artifact->promotedMarkets(),
            'promotion_summary' => (array) ($report['promotion_summary'] ?? []),
            'delta_convention' => (array) ($report['delta_convention'] ?? []),
            'public_output_changed' => $outputs->contains(
                fn (ShadowModelOutput $output): bool => (bool) data_get($output->explanation, 'public_output_changed', false)
            ),
        ];
    }

    /**
     * @param  Collection<int, BetDecision>  $decisions
     * @return array<string, int|float|null>
     */
    private function summary(int $shadowObservationCount, Collection $decisions): array
    {
        $settled = $decisions->filter(fn (BetDecision $decision): bool => $decision->settlement !== null);
        $settledBets = $settled->filter(fn (BetDecision $decision): bool => $decision->is_bet);
        $actualProfit = (float) $settledBets->sum(
            fn (BetDecision $decision): float => (float) $decision->settlement?->profit_units
        );
        $counterfactualProfit = (float) $settled->sum(
            fn (BetDecision $decision): float => (float) data_get($decision->settlement?->metadata, 'shadow_profit_units', 0.0)
        );
        $clvRows = $settled->filter(fn (BetDecision $decision): bool => $decision->settlement?->clv !== null);
        $calibration = $this->calibration($settled);

        return [
            'shadow_observations' => $shadowObservationCount,
            'decisions' => $decisions->count(),
            'tracking_bets' => $decisions->where('is_bet', true)->count(),
            'no_bets' => $decisions->where('is_bet', false)->count(),
            'settled_decisions' => $settled->count(),
            'pending_decisions' => $decisions->count() - $settled->count(),
            'actual_profit_units' => $actualProfit,
            'actual_roi' => $settledBets->isEmpty() ? null : $actualProfit / $settledBets->count(),
            'counterfactual_profit_units' => $counterfactualProfit,
            'counterfactual_roi' => $settled->isEmpty() ? null : $counterfactualProfit / $settled->count(),
            'average_clv' => $clvRows->isEmpty()
                ? null
                : (float) $clvRows->avg(fn (BetDecision $decision): float => (float) $decision->settlement?->clv),
            ...$calibration,
        ];
    }

    /**
     * @param  Collection<int, BetDecision>  $settled
     * @return array{
     *     calibration_games:int,
     *     baseline_brier:?float,
     *     challenger_brier:?float,
     *     brier_delta:?float,
     *     delta_convention:string
     * }
     */
    private function calibration(Collection $settled): array
    {
        $rows = $settled
            ->filter(fn (BetDecision $decision): bool => $decision->shadowOutput !== null
                && (float) $decision->settlement?->result_value !== 0.0)
            ->values();

        if ($rows->isEmpty()) {
            return [
                'calibration_games' => 0,
                'baseline_brier' => null,
                'challenger_brier' => null,
                'brier_delta' => null,
                'delta_convention' => 'baseline_minus_challenger',
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
            'baseline_brier' => $baselineBrier,
            'challenger_brier' => $challengerBrier,
            'brier_delta' => round($baselineBrier - $challengerBrier, 12),
            'delta_convention' => 'baseline_minus_challenger',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(ShadowModelOutput $output, ?Game $game): array
    {
        $snapshot = $output->featureSnapshot;
        $decision = $output->betDecisions->sortByDesc('id')->first();
        $settlement = $decision?->settlement;
        $home = $game?->homeTeam;
        $away = $game?->awayTeam;
        $snapshotOutputs = (array) $snapshot?->outputs;
        $uncertainty = $this->floatOrNull(
            data_get($output->explanation, 'challenger_outputs.uncertainty')
                ?? data_get($snapshot?->outputs, 'challenger_uncertainty')
                ?? data_get($snapshot?->model_metadata, 'shadow_inference.challenger_outputs.uncertainty'),
        );
        $maximumUncertainty = $this->floatOrNull(config('nfl_ml.shadow.max_uncertainty'));

        return [
            'id' => $output->id,
            'game_id' => $output->game_id,
            'matchup' => trim(($away?->abbreviation ?? 'Away').' @ '.($home?->abbreviation ?? 'Home')),
            'game_date' => $game?->game_date?->toDateString(),
            'game_time' => $game?->game_time,
            'game_status' => $game?->status,
            'market_type' => $output->market_type,
            'baseline_output' => (float) $output->baseline_output,
            'challenger_output' => (float) $output->challenger_output,
            'output_delta' => (float) $output->output_delta,
            'baseline_outputs' => [
                'win_probability' => $this->floatOrNull($snapshotOutputs['baseline_win_probability'] ?? $output->baseline_output),
                'predicted_spread' => $this->floatOrNull($snapshotOutputs['baseline_predicted_spread'] ?? null),
                'predicted_total' => $this->floatOrNull($snapshotOutputs['baseline_predicted_total'] ?? null),
            ],
            'challenger_outputs' => [
                'win_probability' => $this->floatOrNull($snapshotOutputs['challenger_win_probability'] ?? $output->challenger_output),
                'predicted_spread' => $this->floatOrNull($snapshotOutputs['challenger_predicted_spread'] ?? null),
                'predicted_total' => $this->floatOrNull($snapshotOutputs['challenger_predicted_total'] ?? null),
                'uncertainty' => $uncertainty,
            ],
            'active_source' => $snapshotOutputs['active_source'] ?? 'baseline',
            'public_output_changed' => (bool) data_get($output->explanation, 'public_output_changed', false),
            'generated_at' => $output->generated_at?->toIso8601String(),
            'snapshot' => [
                'id' => $snapshot?->id,
                'snapshot_run_id' => $snapshot?->snapshot_run_id,
                'model_run_id' => $snapshot?->model_run_id,
                'feature_hash' => $snapshot?->feature_hash,
                'pregame_safe' => (bool) $snapshot?->pregame_safe,
                'availability_status' => $snapshot?->availability_status,
                'features_available_at' => $snapshot?->features_available_at?->toIso8601String(),
                'game_start_at' => $snapshot?->game_start_at?->toIso8601String(),
            ],
            'inference_run_id' => $output->inference_run_id,
            'decision' => $decision ? [
                'id' => $decision->id,
                'status' => $decision->status,
                'recommendation_label' => $decision->recommendation_label,
                'side' => $decision->side,
                'price' => $decision->price,
                'bookmaker' => $decision->bookmaker,
                'market_probability' => $this->floatOrNull($decision->no_vig_probability),
                'model_probability' => $this->floatOrNull($decision->model_probability),
                'edge' => $this->floatOrNull($decision->edge),
                'is_bet' => (bool) $decision->is_bet,
                'is_public' => (bool) $decision->is_public,
                'is_tracking_only' => (bool) $decision->is_tracking_only,
                'pregame_safe' => (bool) $decision->pregame_safe,
                'eligibility_reasons' => array_values((array) $decision->eligibility_reasons),
                'model_uncertainty' => $uncertainty,
                'maximum_model_uncertainty' => $maximumUncertainty,
                'uncertainty_gate_enabled' => $maximumUncertainty !== null,
                'explanation' => [
                    ...(array) $decision->explanation,
                    'model_uncertainty' => $uncertainty,
                    'maximum_model_uncertainty' => $maximumUncertainty,
                    'uncertainty_gate_enabled' => $maximumUncertainty !== null,
                ],
                'decided_at' => $decision->decided_at?->toIso8601String(),
            ] : null,
            'settlement' => $settlement ? [
                'status' => $settlement->result_status,
                'result_value' => $this->floatOrNull($settlement->result_value),
                'profit_units' => $this->floatOrNull($settlement->profit_units),
                'counterfactual_profit_units' => $this->floatOrNull(data_get($settlement->metadata, 'shadow_profit_units')),
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

    /**
     * @return array<string, int|float|null>
     */
    private function emptySummary(): array
    {
        return [
            'shadow_observations' => 0,
            'decisions' => 0,
            'tracking_bets' => 0,
            'no_bets' => 0,
            'settled_decisions' => 0,
            'pending_decisions' => 0,
            'actual_profit_units' => 0.0,
            'actual_roi' => null,
            'counterfactual_profit_units' => 0.0,
            'counterfactual_roi' => null,
            'average_clv' => null,
            'calibration_games' => 0,
            'baseline_brier' => null,
            'challenger_brier' => null,
            'brier_delta' => null,
            'delta_convention' => 'baseline_minus_challenger',
        ];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
