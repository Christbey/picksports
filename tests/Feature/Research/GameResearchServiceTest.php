<?php

use App\Models\GameResearchAssessment;
use App\Models\ResearchDocument;
use App\Models\ResearchSource;
use App\Models\SportsGameContextReport;
use App\Services\Research\GameResearchService;
use App\Services\Research\ResearchDocumentStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
    config(['research.enabled' => true]);
    foreach (['nba', 'mlb', 'wnba', 'cbb', 'wcbb', 'cfb'] as $sport) {
        config(["research.sports.{$sport}.enabled" => true]);
    }
});

afterEach(fn () => $this->travelBack());

function researchSourceFor($game, string $sport, string $side = 'home'): ResearchSource
{
    return ResearchSource::query()->create([
        'sport' => $sport, 'source_key' => $side.'-official', 'team_id' => $game->{$side.'_team_id'},
        'kind' => 'official', 'url' => 'https://evidence.example/'.$side, 'allowed_hosts' => ['evidence.example'], 'enabled' => true,
    ]);
}

function researchDocumentFor(ResearchSource $source, string $topic, string $stance = 'neutral', string $suffix = ''): ResearchDocument
{
    $body = 'Fixture source excerpt for '.$topic.$suffix;

    return app(ResearchDocumentStore::class)->store($source, $source->url.'/'.$topic.$suffix, $body,
        [['text' => $body, 'topic' => $topic, 'stance' => $stance]], now()->subMinutes(10), now()->subMinutes(20));
}

it('provides descriptive coverage for every non-NFL sport without inventing lineup evidence or approval', function (string $sport) {
    [$game] = PredictionReviewFixtures::game($sport);
    $result = app(GameResearchService::class)->assess($game, $sport);

    expect($result['status'])->toBe('unavailable')
        ->and($result['supporting'])->toBe([])->and($result['sources'])->toBe([])
        ->and($result['season_evidence']['home']['sample_games'])->toBe(0)
        ->and($result['season_evidence']['home']['average_margin'])->toBeNull()
        ->and($result['numeric_adjustment_policy'])->toBe('descriptive_only')
        ->and($result)->not->toHaveKeys(['predicted_spread', 'win_probability', 'approval_id'])
        ->and(GameResearchAssessment::query()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['nba', 'mlb', 'wnba', 'cbb', 'wcbb', 'cfb']);

it('retains balanced grounded source findings with exact document references', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    foreach (['home', 'away'] as $side) {
        $source = researchSourceFor($game, 'nba', $side);
        foreach (config('research.sports.nba.topics') as $index => $topic) {
            researchDocumentFor($source, $topic, $index === 0 ? ($side === 'home' ? 'supporting' : 'concern') : 'neutral');
        }
    }
    $result = app(GameResearchService::class)->assess($game, 'nba');

    expect($result['status'])->toBe('ready')
        ->and($result['supporting'][0]['team_side'])->toBe('home')
        ->and($result['concerns'][0]['team_side'])->toBe('away')
        ->and($result['supporting'][0]['evidence_status'])->toBe('reference_verified')
        ->and($result['unresolved'])->toBe([])
        ->and($result['report_id'])->toBe(SportsGameContextReport::query()->sole()->id);
});

it('keeps semantic evidence current after database hydration', function (string $sport) {
    [$game] = PredictionReviewFixtures::game($sport);
    researchDocumentFor(researchSourceFor($game, $sport), config("research.sports.{$sport}.topics")[0], 'supporting');
    $first = app(GameResearchService::class)->assess($game, $sport);
    $read = app(GameResearchService::class)->forGame($game->fresh(), $sport);

    expect($read['applicability'])->toBe('current')->and($read['evidence_hash'])->toBe($first['evidence_hash']);
})->with(['nba', 'mlb']);

it('keeps one-sided evidence partial and reports unsupported coverage without holding a bet', function () {
    [$game] = PredictionReviewFixtures::game('mlb');
    researchDocumentFor(researchSourceFor($game, 'mlb'), 'starting_pitcher', 'supporting');
    $result = app(GameResearchService::class)->assess($game, 'mlb');

    expect($result['status'])->toBe('partial')
        ->and($result['source_coverage']['starting_pitcher'])->toBe(['home' => true, 'away' => false])
        ->and($result['reason_codes'])->toContain('source_coverage_incomplete', 'two_sided_evidence_incomplete')
        ->and(collect($result['unresolved'])->every(fn ($item) => $item['blocking'] === false))->toBeTrue();
});

it('reuses unchanged evidence without replacing timestamps or creating reports during reads', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    researchDocumentFor(researchSourceFor($game, 'nba'), 'availability', 'supporting');
    $service = app(GameResearchService::class);
    $first = $service->assess($game, 'nba');
    $this->travel(5)->minutes();
    $second = $service->assess($game, 'nba', force: true);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $read = $service->forGame($game, 'nba');
    $writes = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query']));
    DB::disableQueryLog();

    expect($first['report_id'])->toBe($second['report_id'])
        ->and($first['assessed_at'])->toBe($second['assessed_at'])
        ->and($read['applicability'])->toBe('current')->and($writes)->toBeEmpty()
        ->and(SportsGameContextReport::query()->count())->toBe(1);
    Http::assertNothingSent();
});

