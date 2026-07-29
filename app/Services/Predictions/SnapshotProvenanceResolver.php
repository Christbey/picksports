<?php

namespace App\Services\Predictions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SnapshotProvenanceResolver
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function resolve(Model $game, array $snapshot, mixed $generatedAt): array
    {
        $generatedAt = CarbonImmutable::parse($generatedAt);
        $gameStartAt = $this->gameStartAt($game);
        $runType = (string) ($snapshot['run_type'] ?? 'prediction');
        $verifiedReconstruction = (bool) ($snapshot['point_in_time_verified'] ?? false);
        $observedPregame = $gameStartAt !== null && $generatedAt->lte($gameStartAt);

        $pregameSafe = array_key_exists('pregame_safe', $snapshot)
            ? (bool) $snapshot['pregame_safe']
            : ($runType !== 'historical_reconstruction' && $observedPregame);

        $availabilityStatus = (string) ($snapshot['availability_status'] ?? match (true) {
            $verifiedReconstruction => 'verified_reconstruction',
            $observedPregame => 'observed_pregame',
            $gameStartAt === null => 'game_start_unknown',
            default => 'after_game_start',
        });

        $featuresAvailableAt = $snapshot['features_available_at']
            ?? ($verifiedReconstruction ? $gameStartAt : $generatedAt);

        return [
            'game_start_at' => $gameStartAt,
            'features_available_at' => $featuresAvailableAt,
            'pregame_safe' => $pregameSafe,
            'availability_status' => $availabilityStatus,
            'source_timestamps' => $snapshot['source_timestamps'] ?? null,
            'lineage_metadata' => array_filter([
                'run_type' => $runType,
                'historical_profile' => $snapshot['historical_profile'] ?? null,
                'point_in_time_verified' => $verifiedReconstruction,
                'observed_before_game_start' => $observedPregame,
                'verification_method' => $snapshot['verification_method'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    public function gameStartAt(Model $game): ?CarbonImmutable
    {
        if (empty($game->game_date)) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $date = Carbon::parse($game->game_date, $timezone)->toDateString();
        $time = empty($game->game_time)
            ? '23:59:59'
            : Carbon::parse($game->game_time, $timezone)->format('H:i:s');

        return CarbonImmutable::parse("{$date} {$time}", $timezone);
    }
}
