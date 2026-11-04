<?php

namespace App\Services\WCBB\Predictions;

use App\Application\Sports\ReadModels\LivePregameForecast;
use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\LivePredictionSnapshot;
use App\Models\WCBB\Game;
use Illuminate\Database\Eloquent\Model;

/** Retain an already observed live baseline; never supplies a pregame forecast. */
class WcbbLegacyLiveBaseline
{
    public function forGame(Model $game): ?LivePregameForecast
    {
        if (! $game instanceof Game || ! in_array($game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'], true)) {
            return null;
        }
        $event = $game->sportEvent;
        $release = CalculationRelease::where('sport', 'wcbb')->where('phase', 'pregame')
            ->whereIn('status', ['approved', 'retired'])->where('input_schema_version', WcbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION)
            ->where('configuration->forecast->source', WcbbFullModelReleaseDefinition::FORECAST_SOURCE)
            ->where('effective_at', '<=', now())->orderBy('effective_at')->orderBy('id')->first();
        if (! $event || $event->sport !== 'wcbb' || ! $release || ! $event->starts_at?->lt($release->effective_at)
            || CanonicalPrediction::where('sport', 'wcbb')->where('sport_event_id', $event->id)->where('phase', 'pregame')->exists()) {
            return null;
        }
        $anchor = LivePredictionSnapshot::where('sport', 'wcbb')->where('game_table', $game->getTable())->where('game_id', $game->id)
            ->where('status', 'live')->whereNull('canonical_prediction_id')
            ->where('observed_at', '>=', $event->starts_at)->where('observed_at', '<', $release->effective_at)
            ->orderByDesc('observed_at')->orderByDesc('id')->first();
        $baseline = $anchor?->baseline;
        if (! is_array($baseline) || ($baseline['pinned'] ?? false) !== true
            || ($baseline['prediction_source'] ?? null) !== 'legacy' || ($baseline['prediction_table'] ?? null) !== 'wcbb_predictions'
            || empty($baseline['prediction_id']) || ($baseline['canonical_prediction_id'] ?? null) !== null) {
            return null;
        }
        foreach (['predicted_spread', 'predicted_total', 'win_probability'] as $key) {
            if (! is_numeric($baseline[$key] ?? null) || ! is_finite((float) $baseline[$key])) {
                return null;
            }
        }
        if ($baseline['predicted_total'] <= 0 || $baseline['predicted_total'] < abs($baseline['predicted_spread'])
            || $baseline['win_probability'] < 0 || $baseline['win_probability'] > 1) {
            return null;
        }

        return LivePregameForecast::fromArray([...$baseline, 'live_compatibility_snapshot_id' => $anchor->id,
            'live_compatibility_release_id' => $release->id]);
    }

    public function matches(Model $game, array $baseline): bool
    {
        $frozen = $this->forGame($game)?->toArray();
        if ($frozen === null) {
            return false;
        }
        foreach (['prediction_source', 'prediction_table', 'prediction_id', 'canonical_prediction_id'] as $key) {
            if (($frozen[$key] ?? null) !== ($baseline[$key] ?? null)) {
                return false;
            }
        }

        foreach (['predicted_spread', 'predicted_total', 'win_probability'] as $key) {
            if (! is_numeric($baseline[$key] ?? null) || ! is_finite((float) $baseline[$key])
                || (float) $frozen[$key] !== (float) $baseline[$key]) {
                return false;
            }
        }

        return true;
    }
}
