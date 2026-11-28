<?php

namespace App\Services\CollegeFootballData;

use App\Services\CollegeFootballData\Fpi\BoundedFpiSink;
use App\Support\Json\JsonAllocationBudget;
use App\Support\Sports\ExplicitOffsetInstant;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

        return $this->seasonSnapshot($path, $query)['rows'];
    }

    /** @return array{rows: array, observed_at: string} */
    public function getFpiSeasonSnapshot(int $year): array
    {
        $validate = function (array $snapshot) use ($year): array {
            $observed = ExplicitOffsetInstant::parse($snapshot['observed_at'] ?? null);
            if ($observed === null || $observed->isFuture() || ! is_array($snapshot['rows'] ?? null)
                || ! array_is_list($snapshot['rows']) || count($snapshot['rows']) > 1024) {
                throw new RuntimeException('Cached or received FPI snapshot is invalid.');
            }
            $raw = json_encode($snapshot['rows'], JSON_THROW_ON_ERROR);
            JsonAllocationBudget::inspect($raw, BoundedFpiSink::MAXIMUM_BYTES, 100000, 16, 'FPI season source');
            foreach ($snapshot['rows'] as $row) {
                if (! is_array($row) || ! is_string($row['team'] ?? null) || strlen($row['team']) > 256
                    || (int) ($row['season'] ?? $row['year'] ?? $year) !== $year) {
                    throw new RuntimeException('FPI source row identity is invalid.');
                }
            }

            return $snapshot;
        };
        if ($this->monthlyQuotaExhausted()) {
            (new Response(new PsrResponse(429, ['Content-Type' => 'application/json'], '{"message":"Monthly call quota exceeded."}')))->throw();
        }
        $makeSink = fn () => new BoundedFpiSink(Utils::streamFor(fopen('php://temp/maxmemory:262144', 'w+')));
        $sink = $makeSink();
        $deadline = hrtime(true) / 1e9 + 87;
        $body = null;
        try {
            $response = $this->request()->withOptions(['sink' => $sink])
                ->retry([250, 1000], when: function (\Throwable $error, PendingRequest $request) use (&$sink, $makeSink, $deadline): bool {
                    $retry = $error instanceof ConnectionException || ($error instanceof RequestException
                        && ! $this->fpiQuotaExceeded($error->response) && in_array($error->response->status(), [429, 500, 502, 503, 504], true));
                    $remaining = $deadline - hrtime(true) / 1e9;
                    if (! $retry || $remaining <= 1) {
                        return false;
                    }
                    $sink->close();
                    $sink = $makeSink();
                    $request->withOptions(['sink' => $sink])->timeout(min(30, $remaining - 1))->connectTimeout(min(30, $remaining - 1));

                    return true;
                }, throw: false)->get('/ratings/fpi', ['year' => $year]);
            $body = $response->toPsrResponse()->getBody();
            if ($response->status() === 429 && $this->fpiQuotaExceeded($response)) {
                Cache::put($this->quotaCacheKey(), true, now('UTC')->startOfMonth()->addMonth());
            }
            $response->throw();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $raw = $body->read(BoundedFpiSink::MAXIMUM_BYTES + 1);
            if (! str_starts_with(ltrim($raw), '[')) {
                throw new RuntimeException('FPI HTTP source must be a top-level JSON list.');
            }
            JsonAllocationBudget::inspect($raw, BoundedFpiSink::MAXIMUM_BYTES, 100000, 16, 'FPI HTTP source');
            if (! $body->eof()) {
                throw new RuntimeException('FPI HTTP source exceeds its byte budget.');
            }
            $rows = json_decode($raw, true, 17, JSON_THROW_ON_ERROR);
            $snapshot = $validate(['rows' => $rows, 'observed_at' => now()->toIso8601String()]);

            return $snapshot;
        } finally {
            $body?->close();
            $sink->close();
        }
    }

    /** @return array{rows: array, observed_at: string} */
    private function fpiQuotaExceeded(Response $response): bool
    {
        if ($response->status() !== 429) {
            return false;
        }
        $body = $response->toPsrResponse()->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->read(BoundedFpiSink::MAXIMUM_BYTES + 1);
        JsonAllocationBudget::inspect($raw, BoundedFpiSink::MAXIMUM_BYTES, 100000, 16, 'FPI quota response');
        if (! $body->eof()) {
            throw new RuntimeException('FPI quota response exceeds its byte budget.');
        }
        $decoded = json_decode($raw, true, 17, JSON_THROW_ON_ERROR);
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return is_array($decoded) && is_string($decoded['message'] ?? null)
            && strcasecmp(trim($decoded['message']), 'Monthly call quota exceeded.') === 0;
    }

    private function seasonSnapshot(string $path, array $query): array
    {
        ksort($query);
        $key = $this->quotaCacheKey().':season-snapshot:v1:'.hash('sha256', json_encode([$this->baseUrl, $path, $query], JSON_THROW_ON_ERROR));

        return Cache::get($key) ?? Cache::lock($key.':fetch', 120)->block(110, function () use ($key, $path, $query): array {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }

            $rows = $this->fetch($path, $query);
            $snapshot = ['rows' => $rows, 'observed_at' => now()->toIso8601String()];
            if ($rows !== [] && array_is_list($rows) && collect($rows)->every(
                fn ($row) => is_array($row) && (int) ($row['season'] ?? $row['year'] ?? $query['year']) === (int) $query['year']
            )) {
                Cache::put($key, $snapshot, now()->addHour());
            }

            return $snapshot;
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
            throw new RuntimeException(
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
