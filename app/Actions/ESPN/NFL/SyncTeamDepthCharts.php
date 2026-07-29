<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncTeamDepthCharts;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Player;
use App\Models\NFL\Team;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncTeamDepthCharts extends AbstractSyncTeamDepthCharts
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const DEPTH_CHART_MODEL_CLASS = DepthChartEntry::class;

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    protected function persistHistoricalSnapshot(
        Model $team,
        string $teamEspnId,
        int $season,
        ?array $payload,
        array $entries,
        CarbonInterface $observedAt
    ): void {
        $payload ??= [];
        $encodedPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $sourceUpdatedAt = $this->latestSourceUpdatedAt($payload);

        DB::transaction(function () use (
            $team,
            $teamEspnId,
            $season,
            $payload,
            $encodedPayload,
            $entries,
            $observedAt,
            $sourceUpdatedAt
        ): void {
            $snapshot = DepthChartSnapshot::query()->create([
                'snapshot_uuid' => (string) Str::uuid(),
                'team_id' => (int) $team->getKey(),
                'espn_team_id' => $teamEspnId,
                'season' => $season,
                'provider' => 'espn',
                'observed_at' => $observedAt,
                'source_updated_at' => $sourceUpdatedAt,
                'payload_hash' => hash('sha256', $encodedPayload),
                'entry_count' => count($entries),
                'raw_payload' => $payload,
            ]);

            $snapshot->entries()->createMany(array_map(
                function (array $entry) use ($observedAt, $sourceUpdatedAt): array {
                    $snapshotEntry = Arr::only($entry, [
                        'player_id',
                        'espn_depth_chart_id',
                        'depth_chart_name',
                        'position_slot_key',
                        'position_espn_id',
                        'position_code',
                        'position_name',
                        'position_display_name',
                        'espn_athlete_id',
                        'slot_order',
                        'depth_rank',
                        'is_starter',
                    ]);
                    $snapshotEntry['observed_at'] = $observedAt;
                    $snapshotEntry['source_updated_at'] = $sourceUpdatedAt;
                    $snapshotEntry['raw_payload'] = json_decode(
                        (string) $entry['raw_payload'],
                        true,
                        flags: JSON_THROW_ON_ERROR
                    );

                    return $snapshotEntry;
                },
                $entries
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function latestSourceUpdatedAt(array $payload): ?CarbonInterface
    {
        $latest = null;

        array_walk_recursive($payload, function (mixed $value, string|int $key) use (&$latest): void {
            if (
                ! is_string($key)
                || ! in_array(strtolower($key), ['lastupdated', 'lastmodified', 'updatedat', 'timestamp'], true)
                || ! is_string($value)
                || trim($value) === ''
            ) {
                return;
            }

            try {
                $candidate = Carbon::parse($value);
            } catch (\Throwable) {
                return;
            }

            if ($latest === null || $candidate->isAfter($latest)) {
                $latest = $candidate;
            }
        });

        return $latest?->setTimezone(config('app.timezone'));
    }
}
