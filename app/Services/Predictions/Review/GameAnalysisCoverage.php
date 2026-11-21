<?php

namespace App\Services\Predictions\Review;

use App\Models\AiGeneration;
use App\Models\AnalysisWorkItem;
use App\Models\PredictionReviewRevision;
use App\Models\ResearchSource;
use App\Support\SportCatalog;
use Illuminate\Support\Facades\Schema;

/** Operational observations, not a claim that saved reviews remain publishable. */
class GameAnalysisCoverage
{
    /** @param list<string> $sports @return array<string, mixed> */
    public function report(array $sports = []): array
    {
        $sports = $sports === [] ? SportCatalog::ALL : array_values(array_unique($sports));
        if (array_diff($sports, SportCatalog::ALL) !== []) {
            throw new \InvalidArgumentException('Unsupported analysis sport.');
        }
        $installed = Schema::hasTable('analysis_work_items') && Schema::hasTable('prediction_review_revisions');
        $bySport = [];
        foreach ($sports as $sport) {
            $items = $installed ? AnalysisWorkItem::where('sport', $sport) : null;
            $pending = $items !== null ? (clone $items)->whereIn('state', ['pending', 'queued', 'running', 'deferred']) : null;
            $sources = Schema::hasTable('research_sources') ? ResearchSource::where('sport', $sport)->get(['enabled', 'last_error']) : collect();
            $bySport[$sport] = [
                'review_enabled' => (bool) config('prediction_review.enabled', false) && (bool) config('prediction_review.sports.'.$sport, false),
                'research_enabled' => (bool) config('research.enabled', false) && (bool) config('research.sports.'.$sport.'.enabled', false),
                'recorded_work_states' => $items !== null ? (clone $items)->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state')->all() : [],
                'overdue_unfinished' => $pending !== null ? (clone $pending)->where('deadline_at', '<=', now())->count() : 0,
                'oldest_unfinished_at' => $pending !== null ? (clone $pending)->oldest('created_at')->first(['created_at'])?->created_at?->toIso8601String() : null,
                'unexpired_saved_revisions' => $installed ? PredictionReviewRevision::where('sport', $sport)
                    ->where('applicability', 'current')->where('expires_at', '>', now())->where('created_at', '<=', now())->count() : 0,
                'sources' => ['registered' => $sources->count(), 'enabled' => $sources->where('enabled', true)->count(),
                    'failed' => $sources->filter(fn ($source) => $source->last_error !== null)->count()],
            ];
        }
        $usage = Schema::hasTable('ai_generations') ? AiGeneration::where('purpose', 'prediction_review')
            ->where('started_at', '>=', now()->subDay())->get(['metadata', 'cost_usd', 'latency_ms', 'input_tokens', 'output_tokens'])
            ->filter(fn ($row) => in_array(data_get($row->metadata, 'sport'), $sports, true)) : collect();
        $unknown = $usage->whereNull('cost_usd');
        $latencies = $usage->pluck('latency_ms')->filter(fn ($value) => $value !== null)->sort()->values();

        return [
            'observed_at' => now()->toIso8601String(), 'review_storage_installed' => $installed,
            'sports' => $bySport,
            'usage_last_24_hours' => [
                'purpose' => 'prediction_review', 'attempts' => $usage->count(),
                'known_cost_usd' => round((float) $usage->sum('cost_usd'), 6),
                'unknown_cost_attempts' => $unknown->count(),
                'unknown_reserved_usd' => round((float) $unknown->sum(fn ($row) => (float) data_get($row->metadata, 'reserved_cost_usd', 0)), 6),
                'input_tokens' => $usage->sum('input_tokens'), 'output_tokens' => $usage->sum('output_tokens'),
                'p95_latency_ms' => $latencies->isEmpty() ? null : (int) $latencies[(int) ceil($latencies->count() * 0.95) - 1],
            ],
            'limitations' => ['Recorded work state is not complete slate coverage or current betting eligibility.',
                'Saved revisions require current-input validation before presentation.',
                'Unknown cost is not zero; admission reservations are estimates, not an account billing cap.',
                'This usage report covers shared prediction reviews; existing research and narrative purposes are separate.'],
        ];
    }
}
