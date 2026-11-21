<?php

namespace App\Services\NBA\Injuries;

use App\Models\NBA\PlayerInjuryObservation;
use App\Models\NBA\PlayerInjurySnapshot;
use App\Models\NBA\Team;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class InjurySnapshotRecorder
{
    public const VERSION = 'espn-nba-injuries-v1';

    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    /** Caller owns the team lock/transaction, including the current-row projection. */
    public function record(Team $team, array $capture, array $entries): PlayerInjuryObservation
    {
        $issues = array_values(array_unique($capture['issues']));
        sort($issues);
        $payloads = array_map(fn (array $response): mixed => $response['payload'], $capture['responses']);
        $payloadHash = $this->hasher->hash($payloads);
        $material = array_map(fn (array $row): array => [
            ...array_intersect_key($row, array_flip(['player_id', 'espn_athlete_id', 'injury_key', 'status', 'detail', 'type', 'return_date', 'issues'])),
            'provider_injury_date' => $row['raw_payload']['injuryDate'] ?? null,
            'details' => $row['raw_payload']['details'] ?? null,
            'short_comment' => $row['raw_payload']['shortComment'] ?? null,
            'long_comment' => $row['raw_payload']['longComment'] ?? null,
        ], $entries);
        usort($material, fn ($left, $right) => strcmp($this->hasher->encode($left), $this->hasher->encode($right)));
        $materialHash = $this->hasher->hash([$team->id, $capture['source_type'], $capture['outcome'], $issues, $material]);
        $snapshotHash = $this->hasher->hash([self::VERSION, $capture['source_url'], $capture['outcome'], $payloadHash, $issues, $entries]);
        $snapshot = PlayerInjurySnapshot::query()->firstOrCreate(['team_id' => $team->id, 'snapshot_hash' => $snapshotHash], [
            'provider' => 'espn', 'source_type' => $capture['source_type'], 'source_url' => $capture['source_url'],
            'normalizer_version' => self::VERSION, 'payload_hash' => $payloadHash, 'material_hash' => $materialHash,
            'outcome' => $capture['outcome'], 'entry_count' => count($entries), 'raw_payload' => $payloads,
            'issues' => $issues, 'created_at' => now(),
        ]);
        if ($snapshot->wasRecentlyCreated) {
            $snapshot->entries()->createMany(array_map(fn (array $entry): array => [...$entry, 'created_at' => now()], $entries));
        }
        $metadata = array_map(function (array $response): array {
            unset($response['payload']);

            return $response;
        }, $capture['responses']);
        // All required payloads must have a known original network observation.
        $required = array_filter($capture['responses'], fn ($response) => $response['required']);
        $times = array_column($required, 'fetched_at');
        $observedAt = $required !== [] && count(array_filter($times)) === count($required)
            ? collect($times)->map(fn ($time) => CarbonImmutable::parse($time))->sortBy(fn ($time) => $time->timestamp)->first()
            : null;

        return PlayerInjuryObservation::query()->create([
            'acquisition_sequence' => $capture['acquisition_sequence'], 'projection_applied' => $capture['projection_applied'],
            'observation_uuid' => (string) Str::uuid(), 'team_id' => $team->id, 'snapshot_id' => $snapshot->id,
            'outcome' => $capture['outcome'], 'started_at' => $capture['started_at'], 'completed_at' => now(),
            'observed_at' => $observedAt?->setTimezone((string) config('app.timezone', 'UTC')),
            'cache_hit' => collect($metadata)->contains(fn ($response) => $response['cache_hit']),
            'fetch_metadata' => $metadata, 'issues' => $issues,
        ]);
    }
}
