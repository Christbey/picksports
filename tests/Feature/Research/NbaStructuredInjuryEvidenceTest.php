<?php

use App\Actions\ESPN\NBA\SyncPlayerInjuries;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjuryObservation;
use App\Models\NBA\Team;
use App\Models\ResearchDocument;
use App\Models\ResearchSource;
use App\Services\Api\V2\GameResearchPresentation;
use App\Services\ESPN\NBA\EspnService;
use App\Services\NBA\Injuries\InjuryAcquisitionOrder;
use App\Services\NBA\Injuries\InjuryFeedClient;
use App\Services\NBA\Injuries\StructuredInjuryEvidence;
use App\Services\Research\GameResearchService;
use App\Services\Research\ResearchDocumentStore;
use App\Services\Research\StoredGameEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-21 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
    config(['research.enabled' => true, 'research.sports.nba.enabled' => true,
        'nba.injuries.observations_enabled' => true, 'nba.injuries.research_adapter_enabled' => true,
        'espn.cache.enabled' => false]);
    [$this->game, $this->legacy, $this->prediction] = PredictionReviewFixtures::game('nba');
    $this->game->sportEvent->update(['season' => $this->game->season, 'season_type' => $this->game->season_type]);
    Team::query()->whereKey($this->game->home_team_id)->update(['espn_id' => 'canary-home']);
    Team::query()->whereKey($this->game->away_team_id)->update(['espn_id' => 'canary-away']);
    $this->sources = [];
    $this->responses = [];
    foreach (['home' => '23', 'away' => '24'] as $side => $id) {
        $team = Team::query()->findOrFail($this->game->{$side.'_team_id'});
        $team->update(['espn_id' => $id]);
        Player::factory()->create(['team_id' => $team->id, 'espn_id' => $id.'123']);
        $url = app(InjuryFeedClient::class)->teamUrl($id);
        $this->sources[$side] = ResearchSource::query()->create(['sport' => 'nba', 'team_id' => $team->id,
            'source_key' => StructuredInjuryEvidence::sourceKey($id), 'kind' => 'structured_espn',
            'url' => $url, 'allowed_hosts' => ['sports.core.api.espn.com', 'site.api.espn.com'], 'enabled' => true]);
        $this->responses[$url] = ['status' => 200, 'body' => ['count' => 1, 'items' => [['id' => $id.'injury', 'athlete' => ['id' => $id.'123'],
            'status' => 'Day-To-Day', 'date' => '2026-10-21T04:22Z']]]];
    }
    Http::fake(function ($request) {
        $response = $this->responses[$request->url()] ?? ['status' => 503, 'body' => []];

        return Http::response($response['body'], $response['status']);
    });
    foreach (['23', '24'] as $id) {
        (new SyncPlayerInjuries(new EspnService))->execute($id);
    }
});

afterEach(function () {
    $this->travelBack();
    Queue::assertNothingPushed();
});

it('builds grounded structured availability without invented publication or game designations', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $before = Http::recorded()->count();
    $evidence = app(StoredGameEvidence::class)->build($this->game, 'nba');
    expect($evidence['source_coverage']['availability'])->toEqualCanonicalizing(['home' => true, 'away' => true])
        ->and($evidence['source_coverage']['lineup'])->toBe(['home' => false, 'away' => false])
        ->and($evidence['facts'][0]['raw_status'])->toBe('Day-To-Day')
        ->and($evidence['facts'][0]['normalized_status'])->toBe('ambiguous')
        ->and($evidence['facts'][0]['evidence_kind'])->toBe('structured')
        ->and($evidence['facts'][0]['source_scope'])->toBe('team_collection')
        ->and($evidence['sources'][0]['published_at'])->toBeNull()
        ->and(ResearchDocument::query()->count())->toBe(0)
        ->and(Http::recorded()->count())->toBe($before);
    $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query']));
    DB::disableQueryLog();
    expect($writes)->toHaveCount(0);
    $presented = app(GameResearchPresentation::class)->present($evidence, []);
    expect($presented['facts'][0]['source_label'])->toBe('ESPN-reported NBA availability')
        ->and($presented['facts'][0]['json_pointer'])->toBe('/status')
        ->and($presented['sources'][0]['publisher'])->toBe('ESPN');
});

it('assesses stored evidence without changing forecasts approvals or buying reviews', function () {
    $forecast = $this->prediction->fresh()->getAttributes();
    $legacy = $this->legacy->fresh()->getAttributes();
    $count = Http::recorded()->count();
    $this->artisan('nba:refresh-injury-research', ['game' => $this->game->id])->assertSuccessful();
    $research = app(GameResearchService::class)->forGame($this->game, 'nba');
    expect($research['status'])->toBe('partial')->and($research['source_coverage']['availability'])->toEqualCanonicalizing(['home' => true, 'away' => true])
        ->and($this->prediction->fresh()->getAttributes())->toBe($forecast)
        ->and($this->legacy->fresh()->getAttributes())->toBe($legacy)
        ->and(DB::table('analysis_work_items')->count())->toBe(0)
        ->and(Http::recorded()->count())->toBe($count);
});

