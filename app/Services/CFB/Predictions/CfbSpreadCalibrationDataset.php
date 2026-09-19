<?php

namespace App\Services\CFB\Predictions;

use App\Models\CanonicalPrediction;
use App\Models\SportEvent;

class CfbSpreadCalibrationDataset
{
    public function rows(string $releaseVersion): array
    {
        $rows = [];
        $excluded = [];
        $hashes = [];
        $completed = SportEvent::query()->where('sport', 'cfb')
            ->whereHas('cfbGame', fn ($q) => $q->where('status', 'STATUS_FINAL'));
        $candidates = (clone $completed)->whereHas('predictions', fn ($q) => $q->where('phase', 'pregame')
            ->whereIn('publication_state', ['published', 'superseded'])
            ->whereColumn('generated_at', '<', 'sport_events.starts_at')
            ->whereColumn('published_at', '<', 'sport_events.starts_at')
            ->whereHas('calculationRun.release', fn ($q) => $q->where('semantic_version', $releaseVersion)));
        $missing = (clone $completed)->count() - (clone $candidates)->count();
        if ($missing > 0) {
            $excluded['no_verified_contemporaneous_prediction'] = $missing;
        }
        // New releases usually have few completed predictions. Do not load every historical game/odds blob.
        foreach ($candidates->select(['sport_events.id', 'sport_events.season', 'sport_events.starts_at'])
            ->with('cfbGame:id,sport_event_id,home_score,away_score')->lazyById(200) as $event) {
            $game = $event->cfbGame;
            $prediction = CanonicalPrediction::query()->where('sport_event_id', $event->id)->where('phase', 'pregame')
                ->whereIn('publication_state', ['published', 'superseded'])->where('generated_at', '<', $event->starts_at)
                ->where('published_at', '<', $event->starts_at)
                ->whereHas('calculationRun.release', fn ($q) => $q->where('semantic_version', $releaseVersion))
                ->with(['markets', 'calculationRun.inputSnapshot', 'calculationRun.release'])->orderByDesc('generated_at')->first();
            $snapshot = $prediction?->calculationRun?->inputSnapshot;
            if (! $snapshot || $snapshot->pregame_safety_status !== 'verified'
                || $snapshot->captured_at->gte($event->starts_at)
                || ($snapshot->latest_source_available_at && $snapshot->latest_source_available_at->gt($snapshot->captured_at))
                || ! data_get($snapshot->inputs, 'require_versioned_elo')
                || ! CfbPredictionInputQuality::assess($snapshot->inputs)['qualified']) {
                $excluded['no_verified_contemporaneous_prediction'] = ($excluded['no_verified_contemporaneous_prediction'] ?? 0) + 1;

                continue;
            }
            $spread = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home');
            // The last stored pregame quote before kickoff is the user's closing-line convention.
            $quote = app(CfbStoredPregameQuote::class)->latest($game->id, 'spreads', 'home', $event->starts_at);
            if (! $quote || ! is_numeric($quote->line) || ! is_numeric($spread?->projected_line) || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)
                || abs((float) $quote->line * 2 - round((float) $quote->line * 2)) > 0.000001) {
                $excluded['missing_result_or_stored_closing_line'] = ($excluded['missing_result_or_stored_closing_line'] ?? 0) + 1;

                continue;
            }
            $hashes[] = $prediction->calculationRun->release->configuration_hash;
            $rows[] = ['game_id' => $game->id, 'prediction_id' => $prediction->id, 'snapshot_id' => $snapshot->id,
                'quote_id' => $quote->id, 'season' => (int) $event->season, 'kickoff' => $event->starts_at->toIso8601String(),
                'pregame_safe' => true, 'model_margin' => -(float) $spread->projected_line,
                'actual_margin' => (float) $game->home_score - (float) $game->away_score, 'home_line' => (float) $quote->line];
        }

        return ['rows' => $rows, 'excluded' => $excluded, 'configuration_hashes' => array_values(array_unique($hashes))];
    }
}
