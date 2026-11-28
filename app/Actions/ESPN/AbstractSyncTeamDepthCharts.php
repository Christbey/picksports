<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use App\Services\ProviderData\Refresh\ProviderRefreshCoordinator;
use App\Services\ProviderData\Refresh\RefreshContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

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

        $context = app(ProviderRefreshCoordinator::class)->begin($team, 'depth_chart', $season);
        $priorBudget = $this->espnService->swapRefreshBudget($context->attempt === null ? null : $context->budget);
        try {
            return $this->executeObserved($team, $teamEspnId, $season, $context);
        } catch (\Throwable $error) {
            $context->fail($error);
            throw $error;
        } finally {
            try {
                $context->finish();
            } finally {
                $this->espnService->swapRefreshBudget($priorBudget);
            }
        }
    }

    private function executeObserved(Model $team, string $teamEspnId, int $season, RefreshContext $context): int
    {
        $observedAt = now();
        $payload = $this->espnService->getTeamDepthCharts($teamEspnId, $season);
        if ($payload === null || ! is_array($payload['items'] ?? null)) {
            throw new RuntimeException("Depth chart response unavailable or malformed for team {$teamEspnId} in season {$season}.");
        }
        if ($context->attempt !== null) {
            $rowCount = 0;
            foreach ($payload['items'] as $chart) {
                foreach ((array) ($chart['positions'] ?? []) as $slot) {
                    $rowCount += is_array($slot['athletes'] ?? null) ? count($slot['athletes']) : 0;
                    $context->budget->rows($rowCount);
                }
            }
        }
        $entries = $this->normalizeEntries($payload, (int) $team->getKey(), $season, $observedAt);

        $model = app($this->depthChartModelClass());
        $connection = $model->getConnection();
        if ($team->getConnection()->getName() !== $connection->getName()
            || app($this->playerModelClass())->getConnection()->getName() !== $connection->getName()) {
            throw new RuntimeException('Depth chart models must use one database connection.');
        }

        return $context->commit(true, $entries, fn (): int => $connection->transaction(function () use ($connection, $model, $team, $teamEspnId, $season, $payload, $entries, $observedAt, $context): int {
            $currentTeam = $team->newQuery()->whereKey($team->getKey())->lockForUpdate()->first();
            if ($currentTeam === null || (string) $currentTeam->espn_id !== $teamEspnId) {
                throw new RuntimeException('Depth chart team identity changed before replacement.');
            }
            $knownPlayers = array_values(array_unique(array_filter(array_column($entries, 'player_id'))));
            $players = $this->playerModelClass()::on($connection->getName())->whereIn('id', $knownPlayers)
                ->orderBy('id')->lockForUpdate()->get(['id', 'team_id', 'espn_id'])->keyBy('id');
            foreach ($entries as $entry) {
                if ($entry['player_id'] !== null) {
                    $player = $players->get($entry['player_id']);
                    if ($player === null || (int) $player->team_id !== (int) $team->getKey()
                        || (string) $player->espn_id !== $entry['espn_athlete_id']) {
                        throw new RuntimeException('Depth chart player identity changed before replacement.');
                    }
                }
            }
            $this->persistHistoricalSnapshot($currentTeam, $teamEspnId, $season, $payload, $entries, $observedAt, $context);
            $connection->table($model->getTable())->where('team_id', $team->getKey())->where('season', $season)->delete();
            if ($entries !== []) {
                $connection->table($model->getTable())->insert($entries);
            }

            return count($entries);
        }));
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
            if (! is_array($chart) || ! is_array($chart['positions'] ?? null)) {
                throw new RuntimeException('Depth chart positions are missing or malformed.');
            }

            $chartId = trim((string) ($chart['id'] ?? ''));
            $chartName = trim((string) ($chart['name'] ?? ''));
            $positions = is_array($chart['positions'] ?? null) ? $chart['positions'] : [];

            foreach ($positions as $slotKey => $slotData) {
                if (! is_array($slotData) || ! is_array($slotData['athletes'] ?? null)) {
                    throw new RuntimeException('Depth chart athlete list is missing or malformed.');
                }

                $position = is_array($slotData['position'] ?? null) ? $slotData['position'] : [];
                $athletes = is_array($slotData['athletes'] ?? null) ? $slotData['athletes'] : [];

                foreach ($athletes as $athleteEntry) {
                    if (! is_array($athleteEntry)) {
                        throw new RuntimeException('Depth chart athlete entry is malformed.');
                    }

                    $espnAthleteId = $this->extractAthleteEspnId($athleteEntry);
                    $playerId = $espnAthleteId !== null ? ($playerMap[$espnAthleteId] ?? null) : null;
                    if ($espnAthleteId === null || preg_match('/\A[1-9][0-9]*\z/', $espnAthleteId) !== 1
                        || filter_var($athleteEntry['rank'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
                        throw new RuntimeException('Depth chart athlete identity or rank is invalid.');
                    }
                    $depthRank = (int) $athleteEntry['rank'];
                    $slotOrder = null;
                    if (isset($athleteEntry['slot'])) {
                        $slotOrder = filter_var($athleteEntry['slot'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
                        if ($slotOrder === false) {
                            throw new RuntimeException('Depth chart slot order is invalid.');
                        }
                    }

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
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
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
        CarbonInterface $observedAt,
        ?RefreshContext $context = null
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
