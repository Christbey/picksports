<?php

use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Models\NFL\Player;
use App\Models\NFL\ResearchDocument;
use App\Models\NFL\ResearchSource;
use App\Models\NFL\Team;
use App\Models\SportsGameContextReport;
use App\Services\AI\AiProviderRateLimitCircuitBreaker;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\NFL\OpenAiNflGameContextResearchClient;
use App\Services\NFL\Research\EvidencePacket;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses()->group('nfl', 'research');

beforeEach(function () {
    $this->travelTo('2026-09-16 12:00:00');
    config(['nfl_research.enabled' => true]);
});
afterEach(fn () => $this->travelBack());

function reliabilityResearchGame(array $attributes = []): Game
{
    return Game::factory()->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_date' => '2026-09-20', 'game_time' => '17:00:00',
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED',
        ...$attributes,
    ]);
}

it('researches the upcoming weekly slate by default and excludes past kickoffs', function () {
    reliabilityResearchGame(['game_date' => '2026-09-16', 'game_time' => '01:00:00']);
    $future = reliabilityResearchGame();
    $this->mock(ResearchPipeline::class, function ($mock) use ($future) {
        $mock->shouldReceive('review')->once()->with(Mockery::on(fn (Game $g) => $g->is($future)), false)->andReturnNull();
    });

    $this->artisan('nfl:research-pipeline', ['--no-ingest' => true, '--no-web' => true])
        ->expectsOutputToContain('1 eligible game(s); reviewing 1 this run')->assertSuccessful();
});

it('rotates persisted and cached timestamps chronologically despite different timestamp formats', function () {
    $older = reliabilityResearchGame();
    $newer = reliabilityResearchGame();
    Cache::put('nfl-research-last-reviewed:'.$older->id, now()->subHours(2)->toIso8601String());
    SportsGameContextReport::create([
        'sport' => 'nfl', 'game_id' => $newer->id, 'prompt_version' => 'test',
        'input_hash' => hash('sha256', 'rotation'), 'researched_at' => now()->subHour(),
    ]);
    $this->mock(ResearchPipeline::class, function ($mock) use ($older) {
        $mock->shouldReceive('review')->once()->with(Mockery::on(fn (Game $g) => $g->is($older)), false)->andReturnNull();
    });

    $this->artisan('nfl:research-pipeline', ['--no-ingest' => true, '--no-web' => true, '--limit' => 1])->assertSuccessful();
});

it('honors a shared provider cooldown in direct pipeline service calls without fabricating reports', function () {
    app(AiProviderRateLimitCircuitBreaker::class)->trip('openai');
    $this->mock(OpenAiNflGameContextResearchClient::class, fn ($mock) => $mock->shouldNotReceive('research'));

    expect(fn () => app(NflWebContextResearchService::class)->research(reliabilityResearchGame(), 'openai'))
        ->toThrow(RuntimeException::class, 'rate-limit cooldown');
    $this->assertDatabaseCount('sports_game_context_reports', 0);
    $this->assertDatabaseCount('ai_generations', 0);
});

it('opens the shared provider circuit on exhausted quota for direct research calls', function () {
    $this->mock(OpenAiNflGameContextResearchClient::class, fn ($mock) => $mock->shouldReceive('research')->once()->andThrow(new RuntimeException('status 429 insufficient_quota')));
    $game = reliabilityResearchGame();
    expect(fn () => app(NflWebContextResearchService::class)->research($game, 'openai'))
        ->toThrow(RuntimeException::class, 'insufficient_quota');

    expect(app(AiProviderRateLimitCircuitBreaker::class)->retryAfterSeconds('openai'))->toBeGreaterThan(0);
    $this->assertDatabaseCount('sports_game_context_reports', 0);
    $this->assertDatabaseHas('ai_generations', ['status' => 'failed']);
});

