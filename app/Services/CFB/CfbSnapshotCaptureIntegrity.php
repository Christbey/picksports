<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/** Checks capture consistency only; this does not certify every feature's provider provenance. */
final class CfbSnapshotCaptureIntegrity
{
    public const SCHEMA = 'cfb-entry-payload-v2';

    public const ALGORITHM = 'canonical-json-sha256-v1';

    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    public function marker(Game $game, array $features, array $outputs, ?array $market, ?CarbonInterface $kickoff): array
    {
        $context = $this->gameIdentity($game);

        $marker = ['schema' => self::SCHEMA, 'algorithm' => self::ALGORITHM,
            'content_hash' => $this->payloadHash($features, $outputs, $market),
            'game_identity' => $context, 'game_identity_hash' => $this->hasher->hash($context),
            'kickoff_at' => $this->literalKickoffReasons($context) === [] ? $kickoff?->toImmutable()->utc()->format('Y-m-d\TH:i:s.u\Z') : null,
            // Actual recording clock; a caller's backdated generated_at cannot create historical certification.
            'captured_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z')];
        $marker['marker_hash'] = $this->hasher->hash($marker);

        return $marker;
    }

    public function inspect(PredictionFeatureSnapshot $snapshot, Game $game): array
    {
        if (strtolower(trim((string) $snapshot->sport)) !== 'cfb' || $snapshot->prediction_table !== 'cfb_predictions'
            || (int) $snapshot->game_id !== (int) $game->id) {
            return ['status' => 'mismatch', 'hash' => $this->payloadHash((array) $snapshot->features, (array) $snapshot->outputs,
                is_array($snapshot->market_context) ? $snapshot->market_context : null), 'reasons' => ['entry_capture_identity_mismatch'], 'kickoff' => null];
        }
        $marker = data_get($snapshot->model_metadata, 'snapshot_integrity');
        $hash = $this->payloadHash((array) $snapshot->features, (array) $snapshot->outputs,
            is_array($snapshot->market_context) ? $snapshot->market_context : null);
        if (! is_array($marker) || ($marker['schema'] ?? null) !== self::SCHEMA || ($marker['algorithm'] ?? null) !== self::ALGORITHM) {
            return ['status' => 'legacy_unverified', 'hash' => $hash, 'reasons' => ['entry_capture_integrity_unknown'], 'kickoff' => null];
        }
        $identity = $this->gameIdentity($game);
        $valid = is_string($marker['marker_hash'] ?? null) && hash_equals($marker['marker_hash'], $this->hasher->hash(array_diff_key($marker, ['marker_hash' => true])))
            && is_string($marker['content_hash'] ?? null) && hash_equals($marker['content_hash'], $hash)
            && is_array($marker['game_identity'] ?? null)
            && is_string($marker['game_identity_hash'] ?? null)
            && hash_equals($marker['game_identity_hash'], $this->hasher->hash($marker['game_identity']));
        if (! $valid) {
            return ['status' => 'mismatch', 'hash' => $hash, 'reasons' => ['entry_capture_integrity_mismatch'], 'kickoff' => null];
        }
        if (! hash_equals($marker['game_identity_hash'], $this->hasher->hash($identity))) {
            return ['status' => 'verified_capture_v2', 'hash' => $hash, 'reasons' => ['entry_game_identity_or_timing_changed'], 'kickoff' => null];
        }
        $literalReasons = $this->literalKickoffReasons($marker['game_identity']);
        if ($literalReasons !== []) {
            return ['status' => 'verified_capture_v2', 'hash' => $hash, 'reasons' => $literalReasons, 'kickoff' => null];
        }
        if (! is_int($marker['game_identity']['home_team_id'] ?? null) || $marker['game_identity']['home_team_id'] < 1
            || ! is_int($marker['game_identity']['away_team_id'] ?? null) || $marker['game_identity']['away_team_id'] < 1
            || $marker['game_identity']['home_team_id'] === $marker['game_identity']['away_team_id']) {
            return ['status' => 'verified_capture_v2', 'hash' => $hash, 'reasons' => ['entry_team_identity_unobserved'], 'kickoff' => null];
        }
        try {
            foreach (['captured_at', 'kickoff_at'] as $key) {
                if (! is_string($marker[$key] ?? null) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $marker[$key]) !== 1) {
                    throw new \UnexpectedValueException('Invalid capture chronology.');
                }
            }
            $capture = CarbonImmutable::parse($marker['captured_at']);
            $kickoff = CarbonImmutable::parse($marker['kickoff_at']);
            if ($capture->gte($kickoff) || ! $snapshot->pregame_safe || $snapshot->availability_status !== 'observed_pregame') {
                return ['status' => 'verified_capture_v2', 'hash' => $hash, 'reasons' => ['entry_not_observed_before_kickoff'], 'kickoff' => null];
            }
        } catch (Throwable) {
            return ['status' => 'mismatch', 'hash' => $hash, 'reasons' => ['entry_capture_chronology_invalid'], 'kickoff' => null];
        }

        return ['status' => 'verified_capture_v2', 'hash' => $hash, 'reasons' => [], 'kickoff' => $kickoff];
    }

    public function payloadHash(array $features, array $outputs, ?array $market): string
    {
        return $this->hasher->hash(['features' => $features, 'outputs' => $outputs, 'market_context' => $market]);
    }

    public function gameIdentity(Game $game): array
    {
        return ['table' => $game->getTable(), 'id' => (int) $game->id,
            'espn_event_id' => $game->espn_event_id === null ? null : (string) $game->espn_event_id,
            'season' => (int) $game->season, 'season_type' => (string) $game->season_type,
            'home_team_id' => $game->home_team_id === null ? null : (int) $game->home_team_id,
            'away_team_id' => $game->away_team_id === null ? null : (int) $game->away_team_id,
            'game_date' => substr((string) $game->getRawOriginal('game_date'), 0, 10),
            'game_time' => $game->getRawOriginal('game_time') === null ? null : (string) $game->getRawOriginal('game_time')];
    }

    private function literalKickoffReasons(array $identity): array
    {
        $date = $identity['game_date'] ?? null;
        $time = $identity['game_time'] ?? null;
        $reasons = [];
        if (! is_string($date) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) !== 1
            || ! checkdate((int) ($parts[2] ?? 0), (int) ($parts[3] ?? 0), (int) ($parts[1] ?? 0))) {
            $reasons[] = 'entry_kickoff_date_unobserved';
        }
        if (! is_string($time) || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9](?:\.[0-9]{1,6})?)?\z/', $time) !== 1) {
            $reasons[] = 'entry_kickoff_time_unobserved';
        }

        return $reasons;
    }

    public function gameContextHash(Game $game): string
    {
        return $this->hasher->hash([...$this->gameIdentity($game), 'status' => $game->status,
            'home_score' => $game->home_score === null ? null : (int) $game->home_score,
            'away_score' => $game->away_score === null ? null : (int) $game->away_score]);
    }
}
