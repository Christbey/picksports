<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use App\Services\ProviderData\Refresh\ProviderRefreshCoordinator;
use App\Services\ProviderData\Refresh\RefreshContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = '';

    protected const TEAM_MODEL_CLASS = '';

    protected const INJURY_TABLE = '';

    protected bool $injuryPayloadObserved = false;

    protected bool $injuryPayloadReliable = true;

    /**
     * @var array<string, array<string, mixed>|null>
     */
    protected array $refCache = [];

    public function __construct(protected BaseEspnService $espnService) {}

    protected function findTeamByEspnId(string $teamEspnId): ?Model
    {
        $teamModel = $this->teamModelClass();

        return $teamModel::query()->where('espn_id', $teamEspnId)->first();
    }

    public function execute(string $teamEspnId): int
    {
        $this->refCache = [];
        $team = $this->findTeamByEspnId($teamEspnId);
        if (! $team) {
            $this->injuryPayloadObserved = false;
            $this->injuryPayloadReliable = false;

            return 0;
        }

        $context = app(ProviderRefreshCoordinator::class)->begin($team, 'injuries', 0);
        $priorBudget = $this->espnService->swapRefreshBudget($context->attempt === null ? null : $context->budget);
        try {
            return $this->executeObserved($team, $teamEspnId, $context);
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

    private function executeObserved(Model $team, string $teamEspnId, RefreshContext $context): int
    {
        $observedAt = now();
        $injuries = $this->extractTeamInjuries($teamEspnId);
        if (! $this->injuryPayloadObserved) {
            return 0;
        }

        $teamId = (int) $team->getKey();
        $playerMap = $this->playerIdMapByEspnId($teamId);
        if ($context->attempt !== null) {
            $context->budget->rows(count($injuries));
        }
        $rows = [];
        $snapshotRows = [];
        $seenIdentities = [];

        foreach ($injuries as $injury) {
            $playerEspnId = $this->extractAthleteEspnId($injury);
            $normalized = $this->normalizeInjury($injury);
            if ($playerEspnId === null || $normalized['status'] === null) {
                $this->injuryPayloadReliable = false;

                continue;
            }
            $identity = $playerEspnId.':'.$this->injuryKey($normalized);
            if (isset($seenIdentities[$identity])) {
                $this->injuryPayloadReliable = false;
                throw new \RuntimeException('Duplicate injury identity in one source observation.');
            }
            $seenIdentities[$identity] = true;
            if (! isset($playerMap[$playerEspnId])) {
                $this->injuryPayloadReliable = false;
            }
            $snapshotRows[] = [
                'player_id' => $playerEspnId !== null ? ($playerMap[$playerEspnId] ?? null) : null,
                'espn_athlete_id' => $playerEspnId,
                'injury_key' => $this->injuryKey($normalized),
                'espn_injury_id' => $normalized['espn_injury_id'],
                'status' => $normalized['status'],
                'detail' => $normalized['detail'],
                'type' => $normalized['type'],
                'injury_date' => $normalized['injury_date'],
                'return_date' => $normalized['return_date'],
                'observed_at' => $observedAt,
                'source_updated_at' => $this->snapshotSourceUpdatedAt($injury),
                'raw_payload' => $injury,
            ];

            if ($playerEspnId === null || ! isset($playerMap[$playerEspnId])) {
                continue;
            }

            $rows[] = [
                'player_id' => $playerMap[$playerEspnId],
                'team_id' => $teamId,
                'injury_key' => $this->injuryKey($normalized),
                'espn_injury_id' => $normalized['espn_injury_id'],
                'status' => $normalized['status'],
                'detail' => $normalized['detail'],
                'type' => $normalized['type'],
                'injury_date' => $normalized['injury_date'],
                'return_date' => $normalized['return_date'],
                'source_updated_at' => $normalized['source_updated_at'],
                'is_active' => $this->isCurrentInjuryActive($normalized),
                'raw_payload' => json_encode($injury, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $observedAt,
                'updated_at' => $observedAt,
            ];
        }

        if (! $this->injuryPayloadReliable && ! $this->supportsPartialProjection()) {
            return 0;
        }
        $connection = $team->getConnection();
        if (app($this->playerModelClass())->getConnection()->getName() !== $connection->getName()) {
            throw new \RuntimeException('Injury team and player models must share one database connection.');
        }

        return $context->commit($this->injuryPayloadReliable, $snapshotRows, fn (): int => $connection->transaction(function () use ($connection, $team, $teamEspnId, $teamId, $observedAt, $rows, $injuries, $snapshotRows, $context): int {
            $currentTeam = $team->newQuery()->whereKey($teamId)->lockForUpdate()->first();
            if ($currentTeam === null || (string) $currentTeam->espn_id !== $teamEspnId) {
                throw new \RuntimeException('Injury team identity changed before projection.');
            }
            $players = $this->playerModelClass()::on($connection->getName())->whereIn('id', array_column($rows, 'player_id'))
                ->orderBy('id')->lockForUpdate()->get(['id', 'team_id', 'espn_id'])->keyBy('id');
            foreach ($snapshotRows as $row) {
                if ($row['player_id'] !== null) {
                    $player = $players->get($row['player_id']);
                    if ($player === null || (int) $player->team_id !== $teamId || (string) $player->espn_id !== $row['espn_athlete_id']) {
                        throw new \RuntimeException('Injury player identity changed before projection.');
                    }
                }
            }
            $this->persistHistoricalSnapshot($currentTeam, $teamEspnId, $injuries, $snapshotRows, $observedAt, $context);
            $existingRows = $connection->table($this->injuryTable())
                ->where('team_id', $teamId)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (object $row): string => $row->player_id.':'.$row->injury_key);
            $presentKeys = collect($rows)
                ->mapWithKeys(fn (array $row): array => [$row['player_id'].':'.$row['injury_key'] => true]);

            if ($this->injuryPayloadReliable) {
                $removedIds = $existingRows
                    ->filter(fn (object $row, string $key): bool => (bool) $row->is_active && $this->canRetireInjury($row) && ! $presentKeys->has($key))
                    ->pluck('id');

                if ($removedIds->isNotEmpty()) {
                    $connection->table($this->injuryTable())
                        ->whereIn('id', $removedIds)
                        ->update(['is_active' => false, 'updated_at' => $observedAt]);
                }
            }

            $changedRows = [];
            $unchangedIds = [];
            foreach ($rows as $row) {
                $existing = $existingRows->get($row['player_id'].':'.$row['injury_key']);
                if ($existing && $this->currentInjuryMatches($existing, $row, $connection)) {
                    $unchangedIds[] = $existing->id;
                } else {
                    $changedRows[] = $row;
                }
            }

            if ($unchangedIds !== []) {
                $connection->table($this->injuryTable())
                    ->whereIn('id', $unchangedIds)
                    ->update(['updated_at' => $observedAt]);
            }

            if ($changedRows === []) {
                return collect($rows)->where('is_active', true)->count();
            }

            $connection->table($this->injuryTable())->upsert(
                $changedRows,
                ['player_id', 'injury_key'],
                [
                    'team_id',
                    'espn_injury_id',
                    'status',
                    'detail',
                    'type',
                    'injury_date',
                    'return_date',
                    'source_updated_at',
                    'is_active',
                    'raw_payload',
                    'updated_at',
                ]
            );

            return collect($rows)->where('is_active', true)->count();
        }));
    }

    public function lastSyncReliable(): bool
    {
        return $this->injuryPayloadObserved && $this->injuryPayloadReliable;
    }

    protected function supportsPartialProjection(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function currentInjuryMatches(object $existing, array $row, Connection $connection): bool
    {
        foreach ($row as $column => $value) {
            if (in_array($column, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $stored = $existing->{$column};
            if ($column === 'raw_payload') {
                if ($this->orderedPayload(json_decode((string) $stored, true)) !== $this->orderedPayload(json_decode((string) $value, true))) {
                    return false;
                }

                continue;
            }

            $prepared = $connection->prepareBindings([$value])[0];
            if (($stored === null) !== ($prepared === null) || (string) $stored !== (string) $prepared) {
                return false;
            }
        }

        return true;
    }

    private function orderedPayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->orderedPayload(...), $value);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function canRetireInjury(object $row): bool
    {
        return true;
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
    ): void {}

    public function syncAllTeams(): int
    {
        $teamModel = $this->teamModelClass();
        $totalSynced = 0;
        $teamModel::query()->select(['id', 'espn_id'])->chunkById(100, function ($teams) use (&$totalSynced): void {
            foreach ($teams as $team) {
                $count = $this->execute((string) $team->espn_id);
                if (! $this->lastSyncReliable()) {
                    throw new \RuntimeException('An injury source was unavailable or incomplete; all-team refresh did not complete.');
                }
                $totalSynced += $count;
            }
        });

        return $totalSynced;
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
            $map[(string) $player->espn_id] = (int) $player->id;
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function extractTeamInjuries(string $teamEspnId): array
    {
        $this->injuryPayloadObserved = false;
        $this->injuryPayloadReliable = true;
        $response = $this->espnService->getTeamInjuries($teamEspnId);
        $keys = is_array($response) ? array_values(array_intersect(['items', 'injuries', 'entries'], array_keys($response))) : [];
        if (count($keys) === 1 && is_array($response[$keys[0]]) && array_is_list($response[$keys[0]])
            && ! collect($response[$keys[0]])->contains(fn ($entry) => ! is_array($entry) || $entry === [])) {
            $this->injuryPayloadObserved = true;
            $this->injuryPayloadReliable = $this->injuryCollectionReliable($response, count($response[$keys[0]]));

            return $this->extractInjuriesFromTeamInjuriesResponse($response);
        }
        $roster = $this->espnService->getRoster($teamEspnId);
        if (! is_array($roster) || ! is_array($roster['athletes'] ?? null) || ! array_is_list($roster['athletes'])) {
            $this->injuryPayloadReliable = false;

            return [];
        }
        $ids = [];
        foreach ($roster['athletes'] as $group) {
            if (! is_array($group)) {
                $this->injuryPayloadReliable = false;

                return [];
            }
            $athletes = array_key_exists('items', $group) ? $group['items'] : [$group];
            if (! is_array($athletes) || ! array_is_list($athletes)
                || (array_key_exists('items', $group) && ! $this->injuryCollectionReliable($group, count($athletes)))) {
                $this->injuryPayloadReliable = false;

                return [];
            }
            foreach ($athletes as $athlete) {
                $id = is_array($athlete) ? $this->extractAthleteIdFromEntity($athlete) : null;
                if ($id === null || ! is_array($athlete['injuries'] ?? null) || ! array_is_list($athlete['injuries'])
                    || collect($athlete['injuries'])->contains(fn ($entry) => ! is_array($entry) || $entry === [])) {
                    $this->injuryPayloadReliable = false;

                    return [];
                }
                $ids[] = $id;
            }
        }
        $team = $this->findTeamByEspnId($teamEspnId);
        $knownIds = $team === null ? [] : array_keys($this->playerIdMapByEspnId((int) $team->getKey()));
        $this->injuryPayloadObserved = true;
        $this->injuryPayloadReliable = count(array_unique($ids)) === count($ids)
            && array_diff($knownIds, $ids) === [] && $this->injuryCollectionReliable($roster, count($ids));

        return $this->extractInjuriesFromRosterResponse($roster);
    }

    protected function injuryCollectionReliable(array $payload, int $count): bool
    {
        foreach (['count', 'total', 'totalCount'] as $key) {
            if (array_key_exists($key, $payload) && (filter_var($payload[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false || (int) $payload[$key] !== $count)) {
                return false;
            }
        }
        foreach (['page', 'pageIndex', 'pageCount'] as $key) {
            if (array_key_exists($key, $payload) && (filter_var($payload[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]) === false)) {
                return false;
            }
        }
        foreach (['next', 'nextPage', 'nextPageUrl', 'links.next', 'hasMore', 'hasNext', 'hasNextPage'] as $key) {
            if (data_get($payload, $key)) {
                return false;
            }
        }
        foreach ((array) ($payload['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'next') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return list<array<string, mixed>>
     */
    protected function extractInjuriesFromTeamInjuriesResponse(?array $response): array
    {
        if (! is_array($response)) {
            return [];
        }

        $items = [];
        $sourceCount = 0;
        foreach (['items', 'injuries', 'entries'] as $sourceKey) {
            $sourceCount += is_array($response[$sourceKey] ?? null) ? count($response[$sourceKey]) : 0;
            $this->espnService->assertRefreshRows($sourceCount);
        }
        foreach (['items', 'injuries', 'entries'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                foreach ($response[$key] as $item) {
                    if (is_array($item)) {
                        $items[] = $this->resolveRefEntity($item);
                    }
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return list<array<string, mixed>>
     */
    protected function extractInjuriesFromRosterResponse(?array $response): array
    {
        if (! is_array($response)) {
            return [];
        }

        $athletes = $response['athletes'] ?? [];
        if (! is_array($athletes)) {
            return [];
        }

        $flattenedAthletes = [];
        $sourceCount = 0;
        foreach ($athletes as $group) {
            $sourceCount += is_array($group) && is_array($group['items'] ?? null) ? count($group['items']) : 1;
            $this->espnService->assertRefreshRows($sourceCount);
        }

        foreach ($athletes as $athlete) {
            if (! is_array($athlete)) {
                continue;
            }

            if (isset($athlete['items']) && is_array($athlete['items'])) {
                foreach ($athlete['items'] as $nestedAthlete) {
                    if (is_array($nestedAthlete)) {
                        $flattenedAthletes[] = $nestedAthlete;
                    }
                }

                continue;
            }

            $flattenedAthletes[] = $athlete;
        }

        $injuries = [];

        foreach ($flattenedAthletes as $athlete) {
            $athleteEspnId = $this->extractAthleteIdFromEntity($athlete);
            if ($athleteEspnId === null) {
                continue;
            }

            $athleteInjuries = $athlete['injuries'] ?? [];
            if (! is_array($athleteInjuries)) {
                continue;
            }

            foreach ($athleteInjuries as $injury) {
                if (! is_array($injury)) {
                    continue;
                }

                $resolvedInjury = $this->resolveRefEntity($injury);
                $resolvedInjury['athlete'] = $resolvedInjury['athlete'] ?? ['id' => $athleteEspnId];
                $injuries[] = $resolvedInjury;
            }
        }

        return $injuries;
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<string, mixed>
     */
    protected function resolveRefEntity(array $entity): array
    {
        $ref = $entity['$ref'] ?? null;
        if (! is_string($ref) || trim($ref) === '') {
            return $entity;
        }

        $resolved = $this->resolveRefPayload($ref);
        if (! is_array($resolved) || $resolved === []) {
            $this->injuryPayloadReliable = false;

            return $entity;
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveRefPayload(string $ref): ?array
    {
        if (array_key_exists($ref, $this->refCache)) {
            return $this->refCache[$ref];
        }

        // The shared ESPN client admits hosts, upgrades HTTP and revalidates every redirect,
        // pins public DNS results and bounds response bytes. No untrusted direct HTTP seam.
        return $this->refCache[$ref] = $this->espnService->getByRef($ref, false);
    }

    /**
     * @param  array<string, mixed>  $injury
     * @return array{
     *   espn_injury_id:?string,
     *   status:?string,
     *   detail:?string,
     *   type:?string,
     *   injury_date:?string,
     *   return_date:?string,
     *   source_updated_at:?string
     * }
     */
    protected function normalizeInjury(array $injury): array
    {
        $status = $injury['status'] ?? null;
        $type = $injury['type'] ?? null;

        return [
            'espn_injury_id' => $this->nullableString($injury['id'] ?? null),
            'status' => $this->nullableString(
                is_array($status)
                    ? ($status['type'] ?? $status['name'] ?? $status['displayName'] ?? null)
                    : $status
            ),
            'detail' => $this->injuryDetailText($injury),
            'type' => $this->nullableString(
                is_array($type)
                    ? ($type['displayName'] ?? $type['name'] ?? $type['abbreviation'] ?? null)
                    : $type
            ),
            'injury_date' => $this->normalizeDate(
                $injury['date']
                    ?? $injury['injuryDate']
                    ?? null
            ),
            'return_date' => $this->normalizeDate(
                $injury['returnDate']
                    ?? (is_array($injury['details'] ?? null) ? ($injury['details']['returnDate'] ?? null) : null)
                    ?? $injury['expectedReturnDate']
                    ?? null
            ),
            'source_updated_at' => $this->normalizeDateTime(
                $injury['lastUpdated']
                    ?? $injury['date']
                    ?? null
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $injury
     */
    protected function injuryDetailText(array $injury): ?string
    {
        $details = $injury['details'] ?? null;
        if (is_string($details)) {
            return $this->nullableString($details);
        }

        if (is_array($details)) {
            foreach (['detail', 'description', 'type', 'location', 'side'] as $key) {
                $value = $this->nullableString($details[$key] ?? null);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return $this->nullableString(
            $injury['detail']
                ?? $injury['description']
                ?? null
        );
    }

    protected function injuryKey(array $normalizedInjury): string
    {
        $espnInjuryId = $normalizedInjury['espn_injury_id'] ?? null;
        if ($espnInjuryId !== null && $espnInjuryId !== '') {
            return 'espn:'.$espnInjuryId;
        }

        $fallback = implode('|', [
            $normalizedInjury['status'] ?? '',
            $normalizedInjury['detail'] ?? '',
            $normalizedInjury['type'] ?? '',
            $normalizedInjury['return_date'] ?? '',
        ]);

        return 'hash:'.sha1($fallback);
    }

    /**
     * @param  array<string, mixed>  $normalizedInjury
     */
    protected function isCurrentInjuryActive(array $normalizedInjury): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $injury
     */
    protected function extractAthleteEspnId(array $injury): ?string
    {
        $athlete = $injury['athlete'] ?? null;
        if (is_array($athlete)) {
            return $this->extractAthleteIdFromEntity($athlete);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    protected function extractAthleteIdFromEntity(array $entity): ?string
    {
        if (isset($entity['id'])) {
            return $this->nullableString($entity['id']);
        }

        if (isset($entity['$ref']) && is_string($entity['$ref'])) {
            if (preg_match('/\/athletes\/(\d+)/', $entity['$ref'], $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    protected function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeDateTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $injury
     */
    protected function snapshotSourceUpdatedAt(array $injury): ?CarbonInterface
    {
        $value = $injury['lastUpdated']
            ?? $injury['date']
            ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $string = trim((string) $value);

            return $string === '' ? null : $string;
        }

        return null;
    }

    /**
     * @return class-string<Model>
     */
    protected function playerModelClass(): string
    {
        if (static::PLAYER_MODEL_CLASS === '') {
            throw new \RuntimeException('PLAYER_MODEL_CLASS must be defined.');
        }

        return static::PLAYER_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        if (static::TEAM_MODEL_CLASS === '') {
            throw new \RuntimeException('TEAM_MODEL_CLASS must be defined.');
        }

        return static::TEAM_MODEL_CLASS;
    }

    protected function injuryTable(): string
    {
        if (static::INJURY_TABLE === '') {
            throw new \RuntimeException('INJURY_TABLE must be defined.');
        }

        return static::INJURY_TABLE;
    }
}