it('retains unresolved questions when a provider fails to supply a verified citation', function () {
    $service = app(NflWebContextResearchService::class);
    $result = (new ReflectionMethod($service, 'decisionResearch'))->invoke($service, [
        'supporting' => [['claim' => 'Unverified support', 'source_url' => 'https://invented.example/news']],
        'unresolved' => [['claim' => 'Starting quarterback availability is unresolved', 'source_url' => 'https://invented.example/news', 'scope' => 'game', 'blocking' => true]],
    ], []);

    expect($result['supporting'])->toBeEmpty()
        ->and($result['unresolved'][0]['blocking'])->toBeTrue()
        ->and($result['unresolved'][0]['source_url'])->toBeNull()
        ->and($result['unresolved'][0]['evidence_status'])->toBe('unverified_question');
});

it('supplies timestamped forecast data and matchup evidence guidance to research', function () {
    $game = reliabilityResearchGame(['venue_name' => 'AT&T Stadium', 'roof' => null]);
    GameWeather::create(['game_id' => $game->id, 'provider' => 'open_meteo', 'temperature_f' => 70, 'wind_speed_mph' => 20, 'precipitation_inches' => 0.1, 'observed_at' => '2026-09-20 17:00:00']);
    $service = app(NflWebContextResearchService::class);
    $input = (new ReflectionMethod($service, 'input'))->invoke($service, $game->load('homeTeam', 'awayTeam'));
    $prompt = (new ReflectionMethod($service, 'prompt'))->invoke($service, $input);

    expect($input['synced_weather']['fresh'])->toBeTrue()
        ->and($input['synced_weather']['roof_status'])->toBe('unknown_retractable')
        ->and((float) $input['synced_weather']['wind_speed_mph'])->toBe(20.0)
        ->and($prompt)->toContain('offensive-line availability against pressure')
        ->and($prompt)->toContain('small early-season samples');
});

it('requires fresh availability sources but accepts either official news discovery channel', function () {
    $game = reliabilityResearchGame()->load('homeTeam', 'awayTeam');
    foreach ([$game->homeTeam->abbreviation, $game->awayTeam->abbreviation] as $team) {
        foreach (['rss', 'newsroom', 'roster', 'injury'] as $kind) {
            ResearchSource::create([
                'key' => $team.':'.$kind, 'team' => $team, 'kind' => $kind, 'url' => 'https://example.com/'.$kind,
                'succeeded_at' => now(), 'checked_at' => now(), 'error' => $kind === 'rss' ? 'NotAnRssFeed' : null,
            ]);
        }
    }
    $packet = app(EvidencePacket::class)->forGame($game);
    expect($packet['holds'])->toBeEmpty()->and($packet['source_coverage'][$game->homeTeam->abbreviation]['rss']['fresh'])->toBeFalse();

    ResearchSource::where('team', $game->homeTeam->abbreviation)->whereIn('kind', ['newsroom', 'injury'])->update(['error' => 'HTTP failure']);
    $holds = app(EvidencePacket::class)->forGame($game)['holds'];
    expect($holds)->toContain('stale_or_missing_news_'.$game->homeTeam->abbreviation, 'stale_or_missing_injury_'.$game->homeTeam->abbreviation);
});

it('bounds ingestion time and resumes with never-attempted source kinds', function () {
    config(['nfl_research.teams' => ['BUF' => 'www.buffalobills.com'], 'nfl_research.ingestion_max_seconds' => 1]);
    $seen = [];
    $ingestor = Mockery::mock(OfficialSourceIngestor::class)->makePartial();
    $ingestor->shouldReceive('poll')->twice()->andReturnUsing(function (ResearchSource $source) use (&$seen) {
        $seen[] = $source->kind;
        $source->update(['checked_at' => now(), 'succeeded_at' => now()]);
        $this->travel(2)->seconds();

        return ['changed' => 0];
    });
    $first = $ingestor->sync(['BUF']);
    $this->travel(16)->minutes();
    $second = $ingestor->sync(['BUF']);

    expect($first['batch']['deferred'])->toBeTrue()->and($second['batch']['deferred'])->toBeTrue()
        ->and($seen)->toHaveCount(2)->and($seen[0])->not->toBe($seen[1]);
});

