<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'nfl_player_injuries';

    /**
     * @param  list<array<string, mixed>>  $injuries
     * @param  list<array<string, mixed>>  $snapshotRows
     */
    protected function persistHistoricalSnapshot(
        Model $team,
        string $teamEspnId,
        array $injuries,
        array $snapshotRows,
        CarbonInterface $observedAt
    ): void {
        $encodedPayload = json_encode(
            $injuries,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $sourceUpdatedAt = collect($snapshotRows)
            ->pluck('source_updated_at')
            ->filter()
            ->sortByDesc(fn (CarbonInterface $timestamp): int => $timestamp->getTimestamp())
            ->first();

        DB::transaction(function () use (
            $team,
            $teamEspnId,
            $injuries,
            $encodedPayload,
            $snapshotRows,
            $observedAt,
            $sourceUpdatedAt
        ): void {
            $snapshot = PlayerInjurySnapshot::query()->create([
                'snapshot_uuid' => (string) Str::uuid(),
                'team_id' => (int) $team->getKey(),
                'espn_team_id' => $teamEspnId,
                'provider' => 'espn',
                'observed_at' => $observedAt,
                'source_updated_at' => $sourceUpdatedAt,
                'payload_hash' => hash('sha256', $encodedPayload),
                'entry_count' => count($snapshotRows),
                'raw_payload' => $injuries,
            ]);

            $snapshot->entries()->createMany($snapshotRows);
        });
    }
}
