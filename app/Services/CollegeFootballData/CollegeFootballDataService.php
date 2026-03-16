<?php

namespace App\Services\CollegeFootballData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class CollegeFootballDataService
{
    protected ?string $apiKey;

    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.collegefootballdata.api_key');
        $this->baseUrl = rtrim(
            (string) config('services.collegefootballdata.base_url', 'https://api.collegefootballdata.com'),
            '/'
        );
    }

    /**
     * Retrieve opponent-adjusted team season statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdjustedTeamSeasonStats(
        ?int $year = null,
        ?string $team = null,
        ?string $conference = null
    ): array {
        return $this->getWepaTeamSeason($year, $team, $conference);
    }

    /**
     * Retrieve opponent-adjusted WEPA team season statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWepaTeamSeason(
        ?int $year = null,
        ?string $team = null,
        ?string $conference = null
    ): array {
        return $this->get('/wepa/team/season', [
            'year' => $year,
            'team' => $team,
            'conference' => $conference,
        ]);
    }

    /**
     * Retrieve ESPN FPI-style ratings from CFBD.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFpiRatings(
        ?int $year = null,
        ?string $team = null,
        ?string $conference = null,
        ?int $week = null
    ): array {
        return $this->get('/ratings/fpi', [
            'year' => $year,
            'team' => $team,
            'conference' => $conference,
            'week' => $week,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFbsTeams(?int $year = null): array
    {
        return $this->get('/teams/fbs', [
            'year' => $year,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->request()
            ->get($path, $this->sanitizeQuery($query))
            ->throw();

        return $response->json() ?? [];
    }

    protected function request(): PendingRequest
    {
        if (! $this->apiKey) {
            throw new \RuntimeException(
                'CollegeFootballData API key is not configured. Please set COLLEGEFOOTBALLDATA_API_KEY in your .env file.'
            );
        }

        return Http::acceptJson()
            ->withToken($this->apiKey)
            ->baseUrl($this->baseUrl)
            ->timeout(30)
            ->connectTimeout(30);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function sanitizeQuery(array $query): array
    {
        return array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }
}