it('keeps the exact confirmed roster across the season and content reversions without rewriting first observation', function () {
    $game = reliabilityResearchGame()->load('homeTeam', 'awayTeam');
    $player = Player::factory()->create(['team_id' => $game->home_team_id, 'full_name' => 'Example Player']);
    $source = ResearchSource::create(['key' => 'test:roster', 'team' => $game->homeTeam->abbreviation, 'kind' => 'roster', 'url' => 'https://example.com/roster']);
    $save = new ReflectionMethod(OfficialSourceIngestor::class, 'save');
    $ingestor = app(OfficialSourceIngestor::class);
    $reserve = [['player_name' => 'Example Player', 'roster_status' => 'Reserve/Injured']];
    $active = [['player_name' => 'Example Player', 'roster_status' => 'Active roster']];
    $save->invoke($ingestor, $source, $source->url, 'Roster', json_encode($reserve), null, $reserve);
    $original = ResearchDocument::findOrFail($source->fresh()->current_document_id);
    $firstObserved = $original->observed_at;
    $this->travel(1)->days();
    $save->invoke($ingestor, $source, $source->url, 'Roster', json_encode($active), null, $active);
    $this->travel(20)->days();
    $save->invoke($ingestor, $source, $source->url, 'Roster', json_encode($reserve), null, $reserve);
    $source->update(['succeeded_at' => now(), 'checked_at' => now(), 'error' => null]);

    $packet = app(EvidencePacket::class)->forGame($game);
    expect((int) $source->fresh()->current_document_id)->toBe($original->id)
        ->and($original->fresh()->observed_at->equalTo($firstObserved))->toBeTrue()
        ->and($packet['availability'][0]['player_id'])->toBe($player->id)
        ->and($packet['availability'][0]['status'])->toBe('Out');
    $this->assertDatabaseCount('nfl_research_documents', 2);
});

it('passes the configured research timeout without retrying potentially paid timed-out requests', function () {
    config(['ai.providers.openai.key' => 'test-key', 'ai.features.nfl_game_context_research.timeout_seconds' => 120]);
    $attempts = 0;
    Http::fake(function ($request, array $options) use (&$attempts) {
        $attempts++;
        expect($options['timeout'])->toBe(120);

        throw new ConnectionException('Research request timed out.');
    });

    expect(fn () => app(OpenAiNflGameContextResearchClient::class)->research('Sourced game context', 'gpt-5.6-luna'))
        ->toThrow(ConnectionException::class);
    expect($attempts)->toBe(1);
});

it('matches omitted generational suffixes only when the current-team identity is unique', function () {
    $game = reliabilityResearchGame()->load('homeTeam', 'awayTeam');
    $player = Player::factory()->create(['team_id' => $game->home_team_id, 'full_name' => 'Keir Thomas II']);
    $source = ResearchSource::create(['key' => 'suffix:roster', 'team' => $game->homeTeam->abbreviation, 'kind' => 'roster', 'url' => 'https://example.com/roster', 'succeeded_at' => now()]);
    $document = ResearchDocument::create([
        'source_id' => $source->id, 'team' => $source->team, 'url' => $source->url,
        'url_hash' => hash('sha256', $source->url), 'content_hash' => hash('sha256', 'suffix'),
        'title' => 'Roster', 'body' => 'Official roster', 'observed_at' => now(),
        'structured' => [['player_name' => 'Keir Thomas', 'roster_status' => 'Reserve/Injured']],
    ]);
    $source->update(['current_document_id' => $document->id]);
    expect(app(EvidencePacket::class)->forGame($game)['availability'][0]['player_id'])->toBe($player->id);

    Player::factory()->create(['team_id' => $game->home_team_id, 'full_name' => 'Keir Thomas Jr.']);
    $packet = app(EvidencePacket::class)->forGame($game);
    expect($packet['availability'])->toBeEmpty()->and($packet['holds'])->toContain('unlinked_reserve_player:Keir Thomas');
    expect(EvidencePacket::normalizePlayerName('Kelvin Gilliam Jr.'))->toBe(EvidencePacket::normalizePlayerName('Kelvin Gilliam'))
        ->and(EvidencePacket::normalizePlayerName('Zachary Carter'))->not->toBe(EvidencePacket::normalizePlayerName('Zach Carter'));
});

