<?php

use App\Models\NFL\ResearchDocument as NflDocument;
use App\Models\NFL\ResearchSource as NflSource;
use App\Models\ResearchDocument;
use App\Models\ResearchSource;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\Research\ResearchDocumentStore;
use Illuminate\Support\Facades\Http;

it('refuses another teams globally approved host before requesting or attributing its document', function () {
    config()->set('nfl_research.teams', ['AAA' => 'team-a.example', 'BBB' => 'team-b.example']);
    $source = NflSource::query()->create(['key' => 'AAA:injury', 'team' => 'AAA', 'kind' => 'injury', 'url' => 'https://team-a.example/injuries']);
    Http::preventStrayRequests();
    Http::fake([
        'https://team-a.example/injuries' => Http::response('', 302, ['Location' => 'https://team-b.example/injuries']),
    ]);
    expect(fn () => app(OfficialSourceIngestor::class)->poll($source))->toThrow(RuntimeException::class, 'redirect')
        ->and(NflDocument::query()->count())->toBe(0);
    Http::assertSentCount(1);
});

it('stores the actual same-team final response URL rather than the redirecting URL', function () {
    config()->set('nfl_research.teams', ['AAA' => 'team-a.example']);
    $source = NflSource::query()->create(['key' => 'AAA:injury', 'team' => 'AAA', 'kind' => 'injury', 'url' => 'https://team-a.example/injuries']);
    Http::fake([
        'https://team-a.example/injuries' => Http::response('', 302, ['Location' => '/current-injuries']),
        'https://team-a.example/current-injuries' => Http::response('<main>'.str_repeat('Verified team A availability text. ', 4).'</main>'),
    ]);
    app(OfficialSourceIngestor::class)->poll($source);
    expect(NflDocument::query()->sole()->url)->toBe('https://team-a.example/current-injuries')
        ->and(NflDocument::query()->sole()->team)->toBe('AAA');
    Http::assertSentCount(2);
});

it('returns a traceable final URL receipt for shared explicitly admitted redirects', function () {
    $source = new ResearchSource(['enabled' => true, 'url' => 'https://source.example/report', 'allowed_hosts' => ['source.example', 'cdn.example']]);
    Http::fake([
        'https://source.example/report' => Http::response('', 302, ['Location' => 'https://cdn.example/current-report']),
        'https://cdn.example/current-report' => Http::response('Exact final report body.'),
    ]);
    $receipt = app(ResearchDocumentStore::class)->fetchWithProvenance($source);
    expect($receipt)->toBe([
        'body' => 'Exact final report body.', 'url' => 'https://cdn.example/current-report',
        'requested_url' => 'https://source.example/report',
        'redirects' => [['from' => 'https://source.example/report', 'to' => 'https://cdn.example/current-report']],
    ]);
    Http::assertSentCount(2);
});

it('persists the actual shared fetch destination through the explicit ingest command', function () {
    $claims = tempnam(sys_get_temp_dir(), 'research-redirect-claims-');
    file_put_contents($claims, json_encode([['text' => 'Exact final report body.', 'topic' => 'availability']]));
    Http::fake([
        'https://source.example/report' => Http::response('', 302, ['Location' => '/actual-report']),
        'https://source.example/actual-report' => Http::response('Exact final report body.'),
    ]);
    try {
        $this->artisan('sports:ingest-research-document', [
            'sport' => 'nba', 'source-key' => 'redirect-regression', '--register-source' => true, '--enable-source' => true,
            '--url' => 'https://source.example/report', '--allowed-host' => ['source.example'],
            '--fetch' => true, '--claims' => $claims, '--observed-at' => now()->toIso8601String(),
        ])->assertSuccessful();
        expect(ResearchDocument::query()->sole()->url)->toBe('https://source.example/actual-report');
        Http::assertSentCount(2);
    } finally {
        unlink($claims);
    }
});
