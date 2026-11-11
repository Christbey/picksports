<?php

namespace App\Services\NFL;

use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\SportsGameContextReport;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\NFL\Research\ResearchRefreshPolicy;
use App\Services\Sports\SportsDateWindowService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/** Read-only, batched board context. Never runs forecasts or paid research. */
class NflPredictionBoardContext
{
    public function forPredictions(Collection $predictions): Collection
    {
        $ids = $predictions->map(fn ($prediction) => $this->game($prediction)?->id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $latestRevisionIds = ResearchRevision::query()
            ->selectRaw('MAX(id) as id')->whereIn('game_id', $ids)->groupBy('game_id');
        $revisions = ResearchRevision::query()
            ->joinSub($latestRevisionIds, 'latest_revisions', 'latest_revisions.id', '=', 'nfl_research_revisions.id')
            ->get(['nfl_research_revisions.id', 'game_id', 'report_id', 'revised', 'brief', 'payload_archive', 'market', 'created_at'])->keyBy('game_id');

        $latestReportIds = SportsGameContextReport::query()
            ->selectRaw('MAX(id) as id')->where('sport', 'nfl')->whereIn('game_id', $ids)->groupBy('game_id');
        $reports = SportsGameContextReport::query()
            ->joinSub($latestReportIds, 'latest_reports', 'latest_reports.id', '=', 'sports_game_context_reports.id')
            ->get(['sports_game_context_reports.id', 'game_id', 'status', 'researched_at', 'expires_at'])->keyBy('game_id');

        $pipeline = app(ResearchPipeline::class);

        return $predictions->mapWithKeys(function ($prediction) use ($revisions, $reports, $pipeline) {
            $game = $this->game($prediction);
            if (! $game instanceof Game) {
                return [$prediction->id => null];
            }
            $revision = $revisions->get($game->id);
            $report = $reports->get($game->id);
            $final = $game->status === 'STATUS_FINAL';
            $pregame = in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true);
            $reasons = (array) data_get($revision?->brief, 'eligibility.data_reasons', []);
            $storedHash = data_get($revision?->brief, 'stored_prediction_hash');
            $savedAt = $prediction instanceof CanonicalPrediction ? $prediction->generated_at : $prediction->updated_at;
            $stored = $this->stored($prediction);
            $newerSave = $revision && $savedAt?->gt($revision->created_at);
            $changed = in_array('research_candidate_changed', $reasons, true)
                || ($storedHash && ($stored === null || $storedHash !== $pipeline->storedPredictionHash($stored)));
            // Legacy assessments have no comparison snapshot: request assessment, not a claim of changed values.
            $comparisonMissing = $newerSave && ! $storedHash;
            $expired = $report && (! $report->expires_at?->isFuture()
                || ! $report->researched_at || $report->researched_at->isFuture()
                || $report->researched_at->lte(now()->subMinutes(app(ResearchRefreshPolicy::class)->freshnessMinutes($game))));
            $unlinked = ! $report || ($revision && $report->id !== $revision->report_id);
            $blocked = data_get($revision?->brief, 'research_refresh.deferred_reason');
            if ($expired) {
                $reasons[] = 'research_evidence_expired';
            }
            if ($changed) {
                $reasons[] = 'research_candidate_changed';
            }
            if ($comparisonMissing) {
                $reasons[] = 'research_prediction_comparison_missing';
            }
            if ($unlinked) {
                $reasons[] = 'research_evidence_unlinked';
            }
            if ($blocked) {
                $reasons[] = $blocked;
            }
            $status = match (true) {
                $final && $revision !== null => 'archived',
                ! $revision => 'missing',
                (bool) $blocked => 'refresh_blocked',
                (bool) $expired => 'evidence_expired',
                (bool) $changed => 'prediction_changed',
                (bool) $comparisonMissing => 'reassessment_due',
                $unlinked => 'evidence_changed',
                data_get($revision->brief, 'eligibility.data_complete') === true && $report->status === 'ready' => 'reviewed',
                default => 'hold',
            };
            // A final/live card must not borrow a live line to describe a pregame forecast.
            // Prefer a fresh book; retain valid stored quotes for display when all books are stale.
            $quotes = $pregame
                ? ($pipeline->quotes($game) ?: $pipeline->quotes($game, requireFresh: false))
                : ($revision?->market ?: $this->storedPregameQuotes($game, $pipeline));
            $home = collect($quotes)->where('side', 'home')->sortBy(fn ($q) => match ($q['bookmaker'] ?? '') {
                'draftkings' => 0, 'fanduel' => 1, default => 2,
            })->first();
            $totalQuotes = $pregame
                ? $pipeline->additionalMarketQuotes($game, 'totals', requireFresh: false)
                : $this->storedPregameQuotes($game, $pipeline, 'totals');
            $total = $home ? collect($totalQuotes)
                ->first(fn ($q) => $q['bookmaker'] === $home['bookmaker'] && $q['side'] === 'over') : null;
            $forecast = ! $final && $revision && ! $changed && ! $comparisonMissing && ! $expired && ! $unlinked ? $revision->revised : null;

            return [$prediction->id => [
                'forecast' => $forecast ? array_intersect_key($forecast, array_flip(['predicted_spread', 'predicted_total', 'win_probability'])) : null,
                'forecast_source' => $forecast ? 'Research forecast' : 'Model forecast',
                'forecast_at' => ($forecast ? $revision->created_at : $savedAt)?->toIso8601String(),
                'research' => [
                    'status' => $status,
                    'assessed_at' => $revision?->created_at?->toIso8601String(),
                    'decision' => data_get($revision?->brief, 'eligibility.status'),
                    'reasons' => array_values(array_unique($reasons)),
                    'evidence_expired' => (bool) $expired,
                    'prediction_changed' => (bool) $changed,
                    'refresh_blocked_reason' => $blocked,
                    'researched_at' => $report?->researched_at?->toIso8601String(),
                    'expires_at' => $report?->expires_at?->toIso8601String(),
                ],
                'market' => [
                    'home_spread' => isset($home['line']) ? (float) $home['line'] : null,
                    'total' => isset($total['line']) ? (float) $total['line'] : null,
                    'bookmaker' => $home['bookmaker'] ?? null,
                    'observed_at' => $home['observed_at'] ?? null,
                    'historical' => ! $pregame,
                    'spread_stale' => $home !== null && ! $pipeline->marketIsFresh($game, collect($quotes)->where('bookmaker', $home['bookmaker'])->values()->all()),
                    'total_stale' => $total !== null && ! collect($pipeline->additionalMarketQuotes($game, 'totals'))->contains(fn ($q) => $q['bookmaker'] === $total['bookmaker'] && $q['side'] === 'over'),
                    'total_observed_at' => $total['observed_at'] ?? null,
                ],
            ]];
        });
    }

