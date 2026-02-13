<?php

namespace App\Services\OddsApi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OddsApiService
{
    protected string $apiKey;

    protected string $baseUrl;

    protected int $cacheMinutes = 5;

    public function __construct()
    {
        $this->apiKey = config('services.odds_api.key');
        $this->baseUrl = config('services.odds_api.base_url');
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
}
