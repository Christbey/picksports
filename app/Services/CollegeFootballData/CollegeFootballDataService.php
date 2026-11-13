<?php

namespace App\Services\CollegeFootballData;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Retrieve opponent-adjusted advanced team season statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdvancedTeamSeasonStats(
        ?int $year = null,
        ?string $team = null,
        ?string $conference = null,
        ?bool $excludeGarbageTime = null
    ): array {
        return $this->get('/stats/season/advanced', [
            'year' => $year,
            'team' => $team,
            'conference' => $conference,
            'excludeGarbageTime' => $excludeGarbageTime,
        ]);
    }

    /**
     * Retrieve team returning production metrics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReturningProduction(
        ?int $year = null,
        ?string $team = null,
        ?string $conference = null
    ): array {
        return $this->get('/player/returning', [
            'year' => $year,
            'team' => $team,
            'conference' => $conference,
        ]);
    }

    /**
     * Retrieve team talent composite ratings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTeamTalent(?int $year = null): array
    {
        return $this->get('/talent', [
            'year' => $year,
        ]);
    }

    /**
     * Retrieve team recruiting rankings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTeamRecruitingRankings(
        ?int $year = null,
        ?string $team = null
    ): array {
        return $this->get('/recruiting/teams', [
            'year' => $year,
            'team' => $team,
        ]);
    }

    /**
     * Retrieve transfer portal player movement for a season.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTransferPortal(int $year): array
    {
        return $this->get('/player/portal', [
            'year' => $year,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getPlayerSeasonPassingStats(int $year): array
    {
        return $this->get('/stats/player/season', ['year' => $year, 'category' => 'passing']);
    }

    /** @return array<int, array<string, mixed>> */
    public function getCoaches(int $year): array
    {
        return $this->get('/coaches', ['year' => $year]);
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
        $query = $this->sanitizeQuery($query);
        if (! isset($query['year']) || isset($query['team']) || isset($query['conference'])
            || ! in_array($path, ['/stats/season', '/stats/season/advanced', '/wepa/team/season'], true)) {
            return $this->fetch($path, $query);
        }

        ksort($query);
        $key = $this->quotaCacheKey().':season-data:'.hash('sha256', json_encode([$this->baseUrl, $path, $query], JSON_THROW_ON_ERROR));

        return Cache::get($key) ?? Cache::lock($key.':fetch', 120)->block(110, function () use ($key, $path, $query): array {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }

            $rows = $this->fetch($path, $query);
            if ($rows !== [] && array_is_list($rows) && collect($rows)->every(
                fn ($row) => is_array($row) && (int) ($row['season'] ?? $row['year'] ?? $query['year']) === (int) $query['year']
            )) {
                Cache::put($key, $rows, now()->addHour());
            }

            return $rows;
        });
    }

    private function fetch(string $path, array $query): array
    {
        if ($this->monthlyQuotaExhausted()) {
            (new Response(new PsrResponse(429, ['Content-Type' => 'application/json'],
                '{"message":"Monthly call quota exceeded."}')))->throw();
        }

        $response = $this->request()
            ->retry([250, 1000], when: fn (\Throwable $error): bool => $error instanceof ConnectionException
                || ($error instanceof RequestException && ! self::isMonthlyQuotaExceeded($error)
                    && in_array($error->response->status(), [429, 500, 502, 503, 504], true)), throw: false)
            ->get($path, $query);

        if ($response->status() === 429 && self::isMonthlyQuotaExceeded($response->toException())) {
            $reset = now('UTC')->startOfMonth()->addMonth();
            if (Cache::add($this->quotaCacheKey(), true, $reset)) {
                Log::warning('CollegeFootballData monthly quota exhausted', ['requests_paused_until' => $reset->toIso8601String()]);
            }
        }
        $response->throw();

        return $response->json() ?? [];
    }

    public static function isMonthlyQuotaExceeded(RequestException $error): bool
    {
        return $error->response->status() === 429
            && strcasecmp(trim((string) $error->response->json('message')), 'Monthly call quota exceeded.') === 0;
    }

    public function monitorQuota(): void
    {
        if (blank($this->apiKey) || ! Cache::add($this->quotaCacheKey().':monitor', true, now()->addHour())) {
            return;
        }

        try {
            $info = $this->request()->get('/info')->throw()->json();
            if (! is_numeric($info['monthlyLimit'] ?? null) || ! is_numeric($info['remainingCalls'] ?? null)) {
                Log::warning('CollegeFootballData quota status unavailable');

                return;
            }

            $reset = filled($info['resetAt'] ?? null)
                ? CarbonImmutable::parse($info['resetAt']) : now('UTC')->startOfMonth()->addMonth();
            if ($info['remainingCalls'] <= 0 && $reset->isFuture()) {
                Cache::put($this->quotaCacheKey(), true, $reset);
            } elseif ($info['remainingCalls'] > 0) {
                Cache::forget($this->quotaCacheKey());
            }

            Log::log($info['remainingCalls'] <= $info['monthlyLimit'] * 0.10 ? 'warning' : 'info',
                'CollegeFootballData quota status', [
                    'monthly_limit' => (int) $info['monthlyLimit'],
                    'remaining_calls' => (int) $info['remainingCalls'],
                    'reset_at' => $reset->toIso8601String(),
                ]);
        } catch (\Throwable $error) {
            Log::warning('CollegeFootballData quota status unavailable', ['exception' => $error::class]);
        }
    }

    public function monthlyQuotaExhausted(): bool
    {
        return filled($this->apiKey) && Cache::has($this->quotaCacheKey());
    }

    private function quotaCacheKey(): string
    {
        return 'cfbd:monthly-quota:'.hash('sha256', (string) $this->apiKey);
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
        return array_map(
            // CFBD validates literal query booleans; PHP/Guzzle otherwise sends 1/0.
            static fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value,
            array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }
}
