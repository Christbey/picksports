<?php

namespace App\Services\NFL;

use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\GameDepthChartLink;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

class GameDepthChartLinkRecorder
{
    public function record(Game $game, PredictionFeatureSnapshot $revision): void
    {
        if ($revision->sport !== 'nfl' || (int) $revision->game_id !== (int) $game->id
            || $revision->generated_at === null) {
            return;
        }

        $kickoff = $game->sportEvent?->starts_at;
        if (! $kickoff && $game->game_date && $game->game_time) {
            $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->getRawOriginal('game_date'), $game->game_time);
        }
        if (! $kickoff) {
            return;
        }

        $bySide = [];
        foreach ($this->links($revision->model_metadata ?? []) as $evidence) {
            $side = $evidence['side'] ?? null;
            if (in_array($side, ['home', 'away'], true)) {
                $bySide[$side][] = $evidence;
            }
        }

        foreach ($bySide as $side => $candidates) {
            $evidence = $candidates[0];
            // Repeated identical evidence is harmless; conflicting selections are not.
            if (array_filter($candidates, fn (array $candidate): bool => $candidate != $evidence)) {
                continue;
            }
            if (! in_array($side, ['home', 'away'], true)
                || (int) ($evidence['game_id'] ?? 0) !== (int) $game->id
                || (int) ($evidence['team_id'] ?? 0) !== (int) $game->{$side.'_team_id'}
                || ! is_string($evidence['as_of'] ?? null)
                || trim($evidence['as_of']) === '') {
                continue;
            }
            try {
                $asOf = CarbonImmutable::parse($evidence['as_of']);
            } catch (InvalidFormatException) {
                continue;
            }
            $snapshot = DepthChartSnapshot::find($evidence['snapshot_id'] ?? null);
            if (! $snapshot || (int) $snapshot->team_id !== (int) $evidence['team_id']
                || (int) $snapshot->season !== (int) $game->season
                || $snapshot->snapshot_uuid !== ($evidence['snapshot_uuid'] ?? null)
                || ! $snapshot->observed_at || $snapshot->observed_at->gte($kickoff)
                || $snapshot->observed_at->gt($asOf) || $asOf->gt($kickoff)
                || $asOf->gt($revision->generated_at)
                || $snapshot->source_updated_at?->gt($asOf)) {
                continue;
            }

            // A depth chart is projection evidence, never proof of who actually started.
            GameDepthChartLink::firstOrCreate([
                'prediction_feature_snapshot_id' => $revision->id,
                'side' => $side,
            ], [
                'game_id' => $game->id,
                'team_id' => $snapshot->team_id,
                'snapshot_id' => $snapshot->id,
                'observed_at' => $snapshot->observed_at,
                'as_of' => $asOf,
                'selected_at' => now(),
                'source' => $snapshot->provider,
                'identity_status' => 'projected_not_confirmed_starter',
                'selection_mode' => now()->gte($kickoff) ? 'historical_reconstruction' : 'observed_pregame',
                'evidence' => $evidence,
            ]);
        }
    }

    private function links(array $metadata): iterable
    {
        foreach ($metadata as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === 'depth_chart_game_link') {
                yield $value;
            } else {
                yield from $this->links($value);
            }
        }
    }
}
