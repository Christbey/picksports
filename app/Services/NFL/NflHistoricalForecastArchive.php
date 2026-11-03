<?php

namespace App\Services\NFL;

use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;

class NflHistoricalForecastArchive
{
    /** @return array<string, mixed>|null */
    public function forGame(Game $game): ?array
    {
        if (! config('prediction_lifecycle.canonical_reads.nfl') || $game->status !== 'STATUS_FINAL'
            || $game->home_score === null || $game->away_score === null) {
            return null;
        }
        $start = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        $cutover = CalculationRelease::where('sport', 'nfl')->where('phase', 'pregame')
            ->whereIn('status', ['approved', 'retired'])->whereNotNull('effective_at')->min('effective_at');
        if (! $start || ! $cutover || $start->gte(CarbonImmutable::parse($cutover))
            || ($game->sport_event_id !== null && CanonicalPrediction::where('sport', 'nfl')
                ->where('sport_event_id', $game->sport_event_id)->where('phase', 'pregame')->exists())) {
            return null;
        }
        $header = PredictionFeatureSnapshot::without('metadataPayload')->where('sport', 'nfl')
            ->where('prediction_table', 'nfl_predictions')->where('game_id', $game->id)
            ->where('pregame_safe', true)->where('availability_status', 'observed_pregame')
            ->where('game_start_at', $start)->whereColumn('generated_at', '<', 'game_start_at')
            ->whereColumn('features_available_at', '<', 'game_start_at')
            ->orderByDesc('generated_at')->orderByDesc('id')
            ->first(['id', 'prediction_id', 'model_version', 'generated_at']);
        if (! $header) {
            return null;
        }
        $outputs = PredictionFeatureSnapshot::without('metadataPayload')->find($header->id, ['id', 'outputs'])?->outputs ?? [];
        $spread = $this->number($outputs['predicted_spread'] ?? null);
        $total = $this->number($outputs['predicted_total'] ?? null);
        $probability = $this->number($outputs['win_probability'] ?? null);
        if ($spread === null && $total === null && $probability === null) {
            return null;
        }

        return [
            'source' => 'legacy_archive', 'prediction_table' => 'nfl_predictions',
            'prediction_id' => $header->prediction_id, 'snapshot_id' => $header->id,
            'model_version' => $header->model_version, 'generated_at' => $header->generated_at->toIso8601String(),
            'known_before_kickoff' => true, 'predicted_spread' => $spread,
            'predicted_total' => $total !== null && $total >= 0 ? $total : null,
            'win_probability' => $probability !== null && $probability >= 0 && $probability <= 1 ? $probability : null,
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
