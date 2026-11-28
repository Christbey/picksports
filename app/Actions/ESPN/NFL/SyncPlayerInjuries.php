<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use App\Services\ProviderData\Refresh\RefreshContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'nfl_player_injuries';

    protected function supportsPartialProjection(): bool
    {
        // Legacy NFL history has no partial-coverage receipt. Preserve its last auditable projection.
        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $injuries
     * @param  list<array<string, mixed>>  $snapshotRows
     */
    protected function persistHistoricalSnapshot(
        Model $team,
        string $teamEspnId,
        array $injuries,
        array $snapshotRows,
        CarbonInterface $observedAt,
        ?RefreshContext $context = null
    ): void {
        if (! $this->injuryPayloadReliable) {
            return; // This legacy snapshot schema cannot represent an authoritative partial report.
        }
        $connection = $team->getConnection();
        if ((new PlayerInjurySnapshot)->getConnection()->getName() !== $connection->getName()) {
            throw new \RuntimeException('Injury snapshot must use the projection database connection.');
        }
        $actionableInjuries = collect($injuries)
            ->filter(fn (array $injury): bool => $this->isCurrentInjuryActive($this->normalizeInjury($injury)))
            ->values()
            ->all();
        $actionableSnapshotRows = collect($snapshotRows)
            ->filter(fn (array $row): bool => $this->isCurrentInjuryActive($row))
            ->values()
            ->all();
        $encodedPayload = json_encode(
            $actionableInjuries,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $payloadHash = hash('sha256', $encodedPayload);
        $sourceUpdatedAt = collect($actionableSnapshotRows)
            ->pluck('source_updated_at')
            ->filter()
            ->sortByDesc(fn (CarbonInterface $timestamp): int => $timestamp->getTimestamp())
            ->first();

        $connection->transaction(function () use (
            $team,
            $connection,
            $context,
            $teamEspnId,
            $actionableInjuries,
            $actionableSnapshotRows,
            $observedAt,
            $sourceUpdatedAt,
            $payloadHash,
        ): void {
            $latest = PlayerInjurySnapshot::on($connection->getName())
                ->where('team_id', (int) $team->getKey())
                ->latest('observed_at')
                ->latest('id')->lockForUpdate()
                ->first();
            if ($latest !== null && hash_equals((string) $latest->payload_hash, $payloadHash)) {
                $context?->recordHistory($latest);

                return;
            }

            $snapshot = PlayerInjurySnapshot::on($connection->getName())->create([
                'snapshot_uuid' => (string) Str::uuid(),
                'team_id' => (int) $team->getKey(),
                'espn_team_id' => $teamEspnId,
                'provider' => 'espn',
                'observed_at' => $observedAt,
                'source_updated_at' => $sourceUpdatedAt,
                'payload_hash' => $payloadHash,
                'entry_count' => count($actionableSnapshotRows),
                'raw_payload' => $actionableInjuries,
            ]);

            $snapshot->entries()->createMany($actionableSnapshotRows);
            $context?->recordHistory($snapshot);
        });
    }

    /**
     * @param  array<string, mixed>  $normalizedInjury
     */
    protected function isCurrentInjuryActive(array $normalizedInjury): bool
    {
        $status = strtolower(trim((string) ($normalizedInjury['status'] ?? '')));
        $type = strtolower(trim((string) ($normalizedInjury['type'] ?? '')));

        if ($status === '' || in_array($status, ['active', 'available', 'healthy'], true)) {
            return false;
        }

        return ! str_contains($type, 'injury_status_active');
    }
}
