<?php

namespace App\Actions\ESPN\NFL;

use App\Models\NFL\Coach;
use App\Models\NFL\Team;
use App\Models\NFL\TeamCoachSeason;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncCoaches
{
    public function __construct(
        protected EspnService $espnService,
    ) {}

    public function execute(int $season): int
    {
        $synced = $this->syncConfiguredHeadCoachOverrides($season);
        if ($synced > 0 && $this->hasCompleteHeadCoachCoverage($season)) {
            return $synced;
        }

        $page = 1;
        $pageCount = 1;

        do {
            $response = $this->espnService->getSeasonCoachesPage($season, $page);
            if (! is_array($response)) {
                break;
            }

            $items = is_array($response['items'] ?? null) ? $response['items'] : [];
            $pageCount = max(1, (int) ($response['pageCount'] ?? 1));

            foreach ($items as $item) {
                $payload = $this->resolveCoachPayload($season, $item);
                if (! is_array($payload) || $payload === []) {
                    continue;
                }

                if ($this->persistCoachSeason($season, $payload)) {
                    $synced++;
                }
            }

            $page++;
        } while ($page <= $pageCount);

        if ($synced > 0 && $season < (int) config('nfl.season.default')) {
            $synced += $this->syncMissingHistoricalSeasonFromTeamCoachRefs($season);
        }

        if ($synced === 0 && $season === (int) config('nfl.season.default')) {
            $synced += $this->syncCurrentSeasonFromOfficialRosterPages($season);
        }

        return $synced;
    }

    protected function hasCompleteHeadCoachCoverage(int $season): bool
    {
        $teamCount = Team::query()->count();
        if ($teamCount === 0) {
            return false;
        }

        $coachTeamCount = TeamCoachSeason::query()
            ->where('season', $season)
            ->where('role', 'head_coach')
            ->distinct('team_id')
            ->count('team_id');

        return $coachTeamCount >= $teamCount;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    protected function resolveCoachPayload(int $season, array $item): ?array
    {
        if (isset($item['id'])) {
            return $item;
        }

        $ref = trim((string) ($item['$ref'] ?? ''));
        if ($ref !== '') {
            return $this->espnService->getByRef($ref);
        }

        $coachId = $this->coachIdFromRef($ref);
        if ($coachId !== null) {
            return $this->espnService->getSeasonCoach($season, $coachId);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function persistCoachSeason(int $season, array $payload): bool
    {
        $coachId = trim((string) ($payload['id'] ?? ''));
        if ($coachId === '') {
            return false;
        }

        $teamEspnId = $this->teamIdFromRef((string) data_get($payload, 'team.$ref', ''));
        if ($teamEspnId === null) {
            return false;
        }

        $team = Team::query()->where('espn_id', $teamEspnId)->first();
        if (! $team) {
            return false;
        }

        $coach = Coach::query()->updateOrCreate(
            ['espn_id' => $coachId],
            [
                'uid' => $payload['uid'] ?? null,
                'first_name' => $payload['firstName'] ?? null,
                'last_name' => $payload['lastName'] ?? null,
                'display_name' => $payload['displayName'] ?? $this->displayName($payload),
                'short_name' => $payload['shortName'] ?? null,
                'experience' => is_numeric($payload['experience'] ?? null) ? (int) $payload['experience'] : null,
                'career_records' => $this->careerRecords($payload),
                'raw_payload' => $payload,
            ]
        );

        TeamCoachSeason::query()->updateOrCreate(
            [
                'season' => $season,
                'team_id' => $team->id,
                'role' => 'head_coach',
            ],
            [
                'coach_id' => $coach->id,
                'experience' => is_numeric($payload['experience'] ?? null) ? (int) $payload['experience'] : null,
                'regular_season_record' => $this->regularSeasonRecord($payload),
                'source_ref' => $payload['$ref'] ?? null,
                'raw_payload' => $payload,
            ]
        );

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function displayName(array $payload): ?string
    {
        $name = trim(implode(' ', array_filter([
            $payload['firstName'] ?? null,
            $payload['lastName'] ?? null,
        ])));

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,mixed>|null
     */
    protected function careerRecords(array $payload): ?array
    {
        $records = $payload['careerRecords'] ?? null;

        return is_array($records) ? $records : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    protected function regularSeasonRecord(array $payload): ?array
    {
        foreach ((array) ($payload['records'] ?? []) as $recordEntry) {
            $ref = (string) data_get($recordEntry, 'record.$ref', '');
            if (! str_contains($ref, '/types/2/')) {
                continue;
            }

            $record = $this->espnService->getByRef($ref);
            if (! is_array($record)) {
                continue;
            }

            return [
                'summary' => $record['summary'] ?? null,
                'display_value' => $record['displayValue'] ?? null,
                'wins' => $this->recordStat($record, 'wins'),
                'losses' => $this->recordStat($record, 'losses'),
                'ties' => $this->recordStat($record, 'ties'),
                'raw' => $record,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $record
     */
    protected function recordStat(array $record, string $type): ?int
    {
        foreach ((array) ($record['stats'] ?? []) as $stat) {
            if (($stat['type'] ?? null) === $type && is_numeric($stat['value'] ?? null)) {
                return (int) $stat['value'];
            }
        }

        return null;
    }

    protected function teamIdFromRef(string $ref): ?string
    {
        if (preg_match('~/teams/([^/?]+)~', $ref, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function coachIdFromRef(string $ref): ?string
    {
        if (preg_match('~/coaches/([^/?]+)~', $ref, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function syncCurrentSeasonFromOfficialRosterPages(int $season): int
    {
        $urls = (array) config('espn.leagues.nfl.official_coach_roster_urls', []);
        if ($urls === []) {
            return 0;
        }

        $synced = 0;

        foreach (Team::query()->get() as $team) {
            $abbreviation = strtoupper((string) $team->abbreviation);
            $url = (string) ($urls[$abbreviation] ?? '');
            if ($url === '') {
                continue;
            }

            $name = $this->headCoachNameFromOfficialRosterPage($url);
            if ($name === null) {
                continue;
            }

            if ($this->persistOfficialCoachSeason($season, $team, $name, $url)) {
                $synced++;
            }
        }

        return $synced;
    }

    protected function syncMissingHistoricalSeasonFromTeamCoachRefs(int $season): int
    {
        $synced = 0;
        $mappedTeamIds = TeamCoachSeason::query()
            ->where('season', $season)
            ->where('role', 'head_coach')
            ->pluck('team_id');

        foreach (Team::query()->whereNotIn('id', $mappedTeamIds)->get() as $team) {
            $response = $this->espnService->getTeamCoaches((string) $team->espn_id, $season);
            $item = data_get($response, 'items.0');
            if (! is_array($item)) {
                continue;
            }

            $payload = $this->resolveCoachPayload($season, $item);
            if (! is_array($payload) || $payload === []) {
                continue;
            }

            if ($this->persistCoachSeasonForTeam($season, $team, $payload)) {
                $synced++;
            }
        }

        return $synced;
    }

    protected function headCoachNameFromOfficialRosterPage(string $url): ?string
    {
        $response = Http::timeout(12)->get($url);
        if (! $response->ok()) {
            return null;
        }

        return $this->headCoachNameFromOfficialRosterHtml($response->body());
    }

    protected function headCoachNameFromOfficialRosterHtml(string $html): ?string
    {
        preg_match_all('~<div[^>]*class="[^"]*d3-o-media-object__body[^"]*"[^>]*>(.*?)</div>~is', $html, $cards);

        foreach ($cards[1] ?? [] as $card) {
            if (
                preg_match('~<h5[^>]*class="[^"]*d3-o-media-object__roofline[^"]*"[^>]*>(.*?)</h5>~is', $card, $roleMatch) !== 1
                || preg_match('~<h3[^>]*class="[^"]*d3-o-media-object__title[^"]*"[^>]*>(.*?)</h3>~is', $card, $nameMatch) !== 1
            ) {
                continue;
            }

            if ($this->cleanHtmlText($roleMatch[1]) !== 'Head Coach') {
                continue;
            }

            $name = $this->cleanHtmlText($nameMatch[1]);

            return $name !== '' ? $name : null;
        }

        return null;
    }

    protected function cleanHtmlText(string $value): string
    {
        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return preg_replace('/\s+/', ' ', $text) ?? '';
    }

    protected function persistOfficialCoachSeason(int $season, Team $team, string $name, string $sourceUrl): bool
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $firstName = $parts[0] ?? null;
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;

        $coach = Coach::query()->updateOrCreate(
            ['espn_id' => 'official:'.Str::slug($name)],
            [
                'uid' => null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $name,
                'short_name' => $name,
                'experience' => null,
                'career_records' => null,
                'raw_payload' => [
                    'source' => 'official_team_coaches_roster',
                    'url' => $sourceUrl,
                    'displayName' => $name,
                ],
            ]
        );

        TeamCoachSeason::query()->updateOrCreate(
            [
                'season' => $season,
                'team_id' => $team->id,
                'role' => 'head_coach',
            ],
            [
                'coach_id' => $coach->id,
                'experience' => null,
                'regular_season_record' => null,
                'source_ref' => $sourceUrl,
                'raw_payload' => [
                    'source' => 'official_team_coaches_roster',
                    'url' => $sourceUrl,
                    'displayName' => $name,
                ],
            ]
        );

        return true;
    }

    protected function syncConfiguredHeadCoachOverrides(int $season): int
    {
        $overrides = (array) config("espn.leagues.nfl.historical_head_coaches.{$season}", []);
        if ($overrides === []) {
            return 0;
        }

        $synced = 0;

        foreach ($overrides as $abbreviation => $name) {
            $team = Team::query()
                ->where('abbreviation', strtoupper((string) $abbreviation))
                ->first();

            if (! $team || ! is_string($name) || trim($name) === '') {
                continue;
            }

            if ($this->persistOfficialCoachSeason(
                $season,
                $team,
                trim($name),
                "config:espn.leagues.nfl.historical_head_coaches.{$season}"
            )) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function persistCoachSeasonForTeam(int $season, Team $team, array $payload): bool
    {
        $coachId = trim((string) ($payload['id'] ?? ''));
        if ($coachId === '') {
            return false;
        }

        $coach = Coach::query()->updateOrCreate(
            ['espn_id' => $coachId],
            [
                'uid' => $payload['uid'] ?? null,
                'first_name' => $payload['firstName'] ?? null,
                'last_name' => $payload['lastName'] ?? null,
                'display_name' => $payload['displayName'] ?? $this->displayName($payload),
                'short_name' => $payload['shortName'] ?? null,
                'experience' => is_numeric($payload['experience'] ?? null) ? (int) $payload['experience'] : null,
                'career_records' => $this->careerRecords($payload),
                'raw_payload' => $payload,
            ]
        );

        TeamCoachSeason::query()->updateOrCreate(
            [
                'season' => $season,
                'team_id' => $team->id,
                'role' => 'head_coach',
            ],
            [
                'coach_id' => $coach->id,
                'experience' => is_numeric($payload['experience'] ?? null) ? (int) $payload['experience'] : null,
                'regular_season_record' => $this->regularSeasonRecord($payload),
                'source_ref' => $payload['$ref'] ?? null,
                'raw_payload' => $payload,
            ]
        );

        return true;
    }
}
