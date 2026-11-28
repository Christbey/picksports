<?php

namespace App\Services\MLB;

use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\MLB\MlbGameStart;
use App\Support\MLB\MlbQuoteTime;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/** Future capture consistency/availability evidence, not full provider or model attestation. */
final class MlbSnapshotCaptureIntegrity
{
    public const SCHEMA = 'mlb-entry-capture-v1';

    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    public function marker(Model $prediction, Game $game, array $features, array $outputs, ?array $market, CarbonInterface $generatedAt, array $provenance, array $versions): array
    {
        $captured = CarbonImmutable::now('UTC');
        $suppliedAvailability = CarbonImmutable::parse($provenance['features_available_at']);
        $available = $suppliedAvailability->gt($captured) ? $suppliedAvailability : $captured;
        $reasons = [];
        if (MlbGameStart::for($game) === null || ! MlbGameStart::observedBy($game, $captured)) {
            $reasons[] = 'start_observation_unavailable';
        }
        if (! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            $reasons[] = 'game_not_pregame_at_capture';
        }
        if (($provenance['pregame_safe'] ?? false) !== true || ($provenance['availability_status'] ?? null) !== 'observed_pregame'
            || $generatedAt->gt($captured) || $suppliedAvailability->gt($captured)
            || MlbGameStart::for($game) === null || $captured->gte(MlbGameStart::for($game))) {
            $reasons[] = 'capture_not_observed_pregame';
        }
        if (MlbGameStart::canonical($game) === null || LegacyTimestampCarrier::forInstant($captured) === null) {
            $reasons[] = 'legacy_carrier_unrepresentable';
        }
        $quoteProof = null;
        if ($this->usesMarket($market)) {
            $id = data_get($market, 'safety.snapshot_id');
            $quote = is_numeric($id) ? GameOddsSnapshot::query()->find((int) $id) : null;
            if ($quote === null || ! MlbQuoteTime::pregame($game, $quote, $captured)) {
                $reasons[] = 'market_observation_unavailable';
            } else {
                $quoteProof = ['snapshot_id' => (int) $quote->id, 'marker_hash' => data_get($quote->market_context, '_mlb_time.hash')];
            }
        }
        $body = ['schema' => self::SCHEMA, 'timing_policy' => MlbGameStart::TIMING_POLICY,
            'capture_id' => (string) Str::uuid(), 'prediction_table' => $prediction->getTable(), 'prediction_id' => (int) $prediction->getKey(),
            'game_id' => (int) $game->id, 'status_at_capture' => $game->status, 'game_identity' => $this->gameIdentity($game), 'versions' => $versions,
            'content_hash' => $this->payloadHash($features, $outputs, $market),
            'start_observation' => MlbGameStart::capture($game, $captured),
            'captured_at_utc' => $captured->format('Y-m-d\TH:i:s.u\Z'),
            'generated_at_utc' => $generatedAt->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'available_at_utc' => $available->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'quote_proof' => $quoteProof, 'qualified' => $reasons === [], 'reasons' => array_values(array_unique($reasons))];

        return $body + ['marker_hash' => $this->hasher->hash($body)];
    }

