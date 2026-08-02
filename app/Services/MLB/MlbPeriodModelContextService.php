<?php

namespace App\Services\MLB;

use App\Models\BetDecision;
use App\Models\MLB\PickCandidate;
use App\Models\ModelArtifact;
use App\Models\ShadowModelOutput;
use Illuminate\Support\Collection;

class MlbPeriodModelContextService
{
    /** @var array<int, Collection<int, ShadowModelOutput>> */
    private array $gameOutputs = [];

    /**
     * @param  iterable<int, int|string>  $gameIds
     */
    public function prime(iterable $gameIds): void
    {
        $ids = collect($gameIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => array_key_exists($id, $this->gameOutputs))
            ->values();
        if ($ids->isEmpty()) {
            return;
        }

        $grouped = ShadowModelOutput::query()
            ->with([
                'artifact.trainingRun',
                'featureSnapshot',
                'betDecisions.settlement',
            ])
            ->where('sport', 'mlb')
            ->where('game_table', 'mlb_games')
            ->whereIn('game_id', $ids->all())
            ->whereIn('market_type', ['first_3_moneyline', 'first_5_moneyline'])
            ->latest('generated_at')
            ->latest('id')
            ->get()
            ->groupBy('game_id');

        foreach ($ids as $gameId) {
            $this->gameOutputs[$gameId] = $this->prepareOutputs(
                $grouped->get($gameId, collect()),
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forGame(int $gameId, ?string $marketType = null, ?string $side = null): array
    {
        return $this->outputsForGame($gameId)
            ->when($marketType, fn (Collection $outputs): Collection => $outputs
                ->where('market_type', ModelArtifact::normalizeMarketType($marketType)))
            ->map(fn (ShadowModelOutput $shadow): array => $this->present($shadow, $side))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCandidate(PickCandidate $candidate): array
    {
        if (! in_array($candidate->market_type, ['first_3_moneyline', 'first_5_moneyline'], true)) {
            return [];
        }

        return $this->forGame((int) $candidate->game_id, $candidate->market_type, $candidate->side);
    }

    /**
     * Only a model that was promoted for this market before inference may replace
     * the heuristic probability used by a generated candidate.
     *
     * @return array{probability: float, shadow: ShadowModelOutput, context: array<string, mixed>}|null
     */
    public function qualifiedProbability(int $gameId, string $marketType, string $side): ?array
    {
        $marketType = ModelArtifact::normalizeMarketType($marketType);
        $shadow = $this->outputsForGame($gameId)
            ->where('market_type', $marketType)
            ->first(fn (ShadowModelOutput $output): bool => $this->isQualified($output));
        if (! $shadow) {
            return null;
        }

        $path = $side === 'away'
            ? 'challenger_outputs.conditional_away_win_probability'
            : 'challenger_outputs.conditional_home_win_probability';
        $probability = $this->probability(data_get($shadow->explanation, $path));
        if ($probability === null) {
            return null;
        }

        return [
            'probability' => $probability,
            'shadow' => $shadow,
            'context' => $this->present($shadow, $side),
        ];
    }

    /**
     * @return Collection<int, ShadowModelOutput>
     */
    private function outputsForGame(int $gameId): Collection
    {
        $this->prime([$gameId]);

        return $this->gameOutputs[$gameId];
    }

    /**
     * @param  Collection<int, ShadowModelOutput>  $outputs
     * @return Collection<int, ShadowModelOutput>
     */
    private function prepareOutputs(Collection $outputs): Collection
    {
        return $outputs
            ->groupBy(fn (ShadowModelOutput $shadow): string => $shadow->market_type.'|'.$shadow->model_artifact_id)
            ->map(fn (Collection $versions): ShadowModelOutput => $versions->first())
            ->sortByDesc(fn (ShadowModelOutput $shadow): array => [
                data_get($shadow->artifact?->metrics, 'shadow_selection.active') === true ? 2 : 0,
                $this->isQualified($shadow) ? 1 : 0,
                $shadow->generated_at?->getTimestamp() ?? 0,
                $shadow->id,
            ])
            ->values();
    }

    private function isQualified(ShadowModelOutput $shadow): bool
    {
        $artifact = $shadow->artifact;

        return $shadow->status === 'promoted_shadow'
            && $artifact?->isPromotedForMarket($shadow->market_type) === true
            && $artifact->promoted_at !== null
            && $shadow->generated_at !== null
            && $artifact->promoted_at->lessThanOrEqualTo($shadow->generated_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShadowModelOutput $shadow, ?string $side): array
    {
        $artifact = $shadow->artifact;
        $snapshot = $shadow->featureSnapshot;
        $outputs = (array) data_get($shadow->explanation, 'challenger_outputs', []);
        $decision = $this->decisionForSide($shadow, $side);

        return [
            'market_type' => $shadow->market_type,
            'role' => data_get($artifact?->metrics, 'shadow_selection.active') === true
                ? 'active_challenger'
                : ($artifact?->status === 'promoted' ? 'champion' : 'challenger'),
            'status' => $shadow->status,
            'qualified_for_candidates' => $this->isQualified($shadow),
            'active_source' => data_get($shadow->explanation, 'active_source', 'baseline'),
            'apply_to_live_output' => (bool) data_get($shadow->explanation, 'apply_to_live_output', false),
            'baseline_probability' => (float) $shadow->baseline_output,
            'challenger_probability' => (float) $shadow->challenger_output,
            'probability_delta' => (float) $shadow->output_delta,
            'probabilities' => [
                'home_win' => $this->probability($outputs['home_win_probability'] ?? null),
                'away_win' => $this->probability($outputs['away_win_probability'] ?? null),
                'tie' => $this->probability($outputs['tie_probability'] ?? null),
                'conditional_home_win' => $this->probability($outputs['conditional_home_win_probability'] ?? null),
                'conditional_away_win' => $this->probability($outputs['conditional_away_win_probability'] ?? null),
            ],
            'fair_prices' => [
                'home' => is_numeric($outputs['fair_home_price'] ?? null) ? (int) $outputs['fair_home_price'] : null,
                'away' => is_numeric($outputs['fair_away_price'] ?? null) ? (int) $outputs['fair_away_price'] : null,
            ],
            'uncertainty' => $this->probability($outputs['uncertainty'] ?? null),
            'model_name' => $outputs['model_name'] ?? null,
            'calibration_method' => $outputs['calibration_method'] ?? null,
            'lineage' => [
                'model_run_id' => data_get($shadow->explanation, 'model_run_id'),
                'inference_run_id' => $shadow->inference_run_id,
                'training_run_id' => $artifact?->training_run_id,
                'artifact_id' => $artifact?->id,
                'artifact_hash' => $artifact?->artifact_hash,
                'artifact_uri' => $artifact?->artifact_uri,
                'dataset_hash' => data_get($shadow->explanation, 'dataset_hash') ?? $artifact?->dataset_hash,
                'config_hash' => data_get($shadow->explanation, 'config_hash') ?? $artifact?->trainingRun?->config_hash,
                'code_version' => $artifact?->trainingRun?->code_version,
                'feature_hash' => data_get($shadow->explanation, 'feature_hash') ?? $snapshot?->feature_hash,
                'snapshot_run_id' => $snapshot?->snapshot_run_id,
            ],
            'timing' => [
                'generated_at' => $shadow->generated_at?->toIso8601String(),
                'features_available_at' => $snapshot?->features_available_at?->toIso8601String(),
                'game_start_at' => $snapshot?->game_start_at?->toIso8601String(),
                'pregame_safe' => (bool) $snapshot?->pregame_safe,
                'availability_status' => $snapshot?->availability_status,
            ],
            'decision' => $decision ? $this->presentDecision($decision) : null,
        ];
    }

    private function decisionForSide(ShadowModelOutput $shadow, ?string $side): ?BetDecision
    {
        $decisions = $shadow->betDecisions
            ->sortByDesc(fn (BetDecision $decision): array => [
                $decision->decided_at?->getTimestamp() ?? 0,
                $decision->id,
            ]);

        return $side
            ? $decisions->firstWhere('side', $side)
            : $decisions->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDecision(BetDecision $decision): array
    {
        $settlement = $decision->settlement;

        return [
            'id' => $decision->id,
            'status' => $decision->status,
            'recommendation_label' => $decision->recommendation_label,
            'side' => $decision->side,
            'is_public' => (bool) $decision->is_public,
            'is_tracking_only' => (bool) $decision->is_tracking_only,
            'is_bet' => (bool) $decision->is_bet,
            'pregame_safe' => (bool) $decision->pregame_safe,
            'eligibility_reasons' => $decision->eligibility_reasons ?? [],
            'reason_codes' => $decision->reason_codes ?? [],
            'risk_flags' => $decision->risk_flags ?? [],
            'model_probability' => $this->probability($decision->model_probability),
            'market_probability' => $this->probability($decision->market_probability),
            'no_vig_probability' => $this->probability($decision->no_vig_probability),
            'edge' => is_numeric($decision->edge) ? (float) $decision->edge : null,
            'expected_value' => is_numeric($decision->projected_value) ? (float) $decision->projected_value : null,
            'quote' => [
                'line' => is_numeric($decision->line) ? (float) $decision->line : null,
                'price' => is_numeric($decision->price) ? (int) $decision->price : null,
                'bookmaker' => $decision->bookmaker,
                'captured_at' => data_get($decision->market_snapshot, 'captured_at'),
            ],
            'decided_at' => $decision->decided_at?->toIso8601String(),
            'settlement' => $settlement ? [
                'result_status' => $settlement->result_status,
                'profit_units' => is_numeric($settlement->profit_units) ? (float) $settlement->profit_units : null,
                'closing_price' => is_numeric($settlement->closing_price) ? (int) $settlement->closing_price : null,
                'closing_line' => is_numeric($settlement->closing_line) ? (float) $settlement->closing_line : null,
                'clv' => is_numeric($settlement->clv) ? (float) $settlement->clv : null,
                'settled_at' => $settlement->settled_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function probability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $probability = (float) $value;

        return $probability >= 0.0 && $probability <= 1.0 ? $probability : null;
    }
}
