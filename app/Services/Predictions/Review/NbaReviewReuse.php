<?php

namespace App\Services\Predictions\Review;

use App\Models\AnalysisWorkItem;
use App\Models\NBA\ReviewRevalidation;
use App\Models\PredictionReviewRevision;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** Conservative NBA-only reuse: identical archived content and claims, newly verified provenance. */
class NbaReviewReuse
{
    public const VERSION = 'nba-review-provenance-v1';

    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    public function enabled(string $sport): bool
    {
        return $sport === 'nba' && config('prediction_review.nba_provenance_reuse_enabled', false)
            && Schema::hasTable('nba_review_revalidations');
    }

    public function eligible(array $evidence): bool
    {
        if (($evidence['schema_version'] ?? null) !== 'game_research_summary_v1'
            || ($evidence['sport'] ?? null) !== 'nba' || ($evidence['applicability'] ?? null) !== 'current'
            || ! in_array($evidence['status'] ?? null, ['ready', 'partial'], true)
            || empty($evidence['sources']) || empty($evidence['expires_at'])) {
            return false;
        }
        foreach ($evidence['sources'] as $source) {
            if (! is_array($source) || ! in_array($source['provider'] ?? null, ['espn', 'picksports'], true)
                || ! is_string($source['content_hash'] ?? null) || $source['content_hash'] === ''
                || ! is_string($source['parser_version'] ?? null) || $source['parser_version'] === '') {
                return false;
            }
        }
        try {
            return CarbonImmutable::parse($evidence['expires_at'])->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    public function materialHash(array $evidence): string
    {
        return $this->hasher->hash([self::VERSION, $this->material($evidence)]);
    }

    /** Called inside the work-item lock, after a newly paid revision is persisted. */
    public function record(PredictionReviewRevision $revision, array $evidence): void
    {
        if (! $this->enabled($revision->sport) || ! $this->eligible($evidence)
            || $revision->applicability !== 'current' || ! $revision->expires_at?->isFuture()
            || $revision->created_at?->isFuture()) {
            return;
        }
        ReviewRevalidation::firstOrCreate([
            'analysis_work_item_id' => $revision->analysis_work_item_id,
            'desired_fingerprint' => $revision->desired_fingerprint,
            'prediction_review_revision_id' => $revision->id,
        ], [
            'material_hash' => $this->materialHash($evidence),
            'policy_version' => self::VERSION, 'evidence' => $evidence, 'created_at' => now(),
        ]);
    }

    /** Caller holds the work-item row lock. Never purchases, creates revisions, or extends expiry. */
    public function revalidate(AnalysisWorkItem $item, array $hashes, array $evidence): ?PredictionReviewRevision
    {
        if (! $this->enabled($item->sport) || ! $this->eligible($evidence)
            || $item->state !== 'succeeded' || ! $item->deadline_at?->isFuture()) {
            return null;
        }
        $material = $this->materialHash($evidence);
        $receipt = ReviewRevalidation::query()->where('analysis_work_item_id', $item->id)
            ->where('desired_fingerprint', $item->desired_fingerprint)->where('policy_version', self::VERSION)
            ->where('material_hash', $material)
            ->whereHas('revision', fn ($query) => $this->validRevision($query, $item, $hashes))->latest('id')->first();
        $revision = $receipt ? $this->revision($item, $receipt, $hashes) : null;
        if ($revision === null) {
            return null;
        }
        ReviewRevalidation::firstOrCreate([
            'analysis_work_item_id' => $item->id, 'desired_fingerprint' => $hashes['fingerprint'],
            'prediction_review_revision_id' => $revision->id,
        ], [
            'material_hash' => $material,
            'policy_version' => self::VERSION, 'evidence' => $evidence, 'created_at' => now(),
        ]);

        return $revision;
    }

    /** Read-only association lookup; current research is still checked on every read. */
    public function current(AnalysisWorkItem $item, array $hashes, array $evidence, bool $includeExpired = false): ?PredictionReviewRevision
    {
        if (! $this->enabled($item->sport) || ! $this->eligible($evidence) || ! $item->deadline_at?->isFuture()) {
            return null;
        }
        $receipt = ReviewRevalidation::query()->where('analysis_work_item_id', $item->id)
            ->where('desired_fingerprint', $hashes['fingerprint'])->where('policy_version', self::VERSION)
            ->where('material_hash', $this->materialHash($evidence))
            ->whereHas('revision', fn ($query) => $this->validRevision($query, $item, $hashes, $includeExpired))->latest('id')->first();

        return $receipt ? $this->revision($item, $receipt, $hashes, $includeExpired) : null;
    }

    private function revision(AnalysisWorkItem $item, ReviewRevalidation $receipt, array $hashes, bool $includeExpired = false): ?PredictionReviewRevision
    {
        return $this->validRevision(PredictionReviewRevision::query()->whereKey($receipt->prediction_review_revision_id), $item, $hashes, $includeExpired)->first();
    }

    private function validRevision(Builder $query, AnalysisWorkItem $item, array $hashes, bool $includeExpired = false): Builder
    {
        return $query->where('analysis_work_item_id', $item->id)->where('sport', 'nba')->where('applicability', 'current')
            ->where('input_hash', $hashes['input_hash'])->where('execution_hash', $hashes['execution_hash'])
            ->when(! $includeExpired, fn ($query) => $query->where('expires_at', '>', now()))->where('created_at', '<=', now());
    }

    private function material(array $evidence): array
    {
        foreach ($evidence as $key => $value) {
            // Keep source identity/URL, parser, raw content/material hashes, claims,
            // coverage and unknown fields. Changed raw content conservatively buys a new review.
            if (in_array($key, ['assessed_at', 'queried_at', 'generated_at', 'checked_at', 'last_checked_at', 'age_minutes',
                'observed_at', 'published_at', 'expires_at', 'report_id', 'evidence_hash', 'observation_id', 'snapshot_id'], true)) {
                unset($evidence[$key]);
            } elseif ($key === 'evidence_reference' && is_string($value)) {
                $evidence[$key] = preg_replace('/\\b(nba_player_injury_snapshots|nba_player_injury_snapshot_entries|nba_research_context_snapshots):[0-9]+\\b/', '$1:immutable-content', $value);
            } elseif (is_array($value)) {
                $evidence[$key] = $this->material($value);
            }
        }

        return $evidence;
    }
}
