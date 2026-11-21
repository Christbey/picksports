<?php

use App\Models\ResearchDocument;
use App\Models\ResearchSource;
use App\Services\Research\GameResearchService;
use App\Services\Research\ResearchDocumentStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    config(['research.enabled' => true, 'research.sports.nba.enabled' => true]);
});

afterEach(fn () => $this->travelBack());

function nbaPilotSource(?int $teamId = null): ResearchSource
{
    return ResearchSource::query()->create([
        'sport' => 'nba', 'source_key' => 'pilot-official', 'team_id' => $teamId,
        'kind' => 'official', 'url' => 'https://evidence.example/report',
        'allowed_hosts' => ['evidence.example'], 'enabled' => true,
    ]);
}

it('keeps event-only NBA excerpts descriptive without claiming either team is covered', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $source = nbaPilotSource();
    app(ResearchDocumentStore::class)->store($source, $source->url, 'Fixture availability excerpt.', [
        ['text' => 'Fixture availability excerpt.', 'topic' => 'availability'],
    ], now()->subMinutes(5), now()->subMinutes(10), $game->sport_event_id);

    $assessment = app(GameResearchService::class)->assess($game, 'nba');
    expect($assessment['status'])->toBe('partial')
        ->and($assessment['facts'][0]['team_side'])->toBe('game')
        ->and($assessment['source_coverage']['availability'])->toBe(['home' => false, 'away' => false]);
    Http::assertNothingSent();
});

it('rejects a team-specific document for an unrelated NBA event before storing evidence', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    [$other] = PredictionReviewFixtures::game('nba');
    $source = nbaPilotSource($game->home_team_id);

    expect(fn () => app(ResearchDocumentStore::class)->store($source, $source->url, 'Fixture excerpt.', [
        ['text' => 'Fixture excerpt.', 'topic' => 'availability'],
    ], now(), eventId: $other->sport_event_id))->toThrow(InvalidArgumentException::class);
    expect(ResearchDocument::query()->count())->toBe(0);
});

it('does not treat recently observed old NBA publications as fresh source coverage', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $source = nbaPilotSource($game->home_team_id);
    app(ResearchDocumentStore::class)->store($source, $source->url, 'Fixture old report.', [
        ['text' => 'Fixture old report.', 'topic' => 'availability'],
    ], now()->subMinutes(5), now()->subMinutes(121), $game->sport_event_id);

    $assessment = app(GameResearchService::class)->assess($game, 'nba');
    expect($assessment['sources'])->toBe([])->and($assessment['status'])->toBe('unavailable')
        ->and($assessment['source_coverage']['availability'])->toBe(['home' => false, 'away' => false]);
});

it('expires NBA evidence at the earlier publication freshness boundary', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $source = nbaPilotSource($game->home_team_id);
    app(ResearchDocumentStore::class)->store($source, $source->url, 'Fixture dated report.', [
        ['text' => 'Fixture dated report.', 'topic' => 'availability'],
    ], now()->subMinutes(5), now()->subMinutes(119), $game->sport_event_id);

    $service = app(GameResearchService::class);
    $assessment = $service->assess($game, 'nba');
    expect($assessment['source_coverage']['availability']['home'])->toBeTrue()
        ->and(CarbonImmutable::parse($assessment['expires_at'])->timestamp)->toBe(now()->addMinute()->timestamp);
    $this->travel(61)->seconds();
    expect($service->forGame($game, 'nba')['applicability'])->toBe('expired');
});

it('marks disabled-source evidence outdated and excludes it on reassessment without fetching', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $source = nbaPilotSource($game->home_team_id);
    app(ResearchDocumentStore::class)->store($source, $source->url, 'Fixture report.', [
        ['text' => 'Fixture report.', 'topic' => 'availability'],
    ], now()->subMinutes(5), now()->subMinutes(10), $game->sport_event_id);
    $service = app(GameResearchService::class);
    $service->assess($game, 'nba');
    $source->update(['enabled' => false]);

    expect($service->forGame($game->fresh(), 'nba')['applicability'])->toBe('outdated');
    $assessment = $service->assess($game->fresh(), 'nba');
    expect($assessment['sources'])->toBe([])->and($assessment['status'])->toBe('unavailable');
    Http::assertNothingSent();
});
