<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = '';

    protected const TEAM_MODEL_CLASS = '';

    protected const INJURY_TABLE = '';

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
        $team = $this->findTeamByEspnId($teamEspnId);
        if (! $team) {
            return 0;
        }

        $injuries = $this->extractTeamInjuries($teamEspnId);
        $teamId = (int) $team->getKey();

        DB::table($this->injuryTable())
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if ($injuries === []) {
            return 0;
        }

        $playerMap = $this->playerIdMapByEspnId($teamId);
        $rows = [];
        $now = now();

        foreach ($injuries as $injury) {
            $playerEspnId = $this->extractAthleteEspnId($injury);
            if ($playerEspnId === null || ! isset($playerMap[$playerEspnId])) {
                continue;
            }

            $normalized = $this->normalizeInjury($injury);
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
                'is_active' => true,
                'raw_payload' => json_encode($injury, JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        DB::table($this->injuryTable())->upsert(
            $rows,
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

        return count($rows);
    }

    public function syncAllTeams(): int
    {
        $teamModel = $this->teamModelClass();
        $teams = $teamModel::query()->get(['espn_id']);
        $totalSynced = 0;

        foreach ($teams as $team) {
            $totalSynced += $this->execute((string) $team->espn_id);
        }

        return $totalSynced;
    }

    /**
     * @return array<string, int>
     */
    protected function playerIdMapByEspnId(int $teamId): array
    {
        $playerModel = $this->playerModelClass();

        /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $players */
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
        $teamInjuriesResponse = $this->espnService->getTeamInjuries($teamEspnId);
        $teamInjuries = $this->extractInjuriesFromTeamInjuriesResponse($teamInjuriesResponse);

        if ($teamInjuries !== []) {
            return $teamInjuries;
        }

        $rosterResponse = $this->espnService->getRoster($teamEspnId);

        return $this->extractInjuriesFromRosterResponse($rosterResponse);
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

        try {
            $response = Http::timeout(30)
                ->connectTimeout(30)
                ->get($ref);
        } catch (\Throwable) {
            return $this->refCache[$ref] = null;
        }

        if (! $response->successful()) {
            return $this->refCache[$ref] = null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return $this->refCache[$ref] = null;
        }

        return $this->refCache[$ref] = $payload;
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
