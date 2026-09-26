<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;

class NflSpreadValidationDataset
{
    /** Read-only: never regenerate forecasts or fall back to mutable game odds. */
    public function load(int $fromSeason, int $toSeason): array
    {
        $rows = $excluded = [];
        $gamesSeen = 0;
        Game::query()->select(['id', 'sport_event_id', 'season', 'week', 'home_score', 'away_score', 'result_updated_at'])
            ->with('sportEvent:id,starts_at')
            ->whereBetween('season', [$fromSeason, $toSeason])
            ->whereIn('season_type', ['2', 'regular'])
            ->where('status', 'STATUS_FINAL')
            ->chunkById(100, function ($games) use (&$rows, &$excluded, &$gamesSeen) {
                $snapshots = PredictionFeatureSnapshot::query()
                    ->select(['id', 'game_id', 'model_version', 'generated_at', 'features_available_at', 'pregame_safe', 'availability_status',
                        'outputs->predicted_spread as model_margin',
                        'outputs->bookmaker_home_spread as output_home_line',
                        'outputs->market_spread as output_market_margin',
                        'market_context->vegas_spread as context_vegas_line',
                        'market_context->bookmaker_home_line as context_home_line',
                        'market_context->market_spread as context_market_margin',
                        'market_context->home_spread as context_home_margin',
                        'model_metadata->legacy->spread as legacy_margin',
                    ])
                    ->where('sport', 'nfl')->where('prediction_table', 'nfl_predictions')
                    ->where('pregame_safe', true)->where('availability_status', 'observed_pregame')
                    ->whereColumn('features_available_at', '<=', 'generated_at')
                    ->whereIn('game_id', $games->modelKeys())->orderByDesc('generated_at')->orderByDesc('id')
                    ->get()->groupBy('game_id');
                foreach ($games as $game) {
                    $gamesSeen++;
                    $reason = 'no_eligible_frozen_forecast';
                    $kickoff = $game->sportEvent?->starts_at;
                    if ($kickoff === null || $game->result_updated_at === null || $game->home_score === null || $game->away_score === null) {
                        $excluded['missing_canonical_start_or_result'] = ($excluded['missing_canonical_start_or_result'] ?? 0) + 1;

                        continue;
                    }
                    foreach ($snapshots->get($game->id, collect()) as $snapshot) {
                        if (! $snapshot->pregame_safe || $snapshot->availability_status !== 'observed_pregame'
                            || $snapshot->generated_at === null || $snapshot->generated_at->gte($kickoff)
                            || $snapshot->features_available_at === null || $snapshot->features_available_at->gt($snapshot->generated_at)) {
                            continue;
                        }
                        $margin = $this->numeric($snapshot->model_margin);
                        $line = $this->numeric($snapshot->output_home_line)
                            ?? $this->numeric($snapshot->context_home_line)
                            ?? $this->numeric($snapshot->context_vegas_line);
                        $marketMargin = $this->numeric($snapshot->context_market_margin)
                            ?? $this->numeric($snapshot->context_home_margin)
                            ?? $this->numeric($snapshot->output_market_margin);
                        if ($line === null && is_numeric($marketMargin)) {
                            $line = -(float) $marketMargin;
                        }
                        if (! is_numeric($margin) || ! is_numeric($line)) {
                            $reason = 'missing_frozen_margin_or_line';

                            continue;
                        }
                        if (is_numeric($marketMargin) && abs((float) $marketMargin + (float) $line) > 0.0001) {
                            $reason = 'conflicting_frozen_line_conventions';

                            continue;
                        }
                        $legacy = $this->numeric($snapshot->legacy_margin);
                        $row = [
                            'game_id' => $game->id, 'snapshot_id' => $snapshot->id,
                            'season' => $game->season, 'week' => $game->week, 'model_version' => $snapshot->model_version,
                            'kickoff' => $kickoff->toIso8601String(), 'generated_at' => $snapshot->generated_at->toIso8601String(),
                            'result_available_at' => $game->result_updated_at->toIso8601String(),
                            // This timestamps observation of the frozen line, not the provider's fetch time.
                            'quote_at' => $snapshot->generated_at->toIso8601String(),
                            'line_source' => 'observed_prediction_snapshot', 'line_freshness_verified' => false,
                            'home_line' => (float) $line, 'model_margin' => (float) $margin,
                            'actual_margin' => (float) $game->home_score - (float) $game->away_score,
                            // A fixed benchmark reconstructed from inputs actually saved before kickoff.
                            'elo_market_margin' => is_numeric($legacy) ? ((float) $legacy - (float) $line) / 2 : null,
                            // No validated EPA candidate has been collected yet. Never substitute custom EPA.
                            'validated_epa_market_margin' => null,
                        ];
                        $rows[] = $row;

                        continue 2;
                    }
                    $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
                }
            });

        return ['rows' => $rows, 'games_seen' => $gamesSeen, 'excluded' => $excluded];
    }

    private function numeric(mixed $value): ?float
    {
        // MySQL JSON_UNQUOTE can return the string "null"; never coerce it to zero.
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
