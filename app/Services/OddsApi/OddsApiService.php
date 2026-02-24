<?php

namespace App\Services\OddsApi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OddsApiService
{
    protected ?string $apiKey;

    protected ?string $baseUrl;

    protected int $cacheMinutes = 5;

    public function __construct()
    {
        $this->apiKey = config('services.odds_api.key');
        $this->baseUrl = config('services.odds_api.base_url', 'https://api.the-odds-api.com/v4');
    }

    public function getOdds(?string $eventId = null, string $sport = 'basketball_ncaab'): ?array
    {
        $url = $this->baseUrl."/sports/{$sport}/odds/";

        $params = [
            'apiKey' => $this->apiKey,
            'regions' => 'us',
            'markets' => 'h2h,spreads,totals',
            'bookmakers' => 'draftkings',
            'oddsFormat' => 'american',
        ];

        if ($eventId) {
            $params['eventIds'] = $eventId;
        }

        return $this->get($url, $params);
    }

    public function getNbaOdds(?string $eventId = null): ?array
    {
        return $this->getOdds($eventId, 'basketball_nba');
    }

    public function getMlbOdds(?string $eventId = null): ?array
    {
        return $this->getOdds($eventId, 'baseball_mlb');
    }

    public function getWcbbOdds(?string $eventId = null): ?array
    {
        return $this->getOdds($eventId, 'basketball_wncaab');
    }

    public function getNflOdds(?string $eventId = null): ?array
    {
        return $this->getOdds($eventId, 'americanfootball_nfl');
    }

    public function getCfbOdds(?string $eventId = null): ?array
    {
        return $this->getOdds($eventId, 'americanfootball_ncaaf');
    }

    public function getWnbaOdds(?string $eventId = null): ?array
    {
        return $this->getOdds($eventId, 'basketball_wnba');
    }

    public function getEvents(): ?array
    {
        $url = $this->baseUrl.'/sports/basketball_ncaab/events/';

        $params = [
            'apiKey' => $this->apiKey,
        ];

        return $this->get($url, $params);
    }

    public function getParticipants(string $sport = 'basketball_ncaab'): ?array
    {
        $url = $this->baseUrl."/sports/{$sport}/participants";

        $params = [
            'apiKey' => $this->apiKey,
        ];

        return $this->get($url, $params, false);
    }

    protected function get(string $url, array $params = [], bool $useCache = true): ?array
    {
        $cacheKey = 'odds_api.'.md5($url.json_encode($params));

        if ($useCache) {
            return Cache::remember(
                $cacheKey,
                now()->addMinutes($this->cacheMinutes),
                fn () => $this->fetchFromApi($url, $params)
            );
        }

        return $this->fetchFromApi($url, $params);
    }

    protected function fetchFromApi(string $url, array $params = []): ?array
    {
        if (! $this->apiKey) {
            throw new \RuntimeException(
                'Odds API key is not configured. Please set ODDS_API_KEY in your .env file. '.
                'Get your free API key at https://the-odds-api.com/'
            );
        }

        $response = Http::timeout(30)
            ->connectTimeout(30)
            ->get($url, $params);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    public function clearCache(): void
    {
        Cache::flush();
    }

    /**
     * Normalize team name for matching
     */
    public function normalizeTeamName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name);

        // Normalize common variations
        $name = str_replace('los angeles', 'la', $name);
        $name = str_replace('st.', 'st', $name);
        $name = str_replace('state', 'st', $name);

