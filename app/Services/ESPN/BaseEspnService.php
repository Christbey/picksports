<?php

namespace App\Services\ESPN;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BaseEspnService
{
    protected const SPORT_KEY = '';

    protected const TEAMS_LIMIT = null;

    protected const SCOREBOARD_USE_CACHE = true;

    protected const SCOREBOARD_EVENT_LIMIT = null;

    protected const SCOREBOARD_EVENT_GROUPS = null;

    protected const PLAYS_ENABLED = true;

    protected const WEEKLY_EVENTS_ENABLED = true;

    protected string $sport;

    protected array $config;

    protected int $cacheMinutes = 5;

    protected ?int $teamsLimit = null;

    protected bool $scoreboardUseCache = true;

    protected ?int $scoreboardEventLimit = null;

    protected ?int $scoreboardEventGroups = null;

    protected bool $playsEnabled = true;

    protected bool $weeklyEventsEnabled = true;

    public function __construct(?string $sport = null)
    {
        $resolvedSport = $sport ?: static::SPORT_KEY;

        if ($resolvedSport === '') {
            throw new \InvalidArgumentException('ESPN sport key must be provided.');
        }

        $this->sport = $resolvedSport;
        $this->config = config("espn.leagues.{$resolvedSport}");
        $this->teamsLimit = static::TEAMS_LIMIT;
        $this->scoreboardUseCache = static::SCOREBOARD_USE_CACHE;
        $this->scoreboardEventLimit = static::SCOREBOARD_EVENT_LIMIT;
        $this->scoreboardEventGroups = static::SCOREBOARD_EVENT_GROUPS;
        $this->playsEnabled = static::PLAYS_ENABLED;
        $this->weeklyEventsEnabled = static::WEEKLY_EVENTS_ENABLED;
    }

    public function getTeams(): ?array
    {
        return $this->get($this->buildUrl('site', 'teams').$this->buildQueryString($this->teamsQueryParams()));
    }

    public function getTeam(string $teamId): ?array
    {
        return $this->get($this->buildUrl('site', 'team', ['teamId' => $teamId]));
    }

    public function getRoster(string $teamId): ?array
    {
        return $this->get($this->buildUrl('site', 'roster', ['teamId' => $teamId]));
    }

    public function getSchedule(string $teamId, ?int $season = null): ?array
    {
        $url = $this->buildUrl('site', 'schedule', ['teamId' => $teamId])
            .$this->buildQueryString($this->scheduleQueryParams($season));

        return $this->get($url);
    }

    public function getScoreboard(?string $date = null): ?array
    {
        $query = $this->scoreboardQueryParams($date);
        $url = $this->buildUrl('site', 'scoreboard').$this->buildQueryString($query);

        return $this->get($url, $this->scoreboardUsesCache());
    }

    public function getGame(string $eventId): ?array
    {
        return $this->get($this->buildUrl('site', 'summary', ['eventId' => $eventId]));
    }

    public function getPlays(string $eventId, string $competitionId): ?array
    {
        if (! $this->supportsPlays()) {
            return null;
        }

        $url = $this->buildUrl('core', 'plays', [
            'eventId' => $eventId,
            'competitionId' => $competitionId,
        ]);

        return $this->get($url);
    }

    public function getGames(int $season, int $seasonType, int $week): ?array
    {
        if (! $this->supportsWeeklyEvents()) {
            return null;
        }

        $url = $this->buildUrl('core', 'weekly_events', [
            'year' => $season,
            'seasonType' => $seasonType,
            'week' => $week,
        ]);

        return $this->get($url);
    }

    protected function buildUrl(string $base, string $endpoint, array $params = []): string
    {
        // Replace placeholders in base URL
        $url = str_replace(
            ['{sport}', '{league}', '{leagueShort}'],
            [$this->config['sport'], $this->config['league'], $this->config['leagueShort']],
            config("espn.bases.{$base}")
        );

        // Get the endpoint path
        $path = $this->config[$base][$endpoint] ?? '';

        // Replace parameters in path
        foreach ($params as $key => $value) {
            $path = str_replace("{{$key}}", $value, $path);
        }

        return $url.$path;
    }

    protected function get(string $url, bool $useCache = true): ?array
    {
        if ($useCache) {
            $cacheKey = "espn.{$this->sport}.".md5($url);

            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $result = $this->fetchFromApi($url);

            // Only cache successful responses, never cache null (API errors/rate limits)
            if ($result !== null) {
                Cache::put($cacheKey, $result, now()->addMinutes($this->cacheMinutes));
            }

            return $result;
        }

        return $this->fetchFromApi($url);
    }

    protected function fetchFromApi(string $url): ?array
    {
        $response = Http::timeout(30)->connectTimeout(30)->get($url);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    public function clearCache(): void
    {
        Cache::tags(["espn.{$this->sport}"])->flush();
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function teamsQueryParams(): array
    {
        return ['limit' => $this->teamsLimit];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function scoreboardQueryParams(?string $date): array
    {
        return [
            'limit' => $this->scoreboardLimit(),
            'groups' => $this->scoreboardGroups(),
            'dates' => $date,
        ];
    }

    protected function scoreboardUsesCache(): bool
    {
        return $this->scoreboardUseCache;
    }

    protected function scoreboardLimit(): ?int
    {
        return $this->scoreboardEventLimit;
    }

    protected function scoreboardGroups(): ?int
    {
        return $this->scoreboardEventGroups;
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function scheduleQueryParams(?int $season): array
    {
        return ['season' => $season];
    }

    protected function supportsPlays(): bool
    {
        return $this->playsEnabled;
    }

    protected function supportsWeeklyEvents(): bool
    {
        return $this->weeklyEventsEnabled;
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    protected function buildQueryString(array $params): string
    {
        $query = http_build_query(array_filter($params, fn ($value) => $value !== null));

        return $query === '' ? '' : "?{$query}";
    }
}
