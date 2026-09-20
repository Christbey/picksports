<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\SportsGameContextReport;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Support\Collection;

/** Read-only, batched board context. Never runs forecasts or paid research. */
class NflPredictionBoardContext
{
    public function forPredictions(Collection $predictions): Collection
    {
        $ids = $predictions->pluck('game_id')->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $revisions = ResearchRevision::query()->whereIn('id', ResearchRevision::query()
            ->selectRaw('MAX(id)')->whereIn('game_id', $ids)->groupBy('game_id'))
            ->get(['id', 'game_id', 'report_id', 'revised', 'brief', 'market', 'created_at'])->keyBy('game_id');
        $reports = SportsGameContextReport::query()->whereIn('id', SportsGameContextReport::query()
            ->selectRaw('MAX(id)')->where('sport', 'nfl')->whereIn('game_id', $ids)->groupBy('game_id'))
            ->get(['id', 'game_id', 'status', 'expires_at'])->keyBy('game_id');
        $pipeline = app(ResearchPipeline::class);

        return $predictions->mapWithKeys(function ($prediction) use ($revisions, $reports, $pipeline) {
            $game = $prediction->getRelationValue('game');
            if (! $game instanceof Game) {
                return [$prediction->id => null];
            }
            $revision = $revisions->get($game->id);
            $report = $reports->get($game->id);
            $final = $game->status === 'STATUS_FINAL';
            $pregame = in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true);
            $newerForecast = $revision && $prediction->updated_at?->gt($revision->created_at);
            $status = ! $revision ? 'missing' : ($final ? 'archived' : ($newerForecast
                || ! $report || $report->id !== $revision->report_id || ! $report->expires_at?->isFuture()
                ? 'stale' : (data_get($revision->brief, 'eligibility.data_complete') === true && $report->status === 'ready' ? 'reviewed' : 'hold')));
            // A final/live card must not borrow a live line to describe a pregame forecast.
            $quotes = $pregame ? $pipeline->quotes($game) : ($revision?->market ?? []);
            $home = collect($quotes)->where('side', 'home')->sortBy(fn ($q) => match ($q['bookmaker'] ?? '') {
                'draftkings' => 0, 'fanduel' => 1, default => 2,
            })->first();
            $total = $pregame && $home ? collect($pipeline->additionalMarketQuotes($game, 'totals'))
                ->first(fn ($q) => $q['bookmaker'] === $home['bookmaker'] && $q['side'] === 'over') : null;
            $forecast = ! $final && $revision && ! $newerForecast ? $revision->revised : null;

            return [$prediction->id => [
                'forecast' => $forecast ? array_intersect_key($forecast, array_flip(['predicted_spread', 'predicted_total', 'win_probability'])) : null,
                'forecast_source' => $forecast ? 'Research forecast' : 'Model forecast',
                'forecast_at' => ($forecast ? $revision->created_at : $prediction->updated_at)?->toIso8601String(),
                'research' => [
                    'status' => $status,
                    'assessed_at' => $revision?->created_at?->toIso8601String(),
                    'decision' => data_get($revision?->brief, 'eligibility.status'),
                    'reasons' => data_get($revision?->brief, 'eligibility.data_reasons', []),
                ],
                'market' => [
                    'home_spread' => isset($home['line']) ? (float) $home['line'] : null,
                    'total' => isset($total['line']) ? (float) $total['line'] : null,
                    'bookmaker' => $home['bookmaker'] ?? null,
                    'observed_at' => $home['observed_at'] ?? null,
                    'historical' => ! $pregame,
                ],
            ]];
        });
    }
}
