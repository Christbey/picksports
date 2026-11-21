<?php

namespace App\Services\NBA\Injuries;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** NBA-only acquisition envelopes retain the original network time on cache hits. */
class InjuryFeedClient
{
    public function teamUrl(string $teamId, bool $roster = false): string
    {
        if (! ctype_digit($teamId)) {
            throw new \InvalidArgumentException('NBA injury acquisition requires a numeric ESPN team ID.');
        }
        $kind = $roster ? 'site' : 'core';
        $base = str_replace(['{sport}', '{league}'], ['basketball', 'nba'], (string) config("espn.bases.{$kind}"));
        $path = (string) config('espn.leagues.nba.'.$kind.'.'.($roster ? 'roster' : 'team_injuries'));

        return $base.str_replace('{teamId}', $teamId, $path);
    }

    /** @return array<string, mixed> */
    public function fetch(string $url, bool $publishCache = true): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $receipt = ['url' => $url, 'checked_at' => now()->toIso8601String(), 'fetched_at' => null,
            'cache_hit' => false, 'http_status' => null, 'error_code' => null, 'payload' => null];
        if (! in_array($host, ['sports.core.api.espn.com', 'site.api.espn.com'], true)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || ! preg_match('#/basketball/(?:leagues/)?nba/#', $parts['path'] ?? '')) {
            return [...$receipt, 'error_code' => 'unapproved_reference'];
        }
        // ESPN may supply HTTP references; the approved host/path is fetched over HTTPS.
        $url = 'https://'.$host.$parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
        $receipt['url'] = $url;
        $key = 'nba:injury-feed:v1:'.hash('sha256', $url);
        if (config('espn.cache.enabled', true)) {
            $cached = Cache::get($key);
            if (is_array($cached) && isset($cached['payload'], $cached['fetched_at'])) {
                return [...$receipt, ...$cached, 'checked_at' => now()->toIso8601String(), 'cache_hit' => true];
            }
        }
        try {
            $response = Http::timeout(20)->connectTimeout(10)
                ->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
            $receipt['http_status'] = $response->status();
            if (! $response->successful()) {
                return [...$receipt, 'error_code' => 'http_'.$response->status()];
            }
            $limit = max(1, (int) config('nba.injuries.maximum_response_bytes', 2097152));
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= $limit) {
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($body)));
                if ($chunk === '') {
                    if (! $stream->eof()) {
                        throw new \RuntimeException('Incomplete injury response stream.');
                    }
                    break;
                }
                $body .= $chunk;
            }
            $stream->close();
            if (strlen($body) > $limit) {
                return [...$receipt, 'error_code' => 'response_too_large'];
            }
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                return [...$receipt, 'error_code' => 'invalid_json_shape'];
            }
            $receipt = [...$receipt, 'payload' => $payload, 'fetched_at' => now()->toIso8601String()];
            if ($publishCache) {
                $this->cacheResponse($receipt);
            }

            return $receipt;
        } catch (Throwable $exception) {
            return [...$receipt, 'error_code' => $exception instanceof \JsonException ? 'invalid_json' : 'fetch_failed'];
        }
    }

    /** The action publishes only while holding the newest acquisition's team lock. */
    public function cacheResponse(array $receipt): void
    {
        $payload = $receipt['payload'];
        if (config('espn.cache.enabled', true) && $receipt['error_code'] === null && is_array($payload)
            && $receipt['fetched_at'] !== null && ! $receipt['cache_hit']
            && strlen(json_encode($payload, JSON_THROW_ON_ERROR)) <= (int) config('espn.cache.max_payload_bytes', 131072)
            && collect(['items', 'injuries', 'entries', 'athletes', 'id'])->contains(fn ($name) => array_key_exists($name, $payload))) {
            unset($receipt['required']);
            Cache::put('nba:injury-feed:v1:'.hash('sha256', $receipt['url']), $receipt,
                now()->addMinutes(max(1, (int) config('espn.cache.ttl_minutes', 5))));
        }
    }
}
