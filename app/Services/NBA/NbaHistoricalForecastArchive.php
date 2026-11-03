<?php

namespace App\Services\NBA;

use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;

/** Historical display only. Never supplies active forecasts or betting evidence. */
class NbaHistoricalForecastArchive
{
    /** @return array<string, mixed>|null */
    public function forGame(Game $game): ?array
    {
        if (! config('prediction_lifecycle.canonical_reads.nba') || $game->status !== 'STATUS_FINAL'
            || $game->home_score === null || $game->away_score === null) {
            return null;
        }
        $start = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        $cutover = CalculationRelease::where('sport', 'nba')->where('phase', 'pregame')
            ->whereIn('status', ['approved', 'retired'])->whereNotNull('effective_at')->min('effective_at');
        if (! $start || ! $cutover || $start->gte(CarbonImmutable::parse($cutover))
            || ($game->sport_event_id !== null && CanonicalPrediction::where('sport', 'nba')
                ->where('sport_event_id', $game->sport_event_id)->where('phase', 'pregame')->exists())) {
            return null;
        }
        $snapshot = PredictionFeatureSnapshot::without('metadataPayload')->where('sport', 'nba')
            ->where('prediction_table', 'nba_predictions')->where('game_id', $game->id)
            ->where('pregame_safe', true)->where('availability_status', 'observed_pregame')
            ->where('game_start_at', $start)->whereColumn('generated_at', '<', 'game_start_at')
            ->whereColumn('features_available_at', '<', 'game_start_at')
            ->orderByDesc('generated_at')->orderByDesc('id')->first(['id', 'prediction_id', 'model_version', 'generated_at']);
        $legacy = $snapshot ? null : Prediction::where('game_id', $game->id)
            ->first(['id', 'predicted_spread', 'predicted_total', 'win_probability', 'model_version', 'updated_at']);
        if (! $snapshot && ! $legacy) {
            return null;
        }
        $outputs = $snapshot
            ? (PredictionFeatureSnapshot::without('metadataPayload')->find($snapshot->id, ['id', 'outputs'])?->outputs ?? [])
            : $legacy->toArray();

        return ['source' => $snapshot ? 'legacy_archive' : 'legacy_history', 'prediction_table' => 'nba_predictions',
            'prediction_id' => $snapshot?->prediction_id ?? $legacy->id, 'snapshot_id' => $snapshot?->id,
            'model_version' => $snapshot?->model_version ?? $legacy?->model_version,
            'generated_at' => ($snapshot?->generated_at ?? $legacy?->updated_at)?->toIso8601String(),
            'known_before_kickoff' => $snapshot !== null,
            'predicted_spread' => $this->number($outputs['predicted_spread'] ?? null),
            'predicted_total' => $this->number($outputs['predicted_total'] ?? null),
            'win_probability' => $this->number($outputs['win_probability'] ?? null),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