it('does not freshen old content when a source is polled again', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $source = researchSourceFor($game, 'nba');
    $document = researchDocumentFor($source, 'availability', 'supporting');
    $first = app(GameResearchService::class)->assess($game, 'nba');
    $this->travel(111)->minutes();
    $source->update(['checked_at' => now(), 'succeeded_at' => now()]);
    $again = app(ResearchDocumentStore::class)->store($source, $document->url, $document->body, $document->claims, now(), $document->published_at);
    $read = app(GameResearchService::class)->forGame($game, 'nba');

    expect($again->id)->toBe($document->id)->and($again->observed_at->equalTo($document->observed_at))->toBeTrue()
        ->and($read['applicability'])->toBe('expired')
        ->and($first['expires_at'])->toBe($document->published_at->addMinutes(120)->toIso8601String());
});

it('invalidates changed documents and retains immutable report and assessment history', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $source = researchSourceFor($game, 'nba');
    researchDocumentFor($source, 'availability', 'supporting');
    $first = app(GameResearchService::class)->assess($game, 'nba');
    researchDocumentFor($source, 'availability', 'concern', '-changed');
    expect(app(GameResearchService::class)->forGame($game, 'nba')['applicability'])->toBe('outdated');
    $second = app(GameResearchService::class)->assess($game, 'nba');

    expect($first['report_id'])->not->toBe($second['report_id'])
        ->and(GameResearchAssessment::query()->count())->toBe(2)
        ->and(fn () => GameResearchAssessment::query()->first()->update(['evidence_hash' => str_repeat('0', 64)]))->toThrow(LogicException::class);
});

it('keeps same-team MLB doubleheader evidence scoped to its exact event', function () {
    [$first] = PredictionReviewFixtures::game('mlb');
    [$second] = PredictionReviewFixtures::game('mlb');
    $second->update(['home_team_id' => $first->home_team_id, 'away_team_id' => $first->away_team_id]);
    $source = researchSourceFor($first, 'mlb');
    $body = 'Fixture confirmed lineup excerpt.';
    $claims = [['text' => $body, 'topic' => 'lineup', 'stance' => 'supporting']];
    $one = app(ResearchDocumentStore::class)->store($source, $source->url, $body, $claims, now(), eventId: $first->sport_event_id);
    $missing = app(GameResearchService::class)->assess($second->fresh(), 'mlb');
    expect($missing['supporting'])->toBe([])->and($missing['source_coverage']['lineup']['home'])->toBeFalse();
    $two = app(ResearchDocumentStore::class)->store($source, $source->url, $body, $claims, now(), eventId: $second->sport_event_id);
    $result = app(GameResearchService::class)->assess($second->fresh(), 'mlb');

    expect($two->id)->not->toBe($one->id)->and($result['sources'][0]['document_id'])->toBe($two->id)
        ->and($result['supporting'])->toHaveCount(1);
});

it('preserves recovered evidence as a new immutable state transition', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $source = researchSourceFor($game, 'nba');
    researchDocumentFor($source, 'availability', 'supporting');
    $first = app(GameResearchService::class)->assess($game, 'nba');
    $source->update(['enabled' => false]);
    app(GameResearchService::class)->assess($game, 'nba');
    $source->update(['enabled' => true]);
    $recovered = app(GameResearchService::class)->assess($game, 'nba');

    expect($recovered['evidence_hash'])->toBe($first['evidence_hash'])
        ->and($recovered['report_id'])->not->toBe($first['report_id'])
        ->and(GameResearchAssessment::query()->count())->toBe(3)
        ->and(app(GameResearchService::class)->forGame($game, 'nba')['applicability'])->toBe('current');
});

it('honors independent sport flags and cannot be enabled by force', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    config(['research.sports.nba.enabled' => false]);
    expect(app(GameResearchService::class)->assess($game, 'nba', force: true)['reason_codes'])->toBe(['research_disabled'])
        ->and(GameResearchAssessment::query()->count())->toBe(0);
});

it('uses canonical kickoff when mutable game fields disagree and stops assessments after start', function () {
    [$game, , $native] = PredictionReviewFixtures::game('nba');
    $game->update(['game_date' => now()->subDays(2)->toDateString()]);
    expect(app(GameResearchService::class)->assess($game, 'nba')['status'])->toBe('unavailable')
        ->and(GameResearchAssessment::query()->count())->toBe(1);
    $this->travel(5)->hours();
    expect(app(GameResearchService::class)->assess($game->fresh(), 'nba')['reason_codes'])->toBe(['pregame_research_ineligible']);
});

it('rejects wrong sport identity and cross-sport event document associations', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    [$other] = PredictionReviewFixtures::game('mlb');
    $source = researchSourceFor($game, 'nba');
    expect(fn () => app(GameResearchService::class)->assess($game, 'mlb'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ResearchDocumentStore::class)->store($source, $source->url, 'Fixture', [], now(), eventId: $other->sport_event_id))->toThrow(InvalidArgumentException::class);
});

it('rejects ungrounded claims private URLs and unapproved redirects', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $source = researchSourceFor($game, 'nba');
    $store = app(ResearchDocumentStore::class);
    expect($store->allowed($source, 'https://127.0.0.1/private'))->toBeFalse()
        ->and($store->allowed($source, 'https://evidence.example@evil.example/private'))->toBeFalse()
        ->and(fn () => $store->store($source, $source->url, 'Fixture body', [['text' => 'Invented claim', 'topic' => 'availability']], now()))->toThrow(InvalidArgumentException::class);
    Http::fake(['https://evidence.example/*' => Http::response('', 302, ['Location' => 'https://evil.example/article'])]);
    expect(fn () => $store->fetch($source))->toThrow(InvalidArgumentException::class);
});

it('reads before shared migration without initiating storage or paid work', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    Schema::shouldReceive('hasTable')->once()->with('game_research_assessments')->andReturn(false);
    expect(app(GameResearchService::class)->forGame($game, 'nba')['reason_codes'])->toBe(['research_storage_unavailable']);
    Http::assertNothingSent();
});
