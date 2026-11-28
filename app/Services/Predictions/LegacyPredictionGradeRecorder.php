<?php

namespace App\Services\Predictions;

use App\Models\LegacyPredictionGradeRevision;
use App\Models\PredictionEvaluation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LegacyPredictionGradeRecorder
{
    public const SCORING_VERSION = 'legacy-forecast-grade-v1';

    public const SPORTS = ['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wnba', 'wcbb'];

    private const GRADE_FIELDS = ['actual_spread', 'actual_total', 'spread_error', 'total_error', 'winner_correct',
        'graded_at', 'ats_pick_side', 'ats_pick_result', 'ats_pick_edge', 'total_pick_side', 'total_pick_line', 'total_pick_result', 'total_pick_edge'];

    public function evaluationQuery(Model $prediction): Builder
    {
        return PredictionEvaluation::on($prediction->getConnectionName())
            ->whereNull('canonical_prediction_id')
            ->where('prediction_table', $prediction->getTable())->where('prediction_id', $prediction->getKey())
            ->where('model_version', $prediction->model_version)->where('feature_version', $prediction->feature_version)
            ->where('blend_version', $prediction->blend_version);
    }

    public function assertUnambiguousEvaluation(Model $prediction): void
    {
        $identities = $this->evaluationQuery($prediction)->orderBy('id')->limit(2)->lockForUpdate()->get(['id']);
        if ($identities->count() > 1) {
            throw new LogicException('Ambiguous legacy evaluation identity for '.$prediction->getTable().':'.$prediction->getKey()
                .'; preserve all matching rows and review their version identity before retrying bounded repair.');
        }
    }

    public function latest(Model $prediction): ?LegacyPredictionGradeRevision
    {
        return LegacyPredictionGradeRevision::on($prediction->getConnectionName())
            ->where('prediction_table', $prediction->getTable())->where('prediction_id', $prediction->getKey())
            ->orderByDesc('revision')->lockForUpdate()->first();
    }

    public function needsGrading(Model $prediction, Model $game, bool $repair = false): bool
    {
        $this->validateIdentity($prediction, $game);
        // Discovery reports pending work; the write transaction emits the refusal.
        if ($this->evaluationQuery($prediction)->orderBy('id')->limit(2)->lockForUpdate()->get(['id'])->count() > 1) {
            return true;
        }
        if ($prediction->graded_at === null) {
            return true;
        }
        $latest = $this->latest($prediction);
        if ($repair && $latest === null) {
            return true;
        }
        if ((float) $prediction->actual_spread !== (float) ($game->home_score - $game->away_score)
            || (float) $prediction->actual_total !== (float) ($game->home_score + $game->away_score)
            || $prediction->actual_spread === null || $prediction->actual_total === null) {
            return true;
        }
        $evaluation = $this->evaluationQuery($prediction)->orderByDesc('id')->lockForUpdate()->first();
        if ($evaluation === null || (int) data_get($evaluation->actuals, 'home_score', -1) !== (int) $game->home_score
            || (int) data_get($evaluation->actuals, 'away_score', -1) !== (int) $game->away_score) {
            return true;
        }

        return $latest !== null && ($latest->evidence_kind !== 'observed_grade'
            || $latest->result_hash !== $this->resultHash($game)
            || $latest->prediction_hash !== $this->predictionHash($prediction)
            || $latest->scoring_version !== self::SCORING_VERSION
            || $this->gradeHash($latest->grade_snapshot) !== $this->gradeHash($prediction->only(self::GRADE_FIELDS))
            || $this->evaluationHash($latest->evaluation_snapshot ?? []) !== $this->evaluationHash($evaluation->toArray()));
    }

    public function preserveBaseline(Model $prediction, Model $game): void
    {
        if ($prediction->graded_at !== null && $this->latest($prediction) === null) {
            $this->append($prediction, $game, 'imported_legacy_baseline');
        }
    }

    public function record(Model $prediction, Model $game): void
    {
        $this->append($prediction, $game, 'observed_grade');
    }

    private function append(Model $prediction, Model $game, string $kind): void
    {
        $sport = $this->validateIdentity($prediction, $game);
        if ($prediction->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Legacy grade receipts require the paired grading transaction.');
        }
        $this->assertUnambiguousEvaluation($prediction);
        $previous = $this->latest($prediction);
        $evaluation = $this->evaluationQuery($prediction)->orderByDesc('id')->lockForUpdate()->first();
        $payload = [
            'sport' => $sport, 'prediction_table' => $prediction->getTable(), 'prediction_id' => $prediction->getKey(),
            'game_table' => $game->getTable(), 'game_id' => $game->getKey(),
            'revision' => ($previous?->revision ?? 0) + 1, 'supersedes_id' => $previous?->id,
            'scoring_version' => $kind === 'observed_grade' ? self::SCORING_VERSION : 'legacy_unknown',
            'evidence_kind' => $kind,
            'result_hash' => $kind === 'observed_grade' ? $this->resultHash($game) : null,
            'prediction_hash' => $this->predictionHash($prediction),
            'result_snapshot' => $kind === 'observed_grade' ? $this->resultSnapshot($game) : null,
            'prediction_snapshot' => $this->predictionSnapshot($prediction),
            'grade_snapshot' => $prediction->only(self::GRADE_FIELDS),
            'evaluation_snapshot' => $evaluation?->toArray(),
            'original_graded_at' => $prediction->graded_at,
            'recorded_at' => now(),
        ];
        // SQL timestamps may discard subsecond precision; the evidence snapshots
        // retain their own values, while storage bookkeeping is outside this digest.
        $payload['receipt_hash'] = app(CanonicalPayloadHasher::class)->hash(
            array_diff_key($payload, array_flip(['recorded_at', 'original_graded_at'])),
        );
        LegacyPredictionGradeRevision::on($prediction->getConnectionName())->create($payload);
    }

    private function resultSnapshot(Model $game): array
    {
        return ['game_table' => $game->getTable(), 'game_id' => $game->getKey(), 'status' => 'official',
            'home_score' => (int) $game->home_score, 'away_score' => (int) $game->away_score];
    }

    private function resultHash(Model $game): string
    {
        return app(CanonicalPayloadHasher::class)->hash($this->resultSnapshot($game));
    }

    private function predictionHash(Model $prediction): string
    {
        return app(CanonicalPayloadHasher::class)->hash($this->predictionSnapshot($prediction));
    }

    private function predictionSnapshot(Model $prediction): array
    {
        return array_filter($prediction->attributesToArray(), fn ($key) => ! in_array($key, [...self::GRADE_FIELDS, 'updated_at'], true)
            && ! str_starts_with($key, 'live_') && ! str_starts_with($key, 'narrative_'), ARRAY_FILTER_USE_KEY);
    }

    private function gradeHash(array $grade): string
    {
        unset($grade['graded_at']);

        return app(CanonicalPayloadHasher::class)->hash($grade);
    }

    private function evaluationHash(array $evaluation): string
    {
        $fields = ['sport', 'prediction_table', 'prediction_id', 'model_version', 'feature_version', 'blend_version',
            'actuals', 'errors', 'market_comparison'];

        return app(CanonicalPayloadHasher::class)->hash(array_intersect_key($evaluation, array_flip($fields)));
    }

    private function validateIdentity(Model $prediction, Model $game): string
    {
        foreach (self::SPORTS as $sport) {
            $prefix = 'App\\Models\\'.strtoupper($sport).'\\';
            if ($prediction::class === $prefix.'Prediction' && $game::class === $prefix.'Game'
                && $prediction->game_id === $game->getKey()
                && $prediction->getConnection()->getName() === $game->getConnection()->getName()) {
                return $sport;
            }
        }
        throw new LogicException('Legacy grade receipts require matching allowlisted legacy game/prediction identities.');
    }
}
