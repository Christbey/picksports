<?php

namespace App\Services\Predictions;

use App\Actions\CBB\CalculateBettingValue as CalculateCbbBettingValue;
use App\Actions\NBA\CalculateBettingValue as CalculateNbaBettingValue;
use App\Actions\NFL\CalculateBettingValue as CalculateNflBettingValue;
use App\Actions\WNBA\CalculateBettingValue as CalculateWnbaBettingValue;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Services\MLB\MlbMarketAwareProjectionService;
use App\Support\PredictionDataPermissions;
use App\Support\PredictionFieldAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class PredictionResourcePreparer
{
    public function __construct(
        private readonly PredictionResourcePresentationStore $presentations,
        private readonly PredictionFieldAccess $fieldAccess,
        private readonly PredictionNarrativeService $narratives,
        private readonly CalculateNbaBettingValue $nbaBettingValue,
        private readonly CalculateNflBettingValue $nflBettingValue,
        private readonly CalculateCbbBettingValue $cbbBettingValue,
        private readonly CalculateWnbaBettingValue $wnbaBettingValue,
        private readonly MlbMarketAwareProjectionService $mlbMarketProjection,
    ) {}

    /**
     * @param  Collection<int, Model>  $predictions
     * @return Collection<int, Model>
     */
    public function prepare(
        Collection $predictions,
        string $sport,
        ?User $user,
        bool $includeGame = true,
    ): Collection {
        if ($predictions->isEmpty()) {
            return $predictions;
        }

        $sport = strtolower($sport);
        $predictions->loadMissing(['game.homeTeam', 'game.awayTeam']);
        $permissions = $this->permissions($user);
        $analyses = $this->analyses($sport, $predictions, $permissions['betting_value'] ?? false);

        foreach ($predictions as $prediction) {
            $this->presentations->put($prediction, $this->presentation(
                $sport,
                $prediction,
                $permissions,
                $analyses->get((int) $prediction->getKey()),
                $includeGame,
            ));
        }

        return $predictions;
    }

    public function prepareOne(
        Model $prediction,
        string $sport,
        ?User $user,
        bool $includeGame = true,
    ): Model {
        return $this->prepare(new Collection([$prediction]), $sport, $user, $includeGame)->firstOrFail();
    }

    /** @return array<string, bool> */
    private function permissions(?User $user): array
    {
        return collect(PredictionDataPermissions::allFields())
            ->mapWithKeys(fn (string $field): array => [
                $field => $user !== null && $this->fieldAccess->canViewField($user, $field),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Model>  $predictions
     * @return \Illuminate\Support\Collection<int, SportsAiPredictionAnalysis>
     */
    private function analyses(string $sport, Collection $predictions, bool $allowed): \Illuminate\Support\Collection
    {
        if (! $allowed || ! Schema::hasTable('sports_ai_prediction_analyses')) {
            return collect();
        }

        return SportsAiPredictionAnalysis::query()
            ->where('sport', $sport)
            ->whereIn('prediction_id', $predictions->modelKeys())
            ->where('market', 'game')
            ->orderByDesc('as_of_date')
            ->orderByDesc('created_at')
            ->get()
            ->unique('prediction_id')
            ->keyBy(fn (SportsAiPredictionAnalysis $analysis): int => (int) $analysis->prediction_id);
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    private function presentation(
        string $sport,
        Model $prediction,
        array $permissions,
        ?SportsAiPredictionAnalysis $analysis,
        bool $includeGame,
    ): PredictionResourcePresentationData {
        $game = $prediction->getRelation('game');
        $game = $game instanceof Model ? $game : null;
        $currentHash = $this->narratives->inputHashForSport($prediction, $game, $sport);
        $storedNarrative = is_array($prediction->getAttribute('narrative_json'))
            ? $prediction->getAttribute('narrative_json')
            : null;
        $storedHash = (string) ($prediction->getAttribute('narrative_input_hash') ?? '');
        $narrative = $storedNarrative && $storedHash !== '' && hash_equals($storedHash, $currentHash)
            ? $storedNarrative
            : $this->narratives->forSport($prediction, $game, $sport, false);
        $bettingValue = ($permissions['betting_value'] ?? false) && $game
            ? $this->bettingValue($sport, $game, $prediction)
            : null;

        return new PredictionResourcePresentationData(
            fieldAccess: $permissions,
            includeGame: $includeGame,
            narrative: $narrative,
            aiAnalysis: $this->analysisPayload($analysis),
            bettingValue: $bettingValue,
            bettingValueSummary: $sport === 'nfl' ? $this->bettingValueSummary($bettingValue) : null,
            predictionAnalysis: $sport === 'nfl' ? $this->predictionAnalysisSummary($prediction) : null,
            marketAwareProjection: $sport === 'mlb'
                ? $this->mlbMarketProjection->forPrediction($prediction)
                : null,
        );
    }

    /** @return array<int, array<string, mixed>>|null */
    private function bettingValue(string $sport, Model $game, Model $prediction): ?array
    {
        $stored = $prediction->getAttribute('betting_value');
        if (is_array($stored)) {
            return $stored;
        }

        $hadPredictionRelation = $game->relationLoaded('prediction');
        $previousPrediction = $hadPredictionRelation ? $game->getRelation('prediction') : null;

        $game->setRelation('prediction', $prediction);

        try {
            return match ($sport) {
                'nba' => $this->nbaBettingValue->execute($game),
                'nfl' => $this->nflBettingValue->execute($game),
                'cbb' => $this->cbbBettingValue->execute($game),
                'wnba' => $this->wnbaBettingValue->execute($game),
                default => null,
            };
        } finally {
            if ($hadPredictionRelation) {
                $game->setRelation('prediction', $previousPrediction);
            } else {
                $game->unsetRelation('prediction');
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function analysisPayload(?SportsAiPredictionAnalysis $analysis): ?array
    {
        if (! $analysis) {
            return null;
        }

        return [
            'id' => (int) $analysis->id,
            'as_of_date' => $analysis->as_of_date?->toDateString(),
            'market' => $analysis->market,
            'recommendation' => $analysis->recommendation,
            'bet_classification' => $analysis->bet_classification,
            'ai_confidence' => (int) $analysis->ai_confidence,
            'analysis_confidence' => (int) $analysis->analysis_confidence,
            'summary' => $analysis->summary,
            'key_factors' => array_values((array) ($analysis->key_factors ?? [])),
            'risk_flags' => array_values((array) ($analysis->risk_flags ?? [])),
            'reason_codes' => array_values((array) ($analysis->reason_codes ?? [])),
            'market_notes' => $analysis->market_notes ?? [],
            'calculated_edge' => $analysis->calculated_edge ?? [],
            'external_game_context' => $this->externalGameContextPayload($analysis),
            'provider' => $analysis->provider,
            'model' => $analysis->model,
            'created_at' => $analysis->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function externalGameContextPayload(SportsAiPredictionAnalysis $analysis): ?array
    {
        $context = data_get($analysis->raw_payload, 'external_game_context');

        if (! is_array($context) || ($context['available'] ?? false) !== true) {
            return null;
        }

        $sources = collect((array) ($context['sources'] ?? []))
            ->filter(fn ($source): bool => is_array($source) && filter_var($source['url'] ?? null, FILTER_VALIDATE_URL) !== false)
            ->map(fn (array $source): array => [
                'url' => (string) $source['url'],
                'title' => (string) ($source['title'] ?? 'Source'),
                'publisher' => (string) ($source['publisher'] ?? 'Unknown'),
                'published_at' => $source['published_at'] ?? null,
                'source_type' => $source['source_type'] ?? null,
            ])
            ->take(12)
            ->values()
            ->all();

        return [
            'status' => $context['status'] ?? null,
            'researched_at' => $context['researched_at'] ?? null,
            'expires_at' => $context['expires_at'] ?? null,
            'confidence' => isset($context['confidence']) ? (int) $context['confidence'] : null,
            'summary' => $context['summary'] ?? null,
            'facts' => array_values(array_slice((array) ($context['facts'] ?? []), 0, 16)),
            'sources' => $sources,
            'risk_flags' => array_values((array) ($context['risk_flags'] ?? [])),
            'deterministic_adjustment' => $context['deterministic_adjustment'] ?? null,
            'context_adjusted_model' => $context['context_adjusted_model'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $bettingValue
     * @return array<string, mixed>
     */
    private function bettingValueSummary(?array $bettingValue): array
    {
        $plays = collect($bettingValue ?? [])
            ->filter(fn (array $recommendation): bool => ($recommendation['is_playable'] ?? false) === true)
            ->values();
        $best = $plays
            ->sortByDesc(fn (array $recommendation): float|int => ($this->gradeRank((string) ($recommendation['grade'] ?? 'Pass')) * 1000) + (float) ($recommendation['edge'] ?? 0))
            ->first();

        return [
            'has_playable_value' => $plays->isNotEmpty(),
            'play_count' => $plays->count(),
            'best_grade' => $best['grade'] ?? null,
            'best_recommendation' => $best['recommendation'] ?? null,
            'best_type' => $best['type'] ?? null,
            'best_edge' => isset($best['edge']) ? (float) $best['edge'] : null,
            'best_units' => isset($best['bet_units']) ? (float) $best['bet_units'] : null,
            'risk_flags' => $plays
                ->flatMap(fn (array $recommendation): array => (array) ($recommendation['risk_flags'] ?? []))
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function predictionAnalysisSummary(Model $prediction): ?array
    {
        $metadata = is_array($prediction->getAttribute('model_metadata')) ? $prediction->getAttribute('model_metadata') : [];
        $analysis = $metadata['analysis_layer'] ?? null;

        if (! is_array($analysis) || ($analysis['applied'] ?? false) !== true) {
            return null;
        }

        return [
            'trust_score' => isset($analysis['trust_score']) ? (float) $analysis['trust_score'] : null,
            'bet_classification' => $analysis['bet_classification'] ?? null,
            'model_signal_classification' => $analysis['model_signal_classification'] ?? null,
            'risk_flags' => array_values((array) ($analysis['risk_flags'] ?? [])),
            'reason_codes' => array_values((array) ($analysis['reason_codes'] ?? [])),
            'reason_code_metadata' => $analysis['reason_code_metadata'] ?? [],
            'player_position_grades' => $metadata['player_position_grades'] ?? null,
            'bet_rule_evaluation' => $analysis['bet_rule_evaluation'] ?? null,
            'validated_signals' => $analysis['validated_signals'] ?? [],
            'best_validated_signal' => $analysis['best_validated_signal'] ?? null,
            'calculated_edge' => $analysis['calculated_edge'] ?? null,
            'analysis_confidence' => $analysis['analysis_confidence'] ?? null,
            'pro_signal_layer' => $analysis['pro_signal_layer'] ?? null,
        ];
    }

    private function gradeRank(string $grade): int
    {
        return match ($grade) {
            'A' => 3,
            'B' => 2,
            'C' => 1,
            default => 0,
        };
    }
}