it('requires explicit source approval and never reuses a revoked source', function () {
    $this->sources['home']->update(['enabled' => false]);
    $evidence = app(StoredGameEvidence::class)->build($this->game, 'nba');
    expect($evidence['source_coverage']['availability']['home'])->toBeFalse()
        ->and(array_column($evidence['unresolved'], 'reason_code'))->toContain('structured_source_not_approved');
});

it('does not mask pending failed partial or stale teams with another teams fresh receipt', function (string $case) {
    $teamId = $this->game->home_team_id;
    if ($case === 'pending') {
        app(InjuryAcquisitionOrder::class)->begin($teamId);
    } elseif ($case === 'stale') {
        $this->travel(121)->minutes();
        $this->responses[app(InjuryFeedClient::class)->teamUrl('24')] = ['status' => 200, 'body' => ['items' => []]];
        (new SyncPlayerInjuries(new EspnService))->execute('24');
    } else {
        $this->responses[app(InjuryFeedClient::class)->teamUrl('23')] = ['status' => $case === 'failed' ? 503 : 200, 'body' => ['count' => 1, 'items' => []]];
        (new SyncPlayerInjuries(new EspnService))->execute('23');
    }
    $evidence = app(StoredGameEvidence::class)->build($this->game, 'nba');
    expect($evidence['source_coverage']['availability'])->toBe(['home' => false, 'away' => true]);
})->with(['pending', 'failed', 'partial', 'stale']);

it('invalidates evidence when a captured player no longer maps to the same team', function () {
    Player::query()->where('espn_id', '23123')->update(['team_id' => $this->game->away_team_id]);
    $evidence = app(StoredGameEvidence::class)->build($this->game, 'nba');
    expect($evidence['source_coverage']['availability']['home'])->toBeFalse()
        ->and(array_column($evidence['unresolved'], 'reason_code'))->toContain('structured_roster_mapping_changed');
});

it('keeps legacy manual documents and flags source reconciliation explicitly', function () {
    $source = ResearchSource::query()->create(['sport' => 'nba', 'team_id' => $this->game->home_team_id, 'source_key' => 'optional-official',
        'kind' => 'official', 'url' => 'https://official.example/report', 'allowed_hosts' => ['official.example'], 'enabled' => true]);
    app(ResearchDocumentStore::class)->store($source, $source->url, 'Player available.',
        [['text' => 'Player available.', 'topic' => 'availability']], now());
    $evidence = app(StoredGameEvidence::class)->build($this->game, 'nba');
    expect(array_column($evidence['unresolved'], 'reason_code'))->toContain('source_reconciliation_required')
        ->and(ResearchDocument::query()->count())->toBe(1);
});

it('leaves the adapter off without source writes or automated dispatch', function () {
    config(['nba.injuries.research_adapter_enabled' => false]);
    expect(app(StoredGameEvidence::class)->build($this->game, 'nba')['facts'])->toBe([]);
    $this->artisan('nba:refresh-injury-research', ['game' => $this->game->id])->assertFailed();
});

it('uses authoritative event identity and rejects cross-sport or mismatched season binding', function () {
    $this->game->sportEvent->update(['sport' => 'nfl']);
    $evidence = app(StoredGameEvidence::class)->build($this->game, 'nba');
    expect($evidence['source_coverage']['availability'])->toBe(['home' => false, 'away' => false])
        ->and(array_column($evidence['unresolved'], 'reason_code'))->toContain('structured_event_identity_invalid');
});

it('keeps successful empty availability neutral rather than claiming every player is healthy', function () {
    $this->responses[app(InjuryFeedClient::class)->teamUrl('23')] = ['status' => 200, 'body' => ['count' => 0, 'items' => []]];
    (new SyncPlayerInjuries(new EspnService))->execute('23');
    $evidence = app(StoredGameEvidence::class)->build($this->game, 'nba');
    expect($evidence['source_coverage']['availability']['home'])->toBeTrue()
        ->and($evidence['facts'][0]['normalized_status'])->toBe('no_injuries_reported')
        ->and($evidence['supporting'])->toBe([]);
});

it('does not renew source validity from repeated cached acquisition', function () {
    config(['espn.cache.enabled' => true]);
    $client = app(InjuryFeedClient::class);
    (new SyncPlayerInjuries(new EspnService))->execute('23');
    $observed = PlayerInjuryObservation::query()->where('team_id', $this->game->home_team_id)->latest('id')->first()->observed_at;
    $this->travel(2)->minutes();
    (new SyncPlayerInjuries(new EspnService))->execute('23');
    $evidence = app(StoredGameEvidence::class)->build($this->game, 'nba');
    expect($evidence['sources'][0]['observed_at'])->toBe($observed->toIso8601String());
});