it('uses a roster-provided canonical profile path without guessing nickname aliases or crossing teams', function () {
    expect(EvidencePacket::rosterIdentityKeys(['player_name' => 'Zach Carter', 'profile_path' => '/team/players-roster/zachary-carter/']))
        ->toBe(['zachcarter', 'zacharycarter']);
    expect(EvidencePacket::rosterIdentityKeys(['player_name' => 'Zach Carter', 'profile_path' => 'https://untrusted.example/team/players-roster/zachary-carter/']))
        ->toBe(['zachcarter']);

    $game = reliabilityResearchGame()->load('homeTeam', 'awayTeam');
    $player = Player::factory()->create(['team_id' => $game->home_team_id, 'full_name' => 'Zachary Carter']);
    $source = ResearchSource::create(['key' => 'profile:roster', 'team' => $game->homeTeam->abbreviation, 'kind' => 'roster', 'url' => 'https://example.com/roster', 'succeeded_at' => now()]);
    ResearchDocument::create([
        'source_id' => $source->id, 'team' => $source->team, 'url' => $source->url,
        'url_hash' => hash('sha256', $source->url), 'content_hash' => hash('sha256', 'profile'),
        'title' => 'Roster', 'body' => 'Official roster', 'observed_at' => now(),
        'structured' => [['player_name' => 'Zach Carter', 'profile_path' => '/team/players-roster/zachary-carter/', 'roster_status' => 'Reserve/Injured']],
    ]);
    expect(app(EvidencePacket::class)->forGame($game)['availability'][0]['player_id'])->toBe($player->id);

    $player->update(['team_id' => $game->away_team_id]);
    $packet = app(EvidencePacket::class)->forGame($game);
    expect($packet['availability'])->toBeEmpty()->and($packet['holds'])->toContain('unlinked_reserve_player:Zach Carter');
});

it('preserves official roster profile paths when parsing identities', function () {
    $active = str_repeat('<tr><td><a>Active Player</a></td></tr>', 30);
    $html = '<table><caption>Active</caption><tbody>'.$active.'</tbody></table><table><caption>Reserve/Injured</caption><tbody><tr><td><a href="/team/players-roster/zachary-carter/">Zach Carter</a></td></tr></tbody></table>';

    $rows = app(OfficialSourceIngestor::class)->roster($html);
    expect($rows[30]['profile_path'])->toBe('/team/players-roster/zachary-carter/');
});

it('fetches the complete roster once when an older document lacks canonical profile paths', function () {
    config(['nfl_research.teams' => ['ARI' => 'www.azcardinals.com']]);
    $source = ResearchSource::create(['key' => 'ARI:roster', 'team' => 'ARI', 'kind' => 'roster', 'url' => 'https://www.azcardinals.com/team/players-roster/', 'etag' => 'old-etag']);
    $document = ResearchDocument::create([
        'source_id' => $source->id, 'team' => 'ARI', 'url' => $source->url,
        'url_hash' => hash('sha256', $source->url), 'content_hash' => hash('sha256', 'old-roster'),
        'title' => 'Roster', 'body' => 'Roster', 'observed_at' => now(),
        'structured' => [['player_name' => 'Zach Carter', 'roster_status' => 'Reserve/Injured']],
    ]);
    $source->update(['current_document_id' => $document->id]);
    $html = '<table><caption>Active</caption><tbody>'.str_repeat('<tr><td><a href="/team/players-roster/example-player/">Example Player</a></td></tr>', 30).'</tbody></table>';
    Http::fakeSequence()->push($html, 200, ['ETag' => 'current-etag'])->push('', 304);
    $ingestor = app(OfficialSourceIngestor::class);

    $ingestor->poll($source);
    $ingestor->poll($source->fresh());

    $requests = Http::recorded();
    expect($requests[0][0]->hasHeader('If-None-Match'))->toBeFalse()
        ->and($requests[1][0]->header('If-None-Match'))->toBe(['current-etag'])
        ->and($source->fresh()->current_document_id)->not->toBe($document->id);
});
