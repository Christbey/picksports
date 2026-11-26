<?php

namespace App\Services\NFL\Predictions;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** Shared native pregame evidence boundary for NFL delivery, live baselines and grading. */
class NflCanonicalPregameEvidenceBoundary
{
    public function apply(EloquentBuilder|QueryBuilder $query, string $prediction = 'predictions'): EloquentBuilder|QueryBuilder
    {
        return $query->whereExists(function (QueryBuilder $evidence) use ($prediction): void {
            $evidence->selectRaw('1')->from('calculation_runs as grading_run')
                ->join('event_input_snapshots as grading_input', 'grading_input.id', '=', 'grading_run.event_input_snapshot_id')
                ->join('calculation_releases as grading_release', 'grading_release.id', '=', 'grading_run.calculation_release_id')
                ->join('sport_events as grading_event', 'grading_event.id', '=', 'grading_run.sport_event_id')
                ->whereColumn('grading_run.id', "{$prediction}.calculation_run_id")
                ->whereColumn('grading_run.sport_event_id', "{$prediction}.sport_event_id")
                ->whereColumn('grading_run.output_hash', "{$prediction}.output_hash")
                ->where('grading_run.status', 'succeeded')->where('grading_run.phase', 'pregame')
                ->where('grading_release.sport', 'nfl')->where('grading_release.phase', 'pregame')
                ->whereIn('grading_release.status', ['approved', 'retired'])
                ->where('grading_input.sport', 'nfl')->where('grading_input.phase', 'pregame')
                ->whereColumn('grading_input.sport_event_id', "{$prediction}.sport_event_id")
                ->where('grading_input.pregame_safety_status', 'verified')
                ->whereColumn('grading_input.captured_at', '<=', "{$prediction}.generated_at")
                ->whereColumn('grading_input.captured_at', '<=', 'grading_input.cutoff_at')
                ->whereColumn('grading_input.cutoff_at', '<=', 'grading_event.starts_at')
                ->whereColumn("{$prediction}.generated_at", '<', 'grading_event.starts_at')
                ->whereColumn("{$prediction}.published_at", '<', 'grading_event.starts_at')
                ->where(fn (QueryBuilder $timing) => $timing->whereNull('grading_input.latest_source_available_at')
                    ->orWhere(fn (QueryBuilder $available) => $available
                        ->whereColumn('grading_input.latest_source_available_at', '<=', 'grading_input.cutoff_at')
                        ->whereColumn('grading_input.latest_source_available_at', '<=', 'grading_input.captured_at')));
        });
    }
}
