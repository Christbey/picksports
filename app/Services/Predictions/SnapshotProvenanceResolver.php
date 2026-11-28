<?php

namespace App\Services\Predictions;

use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Support\MLB\MlbGameStart;
use App\Support\MLB\MlbQuoteTime;
use App\Support\Sports\GameDateTimePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

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
        $startObserved = ! $game instanceof Game || MlbGameStart::observedBy($game, $generatedAt);
        $observedPregame = $gameStartAt !== null && $generatedAt->lt($gameStartAt) && $startObserved;

        $pregameSafe = array_key_exists('pregame_safe', $snapshot)
            ? (bool) $snapshot['pregame_safe']
            : ($runType !== 'historical_reconstruction' && $observedPregame);

        $availabilityStatus = (string) ($snapshot['availability_status'] ?? match (true) {
            $verifiedReconstruction => 'verified_reconstruction',
            $observedPregame => 'observed_pregame',
            $gameStartAt === null => 'game_start_unknown',
            default => 'after_game_start',
        });

        if ($gameStartAt === null) {
            $pregameSafe = false;
            $verifiedReconstruction = false;
            $availabilityStatus = 'game_start_unknown';
        } elseif ($runType !== 'historical_reconstruction' && ! $observedPregame) {
            $pregameSafe = false;
            $verifiedReconstruction = false;
            $availabilityStatus = 'after_game_start';
        }

        if ($game instanceof Game && ! $startObserved) {
            $pregameSafe = false;
            $verifiedReconstruction = false;
            $availabilityStatus = $gameStartAt === null ? 'game_start_unknown' : 'schedule_observation_after_capture';
        }

        if ($game instanceof Game) {
            $market = $snapshot['market_context'] ?? [];
            $usesMarket = ! empty($market['available_markets']) || isset($market['vegas_spread']) || isset($market['market_total']);
            if ($usesMarket) {
                $snapshotId = data_get($market, 'safety.snapshot_id');
                $quote = is_numeric($snapshotId) ? GameOddsSnapshot::query()->find((int) $snapshotId) : null;
                if ($quote === null || ! MlbQuoteTime::pregame($game, $quote, $generatedAt)) {
                    $pregameSafe = false;
                    $verifiedReconstruction = false;
                    $availabilityStatus = 'market_time_unverified';
                }
            }
        }

        $featuresAvailableAt = $snapshot['features_available_at']
            ?? ($verifiedReconstruction ? $gameStartAt : $generatedAt);

        return [
            'game_start_at' => $gameStartAt,
            'features_available_at' => $featuresAvailableAt,
            'pregame_safe' => $pregameSafe,
            'availability_status' => $availabilityStatus,
            'source_timestamps' => $game instanceof Game ? ['scheduled_start_observed_at_utc' => $game->start_observed_at, 'supplied' => $snapshot['source_timestamps'] ?? null] : ($snapshot['source_timestamps'] ?? null),
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
        if ($game instanceof Game) {
            return MlbGameStart::for($game)?->toImmutable();
        }
        $start = GameDateTimePresenter::currentStoredStartsAt($game);

        return $start === null ? null : CarbonImmutable::parse($start, 'UTC');
    }
}
