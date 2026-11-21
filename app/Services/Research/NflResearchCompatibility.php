<?php

namespace App\Services\Research;

use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\SportsGameContextReport;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\NFL\Research\EvidencePacket;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\NFL\Research\ResearchRefreshPolicy;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Facades\Schema;

/** Adapter only: NFL writers, eligibility and provider admission remain untouched. */
class NflResearchCompatibility
{
    /** @return array<string, mixed>|null */
    public function read(Game $game): ?array
    {
        if (! Schema::hasTable('nfl_research_revisions') || ! Schema::hasTable('sports_game_context_reports')) {
            return null;
        }
        $revision = ResearchRevision::query()->where('game_id', $game->id)->latest('id')->first();
        if ($revision === null) {
            return null;
        }
        $report = SportsGameContextReport::query()->where('sport', 'nfl')->where('game_id', $game->id)->latest('id')->first();
        $policy = app(ResearchRefreshPolicy::class);
        $current = $report && $report->id === $revision->report_id
            && $report->researched_at?->lte(now())
            && $report->researched_at?->gt(now()->subMinutes($policy->freshnessMinutes($game)));
        if ($current && data_get($report->raw_payload, 'research_fingerprint')) {
            $current = $policy->reusable($report, $game,
                $policy->fingerprint($game, app(EvidencePacket::class)->forGame($game)),
                (string) data_get($report->raw_payload, 'candidate_hash', ''));
        }
        $selected = app(PredictionDeliverySource::class)->forGame($game, 'nfl');
        $candidate = $selected !== null && ! $selected->source instanceof CanonicalPrediction ? $selected->source->toArray() : null;
        if ($selected?->source instanceof CanonicalPrediction) {
            $context = data_get($selected->source->calculationRun?->inputSnapshot?->inputs, 'recommendation_context', []);
            $candidate = ($context['version'] ?? null) === NflRecommendationInputBuilder::VERSION ? [
                ...(array) ($context['outputs'] ?? []), 'model_metadata' => (array) ($context['model_metadata'] ?? []),
                'model_version' => $context['model_version'] ?? null,
                'feature_version' => $selected->source->feature_version, 'blend_version' => $selected->source->blend_version,
            ] : null;
        }
        $storedHash = data_get($revision->brief, 'stored_prediction_hash');
        $comparisonMissing = ! $storedHash && $selected?->source->updated_at?->gt($revision->created_at);
        if ($candidate === null || $comparisonMissing
            || ($storedHash && $storedHash !== app(ResearchPipeline::class)->storedPredictionHash($candidate))) {
            $current = false;
        }
        $expired = $report?->expires_at?->isPast() ?? true;
        $startsAt = $game->sportEvent?->starts_at ?? app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        $archived = $startsAt?->lte(now()) || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true);

        return [
            'schema_version' => 'game_research_summary_v1', 'sport' => 'nfl', 'game_id' => $game->id, 'sport_event_id' => $game->sport_event_id,
            'status' => in_array($report?->status, ['ready', 'partial'], true) ? $report->status : 'unavailable',
            'applicability' => $archived ? 'archived' : ($expired ? 'expired' : ($current ? 'current' : 'outdated')),
            'assessed_at' => $revision->created_at?->toIso8601String(), 'expires_at' => $report?->expires_at?->toIso8601String(),
            'supporting' => (array) data_get($revision->brief, 'supporting', []),
            'concerns' => (array) data_get($revision->brief, 'opposing', []),
            'unresolved' => (array) data_get($revision->brief, 'unresolved', []),
            'sources' => (array) ($report?->sources ?? []), 'source_coverage' => (array) data_get($revision->brief, 'source_coverage', []),
            'season_evidence' => data_get($revision->brief, 'season_evidence', []),
            'report_id' => $report?->id, 'legacy_revision_id' => $revision->id,
            'evidence_hash' => $revision->input_hash, 'reason_codes' => array_values(array_filter([
                data_get($revision->brief, 'research_refresh.deferred_reason'),
                $comparisonMissing ? 'research_prediction_comparison_missing' : null,
                ...($current ? [] : ['research_evidence_changed_or_stale']),
            ])),
            'numeric_adjustment_policy' => 'existing_nfl_compatibility',
        ];
    }

    public function assess(Game $game): void
    {
        app(ResearchPipeline::class)->review($game, research: false);
    }
}