    /** Pure inspection: callers preload current Game/optional quote in bounded batches. */
    public function inspect(PredictionFeatureSnapshot $snapshot, Game $game, ?GameOddsSnapshot $quote = null): array
    {
        $marker = data_get($snapshot->model_metadata, 'mlb_capture_integrity');
        if (! is_array($marker) || ($marker['schema'] ?? null) !== self::SCHEMA) {
            return ['qualified' => false, 'reason' => 'legacy_capture_unverified'];
        }
        try {
            if (! is_string($marker['marker_hash'] ?? null) || ! hash_equals($marker['marker_hash'], $this->hasher->hash(array_diff_key($marker, ['marker_hash' => true])))
                || ($marker['timing_policy'] ?? null) !== MlbGameStart::TIMING_POLICY
                || ! is_string($marker['capture_id'] ?? null) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $marker['capture_id']) !== 1
                || $snapshot->sport !== 'mlb' || $snapshot->prediction_table !== 'mlb_predictions'
                || ($marker['prediction_table'] ?? null) !== $snapshot->prediction_table || ($marker['prediction_id'] ?? null) !== (int) $snapshot->prediction_id
                || ($marker['game_id'] ?? null) !== (int) $game->id || (int) $snapshot->game_id !== (int) $game->id
                || $this->hasher->hash($marker['game_identity'] ?? null) !== $this->hasher->hash($this->gameIdentity($game))
                || $this->hasher->hash($marker['versions'] ?? null) !== $this->hasher->hash(['model' => $snapshot->model_version, 'feature' => $snapshot->feature_version, 'blend' => $snapshot->blend_version])
                || ! is_string($marker['content_hash'] ?? null)
                || ! hash_equals($marker['content_hash'], $this->payloadHash((array) $snapshot->features, (array) $snapshot->outputs, is_array($snapshot->market_context) ? $snapshot->market_context : null))) {
                return ['qualified' => false, 'reason' => 'capture_integrity_mismatch'];
            }
            foreach (['captured_at_utc', 'generated_at_utc', 'available_at_utc'] as $key) {
                if (! is_string($marker[$key] ?? null) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $marker[$key]) !== 1) {
                    return ['qualified' => false, 'reason' => 'capture_clock_invalid'];
                }
            }
            $captured = $this->utc($marker['captured_at_utc']);
            $generated = $this->utc($marker['generated_at_utc']);
            $available = $this->utc($marker['available_at_utc']);
            if (($marker['qualified'] ?? false) !== true || ! in_array($marker['status_at_capture'] ?? null, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
                || ! $snapshot->pregame_safe || $snapshot->availability_status !== 'observed_pregame'
                || ! MlbGameStart::capturedFor($game, $marker['start_observation'] ?? null)
                || $generated->gt($captured) || $available->lt($captured) || $available->gte(MlbGameStart::for($game))
                || $snapshot->generated_at?->getTimestamp() !== $generated->getTimestamp()
                || $snapshot->features_available_at?->getTimestamp() !== $available->getTimestamp()
                || $snapshot->game_start_at?->getTimestamp() !== MlbGameStart::for($game)?->getTimestamp()) {
                return ['qualified' => false, 'reason' => 'capture_availability_or_context_mismatch'];
            }
            if ($this->usesMarket(is_array($snapshot->market_context) ? $snapshot->market_context : null)) {
                if ($quote === null || (int) data_get($marker, 'quote_proof.snapshot_id') !== (int) $quote->id
                    || data_get($marker, 'quote_proof.marker_hash') !== data_get($quote->market_context, '_mlb_time.hash')
                    || ! MlbQuoteTime::pregame($game, $quote, $captured)) {
                    return ['qualified' => false, 'reason' => 'capture_market_evidence_changed'];
                }
            }

            return ['qualified' => true, 'reason' => null, 'marker' => $marker, 'captured_at' => $captured];
        } catch (Throwable) {
            return ['qualified' => false, 'reason' => 'capture_integrity_invalid'];
        }
    }

    public function payloadHash(array $features, array $outputs, ?array $market): string
    {
        return $this->hasher->hash(['features' => $features, 'outputs' => $outputs, 'market_context' => $market]);
    }

    private function gameIdentity(Game $game): array
    {
        return ['espn_event_id' => $game->espn_event_id, 'season' => $game->season, 'season_type' => (string) $game->season_type,
            'home_team_id' => $game->home_team_id === null ? null : (int) $game->home_team_id,
            'away_team_id' => $game->away_team_id === null ? null : (int) $game->away_team_id,
            'game_date' => $game->game_date?->format('Y-m-d'), 'game_time' => $game->game_time];
    }

    private function utc(string $value): CarbonImmutable
    {
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($instant === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new \InvalidArgumentException('Malformed owned capture clock.');
        }

        return CarbonImmutable::instance($instant);
    }

    private function usesMarket(?array $market): bool
    {
        return ! empty($market['available_markets']) || isset($market['vegas_spread']) || isset($market['market_total']);
    }
}
