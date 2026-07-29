<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

abstract class AbstractSyncTeamDepthCharts
{
    protected const TEAM_MODEL_CLASS = '';

    protected const PLAYER_MODEL_CLASS = '';

    protected const DEPTH_CHART_MODEL_CLASS = '';

    public function __construct(protected BaseEspnService $espnService) {}

    public function execute(string $teamEspnId, int $season): int
    {
        $team = $this->findTeamByEspnId($teamEspnId);
        if (! $team) {
            return 0;
        }

        $observedAt = now();
        $payload = $this->espnService->getTeamDepthCharts($teamEspnId, $season);
        $entries = $this->normalizeEntries($payload, (int) $team->getKey(), $season, $observedAt);

        if (is_array($payload)) {
            $this->persistHistoricalSnapshot(
                $team,
                $teamEspnId,
                $season,
                $payload,
                $entries,
                $observedAt
            );
        }

        $table = $this->depthChartTable();
        DB::table($table)
            ->where('team_id', (int) $team->getKey())
            ->where('season', $season)
            ->delete();

        if ($entries === []) {
            return 0;
        }

        DB::table($table)->insert($entries);

        return count($entries);
    }

    public function syncAllTeams(int $season): int
    {
        $teamModel = $this->teamModelClass();
        $teams = $teamModel::query()->get(['espn_id']);
        $total = 0;

        foreach ($teams as $team) {
            $total += $this->execute((string) $team->espn_id, $season);
        }

        return $total;
    }

    protected function normalizeEntries(
        ?array $payload,
        int $teamId,
        int $season,
        ?CarbonInterface $observedAt = null
    ): array {
        $charts = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($charts === []) {
            return [];
        }

        $playerMap = $this->playerIdMapByEspnId($teamId);
        $rows = [];
        $now = $observedAt ?? now();

        foreach ($charts as $chart) {
            if (! is_array($chart)) {
                continue;
            }

            $chartId = trim((string) ($chart['id'] ?? ''));
            $chartName = trim((string) ($chart['name'] ?? ''));
            $positions = is_array($chart['positions'] ?? null) ? $chart['positions'] : [];

            foreach ($positions as $slotKey => $slotData) {
                if (! is_array($slotData)) {
                    continue;
                }

                $position = is_array($slotData['position'] ?? null) ? $slotData['position'] : [];
                $athletes = is_array($slotData['athletes'] ?? null) ? $slotData['athletes'] : [];

                foreach ($athletes as $athleteEntry) {
                    if (! is_array($athleteEntry)) {
                        continue;
                    }

                    $espnAthleteId = $this->extractAthleteEspnId($athleteEntry);
                    $playerId = $espnAthleteId !== null ? ($playerMap[$espnAthleteId] ?? null) : null;
                    $depthRank = max(1, (int) ($athleteEntry['rank'] ?? 1));
                    $slotOrder = isset($athleteEntry['slot']) ? (int) $athleteEntry['slot'] : null;

                    $rows[] = [
                        'team_id' => $teamId,
                        'player_id' => $playerId,
                        'season' => $season,
                        'espn_depth_chart_id' => $chartId !== '' ? $chartId : null,
                        'depth_chart_name' => $chartName !== '' ? $chartName : null,
                        'position_slot_key' => (string) $slotKey,
                        'position_espn_id' => $this->nullableString($position['id'] ?? null),
                        'position_code' => $this->nullableString($position['abbreviation'] ?? null),
                        'position_name' => $this->nullableString($position['name'] ?? null),
                        'position_display_name' => $this->nullableString($position['displayName'] ?? null),
                        'espn_athlete_id' => $espnAthleteId,
                        'slot_order' => $slotOrder,
                        'depth_rank' => $depthRank,
                        'is_starter' => $depthRank === 1,
                        'source_updated_at' => $now,
                        'raw_payload' => json_encode([
                            'depth_chart' => [
                                'id' => $chartId !== '' ? $chartId : null,
                                'name' => $chartName !== '' ? $chartName : null,
                            ],
                            'position' => $position,
                            'entry' => $athleteEntry,
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return $rows;
    }

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
    ): void {}

    protected function extractAthleteEspnId(array $athleteEntry): ?string
    {
        $athlete = is_array($athleteEntry['athlete'] ?? null) ? $athleteEntry['athlete'] : [];
        $id = trim((string) ($athlete['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        $ref = trim((string) ($athlete['$ref'] ?? ''));
        if ($ref !== '' && preg_match('/\/athletes\/(\d+)(?:\?|\/|$)/', $ref, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, int>
     */
    protected function playerIdMapByEspnId(int $teamId): array
    {
        $playerModel = $this->playerModelClass();

        /** @var Collection<int, Model> $players */
        $players = $playerModel::query()
            ->where('team_id', $teamId)
            ->get(['id', 'espn_id']);

        $map = [];

        foreach ($players as $player) {
            $espnId = trim((string) $player->espn_id);
            if ($espnId === '') {
                continue;
            }

            $map[$espnId] = (int) $player->id;
        }

        return $map;
    }

    protected function findTeamByEspnId(string $teamEspnId): ?Model
    {
        $teamModel = $this->teamModelClass();

        return $teamModel::query()->where('espn_id', $teamEspnId)->first();
    }

    protected function depthChartTable(): string
    {
        return app($this->depthChartModelClass())->getTable();
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        return static::TEAM_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function playerModelClass(): string
    {
        return static::PLAYER_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function depthChartModelClass(): string
    {
        return static::DEPTH_CHART_MODEL_CLASS;
    }
}