        return $name;
    }

    /**
     * Check if odds name matches any ESPN name variations
     */
    public function containsMatch(string $oddsName, array $espnNames): bool
    {
        foreach ($espnNames as $name) {
            if (empty($name)) {
                continue;
            }
            if (str_contains($oddsName, $name) || str_contains($name, $oddsName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract structured odds data from event
     */
    public function extractOddsData(array $event): array
    {
        $oddsData = [
            'event_id' => $event['id'],
            'commence_time' => $event['commence_time'],
            'home_team' => $event['home_team'],
            'away_team' => $event['away_team'],
            'bookmakers' => [],
        ];

        if (isset($event['bookmakers']) && is_array($event['bookmakers'])) {
            foreach ($event['bookmakers'] as $bookmaker) {
                $bookmakerData = [
                    'key' => $bookmaker['key'] ?? null,
                    'title' => $bookmaker['title'] ?? null,
                    'markets' => [],
                ];

                if (isset($bookmaker['markets']) && is_array($bookmaker['markets'])) {
                    foreach ($bookmaker['markets'] as $market) {
                        $marketData = [
                            'key' => $market['key'] ?? null,
                            'outcomes' => $market['outcomes'] ?? [],
                        ];
                        $bookmakerData['markets'][] = $marketData;
                    }
                }

                $oddsData['bookmakers'][] = $bookmakerData;
            }
        }

        return $oddsData;
    }

    /**
     * Generic fuzzy team matching for any sport
     */
    public function fuzzyMatchTeams(
        string $oddsHome,
        string $oddsAway,
        array $homeNames,
        array $awayNames,
        float $threshold = 70.0,
        ?string $sport = null
    ): bool {
        // Step 1: Check manual mappings first
        if ($sport && $this->checkManualMappings($oddsHome, $oddsAway, $homeNames, $awayNames, $sport)) {
            return true;
        }

        // Step 2: Fall back to fuzzy matching
        $oddsHome = $this->normalizeTeamName($oddsHome);
        $oddsAway = $this->normalizeTeamName($oddsAway);

        // Normalize all ESPN names
        $normalizedHomeNames = array_filter(array_map([$this, 'normalizeTeamName'], $homeNames));
        $normalizedAwayNames = array_filter(array_map([$this, 'normalizeTeamName'], $awayNames));

        // Try exact match on any variation
        foreach ($normalizedHomeNames as $homeName) {
            foreach ($normalizedAwayNames as $awayName) {
                if ($homeName === $oddsHome && $awayName === $oddsAway) {
                    return true;
                }
            }
        }

        // Try contains match
        if ($this->containsMatch($oddsHome, $normalizedHomeNames) &&
            $this->containsMatch($oddsAway, $normalizedAwayNames)) {
            return true;
        }

        // Fuzzy match on first name variations
        $normalizedHomeNames = array_values($normalizedHomeNames);
        $normalizedAwayNames = array_values($normalizedAwayNames);
        if (! empty($normalizedHomeNames) && ! empty($normalizedAwayNames)) {
            similar_text($normalizedHomeNames[0], $oddsHome, $homePercent);
            similar_text($normalizedAwayNames[0], $oddsAway, $awayPercent);

            return $homePercent >= $threshold && $awayPercent >= $threshold;
        }

        return false;
    }

    /**
     * Check manual team mappings before fuzzy matching
     */
    protected function checkManualMappings(
        string $oddsHome,
        string $oddsAway,
        array $homeNames,
        array $awayNames,
        string $sport
    ): bool {
        // Find manual mappings for both Odds API teams
        $homeMappings = \App\Models\OddsApiTeamMapping::query()
            ->where('sport', $sport)
            ->where('odds_api_team_name', $oddsHome)
            ->whereNotNull('espn_team_name')
            ->pluck('espn_team_name')
            ->toArray();

        $awayMappings = \App\Models\OddsApiTeamMapping::query()
            ->where('sport', $sport)
            ->where('odds_api_team_name', $oddsAway)
            ->whereNotNull('espn_team_name')
            ->pluck('espn_team_name')
            ->toArray();

        // If no mappings exist for either team, skip manual matching
        if (empty($homeMappings) || empty($awayMappings)) {
            return false;
        }

        // Normalize all names for comparison
        $normalizedHomeNames = array_map(fn ($name) => $this->normalizeTeamName($name), $homeNames);
        $normalizedAwayNames = array_map(fn ($name) => $this->normalizeTeamName($name), $awayNames);
        $normalizedHomeMappings = array_map(fn ($name) => $this->normalizeTeamName($name), $homeMappings);
        $normalizedAwayMappings = array_map(fn ($name) => $this->normalizeTeamName($name), $awayMappings);

        // Check if any home mapping matches any home name variation
        $homeMatch = false;
        foreach ($normalizedHomeMappings as $mapping) {
            foreach ($normalizedHomeNames as $name) {
                if ($mapping === $name || str_contains($mapping, $name) || str_contains($name, $mapping)) {
                    $homeMatch = true;
                    break 2;
                }
            }
        }

        // Check if any away mapping matches any away name variation
        $awayMatch = false;
        foreach ($normalizedAwayMappings as $mapping) {
            foreach ($normalizedAwayNames as $name) {
                if ($mapping === $name || str_contains($mapping, $name) || str_contains($name, $mapping)) {
                    $awayMatch = true;
                    break 2;
                }
            }
        }

        return $homeMatch && $awayMatch;
    }
}
