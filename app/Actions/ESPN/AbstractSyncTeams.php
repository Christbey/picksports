<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use App\Services\SportsAssetStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

abstract class AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = '';

    protected const TEAM_DTO_CLASS = '';

    /**
     * @var array<string, array<string,mixed>|null>
     */
    protected array $refCache = [];

    public function __construct(
        protected BaseEspnService $espnService,
        protected ?SportsAssetStorage $sportsAssetStorage = null,
    ) {
        $this->sportsAssetStorage ??= app(SportsAssetStorage::class);
    }

    /**
     * @param  array<string, mixed>  $team
     */
    protected function resolveTeam(array $team): ?array
    {
        $hasConference = ! empty($team['conference']['name'])
            || ! empty($team['groups']['name'])
            || ! empty($team['group']['name']);
        $hasDivision = ! empty($team['division']['name'])
            || ! empty($team['groups']['parent']['name'])
            || ! empty($team['group']['parent']['name']);

        if ($hasConference || $hasDivision) {
            return $this->hydrateConferenceDivisionFromRefs($team);
        }

        $teamId = (string) ($team['id'] ?? '');
        if ($teamId === '') {
            return $this->hydrateConferenceDivisionFromRefs($team);
        }

        try {
            $teamDetail = $this->espnService->getTeam($teamId);
        } catch (ConnectionException $e) {
            Log::warning("ESPN: Team detail fallback timed out for team {$teamId}", [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return $team;
        }

        $detailedTeam = is_array($teamDetail['team'] ?? null) ? $teamDetail['team'] : null;
        if (! is_array($detailedTeam) || $detailedTeam === []) {
            return $this->hydrateConferenceDivisionFromRefs($team);
        }

        return $this->hydrateConferenceDivisionFromRefs(array_replace_recursive($team, $detailedTeam));
    }

    /**
     * @param  array<string, mixed>  $resolvedTeam
     * @param  array<string, mixed>  $rawTeam
     * @return array<string, mixed>
     */
    protected function mapTeamAttributes(object $dto, array $resolvedTeam, array $rawTeam): array
    {
        $attributes = $dto->toArray();
        $attributes['logo_url'] = $this->mirrorLogo(
            $attributes['logo_url'] ?? null,
            $this->sportKey(),
            $this->teamAssetIdentifier($attributes, $this->getDtoEspnId($dto))
        );

        return $attributes;
    }

    /**
     * Populate conference/division from `$ref` group payloads when ESPN omits names inline.
     *
     * @param  array<string,mixed>  $team
     * @return array<string,mixed>
     */
    protected function hydrateConferenceDivisionFromRefs(array $team): array
    {
        $conference = $team['conference']['name']
            ?? $team['groups']['name']
            ?? $team['group']['name']
            ?? null;

        $division = $team['division']['name']
            ?? $team['groups']['parent']['name']
            ?? $team['group']['parent']['name']
            ?? null;

        $group = is_array($team['group'] ?? null)
            ? $team['group']
            : (is_array($team['groups'] ?? null) ? $team['groups'] : null);

        if ($conference === null && is_array($group)) {
            $conference = $group['name'] ?? null;
            if ($conference === null) {
                $groupPayload = $this->resolveRefPayload((string) ($group['$ref'] ?? ''));
                $conference = $groupPayload['name'] ?? null;
                if ($conference === null) {
                    $conference = $groupPayload['group']['name'] ?? null;
                }
                if (is_array($groupPayload['parent'] ?? null) && $division === null) {
                    $division = $groupPayload['parent']['name'] ?? $division;
                    if ($division === null) {
                        $division = $this->resolveNameFromRef((string) ($groupPayload['parent']['$ref'] ?? ''));
                    }
                }
            }
        }

        $parent = is_array($group['parent'] ?? null) ? $group['parent'] : null;
        if ($division === null && is_array($parent)) {
            $division = $parent['name'] ?? null;
            if ($division === null) {
                $division = $this->resolveNameFromRef((string) ($parent['$ref'] ?? ''));
            }
        }

        if ($conference !== null) {
            $team['conference']['name'] = $conference;
            $team['groups']['name'] = $conference;
            $team['group']['name'] = $conference;
        }

        if ($division !== null) {
            $team['division']['name'] = $division;
            $team['groups']['parent']['name'] = $division;
            $team['group']['parent']['name'] = $division;
        }

        return $team;
    }

    protected function resolveNameFromRef(string $ref): ?string
    {
        $payload = $this->resolveRefPayload($ref);
        if (! is_array($payload)) {
            return null;
        }

        return $payload['name']
            ?? $payload['group']['name']
            ?? null;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function resolveRefPayload(string $ref): ?array
    {
        $url = trim($ref);
        if ($url === '') {
            return null;
        }

        if (array_key_exists($url, $this->refCache)) {
            return $this->refCache[$url];
        }

        try {
            $payload = $this->espnService->getByRef($url);
        } catch (\Throwable) {
            return $this->refCache[$url] = null;
        }

        if (! is_array($payload)) {
            return $this->refCache[$url] = null;
        }

        return $this->refCache[$url] = $payload;
    }

    protected function getUniqueKey(): string
    {
        return 'espn_id';
    }

    protected function getDtoEspnId(object $dto): string
    {
        return (string) $dto->espnId;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    protected function extractTeams(array $response): array
    {
        $teams = $response['sports'][0]['leagues'][0]['teams'] ?? null;

        return is_array($teams) ? $teams : [];
    }

    protected function extractPageCount(array $response): int
    {
        $pageCount = $response['pageCount'] ?? null;

        return is_numeric($pageCount) && (int) $pageCount > 0 ? (int) $pageCount : 1;
    }

    public function execute(): int
    {
        $teamModel = $this->teamModelClass();
        $uniqueKey = $this->getUniqueKey();
        $synced = 0;

        $page = 1;
        $pageCount = 1;

        do {
            $response = $this->espnService->getTeamsPage($page);
            if (! is_array($response)) {
                break;
            }

            $teams = $this->extractTeams($response);
            if ($teams === []) {
                break;
            }

            $pageCount = $this->extractPageCount($response);

            foreach ($teams as $teamData) {
                $rawTeam = $teamData['team'] ?? [];
                if (empty($rawTeam['id'])) {
                    continue;
                }

                $resolvedTeam = $this->resolveTeam($rawTeam);
                if (! is_array($resolvedTeam) || $resolvedTeam === []) {
                    continue;
                }

                $dto = $this->teamDtoFromApi($resolvedTeam);
                $espnId = $this->getDtoEspnId($dto);
                if ($espnId === '') {
                    continue;
                }

                $attributes = $this->mapTeamAttributes($dto, $resolvedTeam, $rawTeam);

                try {
                    $teamModel::updateOrCreate(
                        [$uniqueKey => $espnId],
                        $attributes
                    );
                } catch (QueryException $e) {
                    if (! $this->isDuplicateAbbreviationViolation($e, $teamModel::query()->getModel()->getTable())) {
                        throw $e;
                    }

                    $attributes['abbreviation'] = $this->resolveUniqueAbbreviation($teamModel, $attributes, $espnId);

                    $teamModel::updateOrCreate(
                        [$uniqueKey => $espnId],
                        $attributes
                    );
                }

                $synced++;
            }

            $page++;
        } while ($page <= $pageCount);

        return $synced;
    }

    public function executeForEspnId(string $espnId): bool
    {
        $espnId = trim($espnId);
        if ($espnId === '') {
            return false;
        }

        $response = $this->espnService->getTeam($espnId);
        $team = is_array($response['team'] ?? null) ? $response['team'] : null;

        if (! is_array($team) || empty($team['id'])) {
            return false;
        }

        return $this->persistTeamRecord($team) !== null;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    protected function teamDtoFromApi(array $team): object
    {
        $teamDtoClass = $this->teamDtoClass();

        return $teamDtoClass::fromEspnResponse($team);
    }

    /**
     * @return class-string
     */
    protected function teamModelClass(): string
    {
        if (static::TEAM_MODEL_CLASS === '') {
            throw new \RuntimeException('TEAM_MODEL_CLASS must be defined.');
        }

        return static::TEAM_MODEL_CLASS;
    }

    /**
     * @return class-string
     */
    protected function teamDtoClass(): string
    {
        if (static::TEAM_DTO_CLASS === '') {
            throw new \RuntimeException('TEAM_DTO_CLASS must be defined.');
        }

        return static::TEAM_DTO_CLASS;
    }

    protected function isDuplicateAbbreviationViolation(QueryException $e, string $table): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '1062 Duplicate entry')
            && str_contains($message, "{$table}.{$table}_abbreviation_unique");
    }

    /**
     * @param  class-string<Model>  $teamModel
     * @param  array<string, mixed>  $attributes
     */
    protected function resolveUniqueAbbreviation(string $teamModel, array $attributes, string $espnId): string
    {
        $existing = $teamModel::query()
            ->where('espn_id', $espnId)
            ->value('abbreviation');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $base = strtoupper(trim((string) ($attributes['abbreviation'] ?? '')));
        $base = $base !== '' ? substr($base, 0, 10) : 'TEAM';

        if (! $teamModel::query()->where('abbreviation', $base)->exists()) {
            return $base;
        }

        foreach ($this->abbreviationCandidates($base, $espnId) as $candidate) {
            if (! $teamModel::query()->where('abbreviation', $candidate)->exists()) {
                return $candidate;
            }
        }

        return substr($base, 0, 6).substr($espnId, -4);
    }

    /**
     * @return array<int, string>
     */
    protected function abbreviationCandidates(string $base, string $espnId): array
    {
        $suffix = preg_replace('/\D+/', '', $espnId) ?: $espnId;
        $suffix = substr($suffix, -4);

        return [
            substr($base, 0, 7).$suffix,
            substr($base, 0, 6).substr($suffix, -3),
            substr($base, 0, 5).substr($suffix, -4),
        ];
    }

    protected function mirrorLogo(?string $sourceUrl, string $sport, string $teamIdentifier): ?string
    {
        return $this->sportsAssetStorage?->mirrorTeamLogo($sourceUrl, $sport, $teamIdentifier) ?? $sourceUrl;
    }

    protected function teamAssetIdentifier(array $attributes, string $fallbackId): string
    {
        $name = trim(implode(' ', array_filter([
            $attributes['location'] ?? $attributes['school'] ?? null,
            $attributes['name'] ?? $attributes['mascot'] ?? null,
        ])));

        return $name !== '' ? "{$name}-{$fallbackId}" : $fallbackId;
    }

    protected function sportKey(): string
    {
        $namespace = static::class;
        $segments = explode('\\', $namespace);

        return strtolower($segments[3] ?? 'teams');
    }

    /**
     * @param  array<string, mixed>  $rawTeam
     * @return Model|null
     */
    protected function persistTeamRecord(array $rawTeam): ?object
    {
        if (empty($rawTeam['id'])) {
            return null;
        }

        $teamModel = $this->teamModelClass();
        $uniqueKey = $this->getUniqueKey();
        $resolvedTeam = $this->resolveTeam($rawTeam);
        if (! is_array($resolvedTeam) || $resolvedTeam === []) {
            return null;
        }

        $dto = $this->teamDtoFromApi($resolvedTeam);
        $espnId = $this->getDtoEspnId($dto);
        if ($espnId === '') {
            return null;
        }

        $attributes = $this->mapTeamAttributes($dto, $resolvedTeam, $rawTeam);

        try {
            return $teamModel::updateOrCreate([$uniqueKey => $espnId], $attributes);
        } catch (QueryException $e) {
            if (! $this->isDuplicateAbbreviationViolation($e, $teamModel::query()->getModel()->getTable())) {
                throw $e;
            }

            $attributes['abbreviation'] = $this->resolveUniqueAbbreviation($teamModel, $attributes, $espnId);

            return $teamModel::updateOrCreate([$uniqueKey => $espnId], $attributes);
        }
    }
}