    private function game(Model $prediction): ?Game
    {
        return $prediction instanceof CanonicalPrediction
            ? $prediction->sportEvent?->getRelation('nflGame')
            : $prediction->getRelationValue('game');
    }

    private function stored(Model $prediction): ?array
    {
        if (! $prediction instanceof CanonicalPrediction) {
            return $prediction->toArray();
        }
        $context = (array) data_get($prediction->calculationRun?->inputSnapshot?->inputs, 'recommendation_context', []);
        if (($context['version'] ?? null) !== NflRecommendationInputBuilder::VERSION) {
            return null;
        }

        return [...(array) ($context['outputs'] ?? []), 'model_metadata' => (array) ($context['model_metadata'] ?? []),
            'model_version' => $context['model_version'] ?? null, 'feature_version' => $prediction->feature_version,
            'blend_version' => $prediction->blend_version, 'updated_at' => $prediction->generated_at?->toIso8601String()];
    }

    /** Only timestamped odds saved before kickoff may stand in for missing research market evidence. */
    private function storedPregameQuotes(Game $game, ResearchPipeline $pipeline, string $market = 'spreads'): array
    {
        $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        if (! $kickoff || $kickoff->isFuture() || ! $game->odds_updated_at || $game->odds_updated_at->gte($kickoff)) {
            return [];
        }
        $quotes = $market === 'spreads'
            ? $pipeline->quotes($game, requireFresh: false)
            : $pipeline->additionalMarketQuotes($game, $market, requireFresh: false);

        return collect($quotes)->groupBy('bookmaker')->filter(function ($pair) use ($kickoff): bool {
            return $pair->every(function ($quote) use ($kickoff): bool {
                try {
                    return is_string($quote['observed_at'] ?? null)
                        && Carbon::parse($quote['observed_at'])->lt($kickoff);
                } catch (Throwable) {
                    return false;
                }
            });
        })->flatten(1)->values()->all();
    }
}
