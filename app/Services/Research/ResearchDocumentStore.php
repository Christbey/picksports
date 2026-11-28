<?php

namespace App\Services\Research;

use App\Models\ResearchDocument;
use App\Models\ResearchSource;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Http\ApprovedHostGet;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonInterface;

/** Explicit ingestion only; reading research never fetches a source. */
class ResearchDocumentStore
{
    public function allowed(ResearchSource $source, string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $source->enabled && parse_url($url, PHP_URL_SCHEME) === 'https'
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS) && ! parse_url($url, PHP_URL_PORT)
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && str_contains($host, '.') && ! str_ends_with($host, '.local')
            && in_array($host, (array) $source->allowed_hosts, true);
    }

    public function fetch(ResearchSource $source, ?string $url = null): string
    {
        return $this->fetchWithProvenance($source, $url)['body'];
    }

    /** @return array{body:string,url:string,requested_url:string,redirects:list<array{from:string,to:string}>} */
    public function fetchWithProvenance(ResearchSource $source, ?string $url = null): array
    {
        $url ??= $source->url;
        $requestedUrl = $url;
        $redirects = [];
        for ($hop = 0; $hop < 4; $hop++) {
            if (! $this->allowed($source, $url)) {
                throw new \InvalidArgumentException('Research source URL is not explicitly approved.');
            }
            $response = app(ApprovedHostGet::class)->get($url, (array) $source->allowed_hosts,
                (int) config('research.maximum_document_bytes', 250000), headers: ['User-Agent' => 'PickSports research reader'], followRedirects: false);
            if ($response->redirect()) {
                $next = $response->header('Location');
                if (is_string($next) && str_starts_with($next, '/') && ! str_starts_with($next, '//')) {
                    $next = 'https://'.parse_url($url, PHP_URL_HOST).$next;
                }
                if (! is_string($next) || ! $this->allowed($source, $next)) {
                    throw new \InvalidArgumentException('Research source redirect is not explicitly approved.');
                }
                $redirects[] = ['from' => $url, 'to' => $next];
                $url = $next;

                continue;
            }
            $response->throw();
            if (strlen($response->body()) > (int) config('research.maximum_document_bytes', 250000)) {
                throw new \InvalidArgumentException('Research source document exceeds the size limit.');
            }

            return ['body' => $response->body(), 'url' => $url, 'requested_url' => $requestedUrl, 'redirects' => $redirects];
        }

        throw new \InvalidArgumentException('Research source redirect limit reached.');
    }

    /** @param list<array<string, mixed>> $claims */
    public function store(ResearchSource $source, string $url, string $body, array $claims, CarbonInterface $observedAt, ?CarbonInterface $publishedAt = null, ?int $eventId = null): ResearchDocument
    {
        if (! $this->allowed($source, $url) || trim($body) === ''
            || strlen($body) > (int) config('research.maximum_document_bytes', 250000)
            || $observedAt->isFuture() || $publishedAt?->isFuture() || $publishedAt?->gt($observedAt)
            || count($claims) > 128) {
            throw new \InvalidArgumentException('Research document provenance or content is invalid.');
        }
        if ($eventId !== null && ! SportEvent::query()->whereKey($eventId)->where('sport', $source->sport)->exists()) {
            throw new \InvalidArgumentException('Research event and source sport must match.');
        }
        $context = app(SportContextResolver::class)->find($source->sport);
        if ($context === null || ! is_array(config("research.sports.{$source->sport}"))) {
            throw new \InvalidArgumentException('Research source sport is unsupported.');
        }
        if ($eventId !== null && $source->team_id !== null) {
            $gameClass = $context->models['game'];
            if (! $gameClass::query()->where('sport_event_id', $eventId)
                ->where(fn ($query) => $query->where('home_team_id', $source->team_id)->orWhere('away_team_id', $source->team_id))->exists()) {
                throw new \InvalidArgumentException('Research source team does not participate in the selected event.');
            }
        }
        foreach ($claims as $claim) {
            if (! is_array($claim) || ! is_string($claim['text'] ?? null) || trim($claim['text']) === '' || strlen($claim['text']) > 4000
                || ! str_contains($body, $claim['text']) || ! is_string($claim['topic'] ?? null)
                || ! in_array($claim['stance'] ?? 'neutral', ['supporting', 'concern', 'neutral'], true)) {
                throw new \InvalidArgumentException('Research claims require an exact excerpt in the stored document.');
            }
        }

        // An unchanged poll never rewrites first-observed/publication timestamps.
        return ResearchDocument::query()->firstOrCreate([
            'source_id' => $source->id, 'url_hash' => hash('sha256', $url), 'content_hash' => hash('sha256', $body),
            'scope_hash' => hash('sha256', $eventId === null ? 'team' : 'event:'.$eventId),
            'claims_hash' => app(CanonicalPayloadHasher::class)->hash(['parser_version' => 'exact-excerpt-v1', 'claims' => $claims]),
        ], [
            'sport' => $source->sport, 'team_id' => $source->team_id, 'sport_event_id' => $eventId,
            'url' => $url, 'body' => $body, 'claims' => $claims, 'parser_version' => 'exact-excerpt-v1',
            // SQL timestamps follow the application timezone; preserve instants from explicit source offsets.
            'observed_at' => $observedAt->copy()->setTimezone((string) config('app.timezone', 'UTC')),
            'published_at' => $publishedAt?->copy()->setTimezone((string) config('app.timezone', 'UTC')),
        ]);
    }
}
