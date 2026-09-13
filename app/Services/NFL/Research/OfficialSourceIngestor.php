<?php

namespace App\Services\NFL\Research;

use App\Jobs\ESPN\NFL\FetchPlayers;
use App\Models\NFL\Player;
use App\Models\NFL\ResearchDocument;
use App\Models\NFL\ResearchSource;
use App\Models\NFL\Team;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OfficialSourceIngestor
{
    public function sync(?array $teams = null): array
    {
        $results = [];
        foreach (config('nfl_research.teams', []) as $team => $host) {
            if ($teams !== null && ! in_array($team, $teams, true)) {
                continue;
            }
            // Newsroom fallback also discovers transactions missing from a valid RSS feed.
            foreach (['rss' => '/rss/news', 'newsroom' => '/news/', 'injury' => '/team/injury-report/', 'roster' => '/team/players-roster/'] as $kind => $path) {
                $source = ResearchSource::firstOrCreate(['key' => $team.':'.$kind], ['team' => $team, 'url' => 'https://'.$host.$path, 'kind' => $kind]);
                try {
                    $results[$source->key] = $this->poll($source);
                } catch (Throwable $e) {
                    $source->update(['checked_at' => now(), 'error' => class_basename($e)]);
                    $results[$source->key] = ['error' => class_basename($e)];
                } finally {
                    // Release HTTP/DOM cycles between sources on full-slate command runs.
                    gc_collect_cycles();
                }
            }
        }

        return $results;
    }

    public function poll(ResearchSource $source): array
    {
        $headers = array_filter(['If-None-Match' => $source->etag, 'If-Modified-Since' => $source->last_modified]);
        $response = $this->fetch($source->url, $headers);
        if ($response->status() === 304) {
            $source->update(['checked_at' => now(), 'succeeded_at' => now(), 'error' => null]);

            return ['changed' => 0];
        }
        $body = $response->body();
        $changed = 0;
        $latest = null;
        if ($source->kind === 'rss') {
            $xml = $this->xml($body);
            if ($xml->getElementsByTagName('rss')->length === 0) {
                throw new RuntimeException('Not an RSS feed');
            }
            $items = [];
            foreach ($xml->getElementsByTagName('item') as $item) {
                $date = $item->getElementsByTagName('pubDate')->item(0)?->textContent;
                try {
                    $published = $date ? Carbon::parse($date)->setTimezone(config('app.timezone', 'UTC')) : null;
                } catch (Throwable) {
                    $published = null;
                }
                if (! $published || $published->isFuture()) {
                    continue;
                }
                $latest = ! $latest || $published->gt($latest) ? $published : $latest;
                if ($published->lt(now()->subDays(config('nfl_research.lookback_days', 14)))) {
                    continue;
                }
                $items[] = ['url' => trim($item->getElementsByTagName('link')->item(0)?->textContent ?? ''), 'title' => trim($item->getElementsByTagName('title')->item(0)?->textContent ?? ''), 'published' => $published];
            }
            usort($items, fn ($a, $b) => $b['published'] <=> $a['published']);
            foreach (array_slice($items, 0, config('nfl_research.max_articles_per_source', 12)) as $item) {
                $changed += $this->article($source, $item['url'], $item['title'], $item['published']);
            }
        } elseif ($source->kind === 'newsroom') {
            $dom = $this->html($body);
            foreach ((new DOMXPath($dom))->query('//nav|//header|//footer') as $navigation) {
                $navigation->parentNode?->removeChild($navigation);
            }
            $urls = [];
            foreach ($dom->getElementsByTagName('a') as $link) {
                $url = $link->getAttribute('href');
                if (str_starts_with($url, '/')) {
                    $url = 'https://'.parse_url($source->url, PHP_URL_HOST).$url;
                }
                if ($this->allowed($url) && preg_match('~/news/[^/?]+~', $url)) {
                    $urls[$url] = trim($link->textContent);
                }
            }
            foreach (array_slice($urls, 0, config('nfl_research.max_articles_per_source', 12), true) as $url => $title) {
                $changed += $this->article($source, $url, $title, null);
            }
        } elseif ($source->kind === 'roster') {
            $rows = $this->roster($body);
            $changed += $this->save($source, $source->url, 'Official roster and reserve lists', json_encode($rows), null, $rows);
            $this->refreshUnmatchedRoster($source->team, $rows);
        } else {
            $changed += $this->save($source, $source->url, 'Official injury report', $this->text($body), null);
        }
        $source->update(['checked_at' => now(), 'succeeded_at' => now(), 'error' => null, 'etag' => $response->header('ETag'), 'last_modified' => $response->header('Last-Modified'), 'latest_published_at' => $latest ?? $source->latest_published_at]);

        return ['changed' => $changed];
    }

    private function article(ResearchSource $source, string $url, string $title, ?Carbon $published): int
    {
        // Prevent cross-team attribution as well as arbitrary feed-controlled requests.
        if (parse_url($url, PHP_URL_HOST) !== parse_url($source->url, PHP_URL_HOST)) {
            return 0;
        }
        $html = $this->fetch($url)->body();
        $dom = $this->html($html);
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if ($meta->getAttribute('property') === 'article:published_time') {
                try {
                    $published = Carbon::parse($meta->getAttribute('content'))->setTimezone(config('app.timezone', 'UTC'));
                } catch (Throwable) { /* Keep feed timestamp. */
                }
            }
        }
        // Newsroom navigation also links to categories, media guides and signup pages.
        if ($source->kind === 'newsroom' && ! $published) {
            return 0;
        }
        if ($published && ($published->isFuture() || $published->lt(now()->subDays(config('nfl_research.lookback_days', 14))))) {
            return 0;
        }

        return $this->save($source, $url, $title, $this->text($html), $published);
    }

    private function save(ResearchSource $source, string $url, string $title, string $body, ?Carbon $published, ?array $structured = null): int
    {
        if (mb_strlen($body) < 50) {
            throw new RuntimeException('Empty source document');
        }
        $doc = ResearchDocument::firstOrCreate(['url_hash' => hash('sha256', $url), 'content_hash' => hash('sha256', $body)], ['source_id' => $source->id, 'team' => $source->team, 'url' => $url, 'title' => mb_substr($title, 0, 2000), 'body' => $body, 'structured' => $structured, 'published_at' => $published, 'observed_at' => now()]);

        return (int) $doc->wasRecentlyCreated;
    }

    public function roster(string $html): array
    {
        $dom = $this->html($html);
        $xp = new DOMXPath($dom);
        $rows = [];
        foreach ($xp->query('//table') as $table) {
            $heading = trim($xp->query('./caption', $table)->item(0)?->textContent ?? $xp->query('preceding::*[self::h2 or self::h3 or self::h4][1]', $table)->item(0)?->textContent ?? '');
            foreach ($xp->query('.//tbody/tr', $table) as $tr) {
                $name = trim($xp->query('./td[1]//a[normalize-space(.) != ""]', $tr)->item(0)?->textContent ?? '');
                if ($name !== '') {
                    $rows[] = ['player_name' => preg_replace('/\s+/u', ' ', $name), 'roster_status' => $heading];
                }
            }
        }
        if (count($rows) < 30) {
            throw new RuntimeException('Incomplete roster parsing');
        }

        return $rows;
    }

    public function refreshUnmatchedRoster(string $abbreviation, array $rows): void
    {
        $team = Team::whereIn('abbreviation', $abbreviation === 'WAS' ? ['WAS', 'WSH'] : [$abbreviation])->first();
        if (! $team?->espn_id) {
            return;
        }
        $names = Player::where('team_id', $team->id)->pluck('full_name')->map(fn ($name) => EvidencePacket::normalizePlayerName($name));
        $missing = collect($rows)->contains(fn ($row) => ! $names->contains(EvidencePacket::normalizePlayerName($row['player_name'])));
        if ($missing && Cache::add('nfl-research-roster-refresh:'.$team->id, true, now()->addHour())) {
            FetchPlayers::dispatch((string) $team->espn_id);
        }
    }

    private function allowed(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS)
            && ! parse_url($url, PHP_URL_PORT)
            && in_array(parse_url($url, PHP_URL_HOST), array_values(config('nfl_research.teams', [])), true);
    }

    private function fetch(string $url, array $headers = [])
    {
        if (! $this->allowed($url)) {
            throw new RuntimeException('Source host not allowed');
        }
        for ($hop = 0; $hop < 4; $hop++) {
            $r = Http::withHeaders($headers)->withUserAgent('Picksports research feed reader')->timeout(15)
                ->withOptions(['allow_redirects' => false])->get($url);
            if (in_array($r->status(), [301, 302, 303, 307, 308], true)) {
                $next = $r->header('Location');
                if (str_starts_with($next ?? '', '/') && ! str_starts_with($next, '//')) {
                    $next = 'https://'.parse_url($url, PHP_URL_HOST).$next;
                }
                if (! is_string($next) || ! $this->allowed($next)) {
                    throw new RuntimeException('Unapproved source redirect');
                }
                $url = $next;

                continue;
            }
            break;
        }
        if ($r->status() !== 304 && ! $r->successful()) {
            throw new RuntimeException('Source HTTP failure');
        }
        if (strlen($r->body()) > 3_000_000) {
            throw new RuntimeException('Oversized source');
        }

        return $r;
    }

    private function xml(string $body): DOMDocument
    {
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new RuntimeException('Unsafe XML');
        }
        $dom = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($body, LIBXML_NONET)) {
                throw new RuntimeException('Invalid XML');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        return $dom;
    }

    private function html(string $body): DOMDocument
    {
        $dom = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        return $dom;
    }

    private function text(string $body): string
    {
        $dom = $this->html($body);
        $xp = new DOMXPath($dom);
        foreach ($xp->query('//script|//style|//nav|//footer|//header') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $node = $xp->query('//article|//main')->item(0) ?? $dom;

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $node->textContent)), 0, 60000);
    }
}
