<?php

namespace App\Services\ESPN;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BaseEspnService
{
    protected string $sport;

    protected array $config;

    protected int $cacheMinutes = 5;

    public function __construct(string $sport)
    {
        $this->sport = $sport;
        $this->config = config("espn.leagues.{$sport}");
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
            return Cache::remember(
                "espn.{$this->sport}.".md5($url),
                now()->addMinutes($this->cacheMinutes),
                fn () => $this->fetchFromApi($url)
            );
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
}
