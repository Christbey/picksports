<?php

use App\Models\ResearchDocument;
use App\Models\ResearchSource;
use App\Services\Research\GameResearchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
});

afterEach(fn () => $this->travelBack());

it('registers an explicitly approved staged source without fetching', function () {
    $this->artisan('sports:ingest-research-document', [
        'sport' => 'nba', 'source-key' => 'team-official', '--register-source' => true, '--register-only' => true,
        '--url' => 'https://evidence.example/news', '--allowed-host' => ['evidence.example'],
    ])->assertSuccessful();

    expect(ResearchSource::query()->sole()->enabled)->toBeFalse()->and(ResearchDocument::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('ingests an explicit local file and preserves original observed and publication times', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $body = tempnam(sys_get_temp_dir(), 'research-body-');
    $claims = tempnam(sys_get_temp_dir(), 'research-claims-');
    file_put_contents($body, 'Fixture official lineup statement.');
    file_put_contents($claims, json_encode([['text' => 'Fixture official lineup statement.', 'topic' => 'lineup', 'stance' => 'neutral']]));
    try {
        $this->artisan('sports:ingest-research-document', [
            'sport' => 'nba', 'source-key' => 'team-official', '--register-source' => true, '--enable-source' => true,
            '--url' => 'https://evidence.example/news', '--allowed-host' => ['evidence.example'], '--team-id' => $game->home_team_id,
            '--event-id' => $game->sport_event_id, '--file' => $body, '--claims' => $claims,
            '--observed-at' => '2026-11-20T11:45:00-06:00', '--published-at' => '2026-11-20T11:30:00-06:00',
        ])->assertSuccessful();
        $document = ResearchDocument::query()->sole();
        expect($document->sport)->toBe('nba')->and($document->team_id)->toBe($game->home_team_id)
            ->and($document->sport_event_id)->toBe($game->sport_event_id)
            ->and($document->observed_at->timestamp)->toBe(now()->subMinutes(15)->timestamp)
            ->and($document->published_at->timestamp)->toBe(now()->subMinutes(30)->timestamp);
        Http::assertNothingSent();
    } finally {
        unlink($body);
        unlink($claims);
    }
});

it('does not silently enable or overwrite an existing source policy', function () {
    ResearchSource::query()->create([
        'sport' => 'nba', 'source_key' => 'team-official', 'kind' => 'official',
        'url' => 'https://evidence.example/news', 'allowed_hosts' => ['evidence.example'], 'enabled' => false,
    ]);
    $this->artisan('sports:ingest-research-document', [
        'sport' => 'nba', 'source-key' => 'team-official', '--register-source' => true, '--register-only' => true,
        '--url' => 'https://evil.example/news', '--allowed-host' => ['evil.example'], '--enable-source' => true,
    ])->assertFailed();
    expect(ResearchSource::query()->sole()->enabled)->toBeFalse()
        ->and(ResearchSource::query()->sole()->allowed_hosts)->toBe(['evidence.example']);
});

it('rejects unapproved source registration without creating a source', function () {
    $this->artisan('sports:ingest-research-document', [
        'sport' => 'nba', 'source-key' => 'unsafe', '--register-source' => true, '--register-only' => true,
        '--url' => 'https://127.0.0.1/private', '--allowed-host' => ['127.0.0.1'],
    ])->assertFailed();
    expect(ResearchSource::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('fetches the same approved daily report URL that it records instead of the registered URL', function () {
    $source = ResearchSource::query()->create([
        'sport' => 'nba', 'source_key' => 'daily-official', 'kind' => 'official',
        'url' => 'https://evidence.example/old-report', 'allowed_hosts' => ['evidence.example'], 'enabled' => true,
    ]);
    $requestedUrl = 'https://evidence.example/new-report';
    $body = 'Fixture new official report excerpt.';
    $claims = tempnam(sys_get_temp_dir(), 'research-fetch-claims-');
    file_put_contents($claims, json_encode([['text' => $body, 'topic' => 'availability']]));
    Http::fake([
        $source->url => Http::response('Fixture old official report excerpt.'),
        $requestedUrl => Http::response($body),
    ]);

    try {
        $this->artisan('sports:ingest-research-document', [
            'sport' => 'nba', 'source-key' => 'daily-official', '--fetch' => true,
            '--url' => $requestedUrl, '--claims' => $claims,
            '--observed-at' => '2026-11-20T11:45:00-06:00', '--published-at' => '2026-11-20T11:30:00-06:00',
        ])->assertSuccessful();

        $document = ResearchDocument::query()->sole();
        expect($document->url)->toBe($requestedUrl)->and($document->body)->toBe($body)
            ->and($source->fresh()->url)->toBe('https://evidence.example/old-report');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === $requestedUrl);
        Http::assertNotSent(fn ($request) => $request->url() === $source->url);
    } finally {
        unlink($claims);
    }
});

it('rejects an unapproved fetched URL override without requesting or storing it', function () {
    ResearchSource::query()->create([
        'sport' => 'nba', 'source_key' => 'daily-official', 'kind' => 'official',
        'url' => 'https://evidence.example/old-report', 'allowed_hosts' => ['evidence.example'], 'enabled' => true,
    ]);
    $claims = tempnam(sys_get_temp_dir(), 'research-fetch-claims-');
    file_put_contents($claims, json_encode([['text' => 'Fixture excerpt.', 'topic' => 'availability']]));

    try {
        $this->artisan('sports:ingest-research-document', [
            'sport' => 'nba', 'source-key' => 'daily-official', '--fetch' => true,
            '--url' => 'https://unapproved.example/new-report', '--claims' => $claims,
            '--observed-at' => '2026-11-20T11:45:00-06:00',
        ])->assertFailed();

        expect(ResearchDocument::query()->count())->toBe(0);
        Http::assertNothingSent();
    } finally {
        unlink($claims);
    }
});

it('normalizes mixed source offsets before SQL storage so fresh NBA evidence remains usable', function () {
    config(['research.enabled' => true, 'research.sports.nba.enabled' => true]);
    [$game] = PredictionReviewFixtures::game('nba');
    $body = tempnam(sys_get_temp_dir(), 'research-zone-body-');
    $claims = tempnam(sys_get_temp_dir(), 'research-zone-claims-');
    file_put_contents($body, 'Fixture reviewed availability excerpt.');
    file_put_contents($claims, json_encode([['text' => 'Fixture reviewed availability excerpt.', 'topic' => 'availability', 'stance' => 'neutral']]));
    try {
        $this->artisan('sports:ingest-research-document', [
            'sport' => 'nba', 'source-key' => 'mixed-offset-official', '--register-source' => true, '--enable-source' => true,
            '--url' => 'https://evidence.example/report', '--allowed-host' => ['evidence.example'], '--team-id' => $game->home_team_id,
            '--event-id' => $game->sport_event_id, '--file' => $body, '--claims' => $claims,
            '--observed-at' => '2026-11-20T17:45:00+00:00', '--published-at' => '2026-11-20T12:30:00-05:00',
        ])->assertSuccessful();
        $document = ResearchDocument::query()->sole();
        $result = app(GameResearchService::class)->assess($game, 'nba');
        expect($document->observed_at->timestamp)->toBe(CarbonImmutable::parse('2026-11-20T17:45:00+00:00')->timestamp)
            ->and($document->published_at->timestamp)->toBe(CarbonImmutable::parse('2026-11-20T12:30:00-05:00')->timestamp)
            ->and($result['status'])->toBe('partial')
            ->and($result['source_coverage']['availability']['home'])->toBeTrue()
            ->and(CarbonImmutable::parse($result['expires_at'])->timestamp)->toBe(CarbonImmutable::parse('2026-11-20T19:30:00+00:00')->timestamp);
        Http::assertNothingSent();
    } finally {
        unlink($body);
        unlink($claims);
    }
});
