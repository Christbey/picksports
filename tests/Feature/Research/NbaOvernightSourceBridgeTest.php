<?php

use App\Actions\ESPN\NBA\SyncPlayerInjuries;
use App\AI\Agents\PredictionReviewAgent;
use App\Jobs\NBA\RefreshOvernightSources;
use App\Models\AiGeneration;
use App\Models\AnalysisWorkItem;
use App\Models\CommandHeartbeat;
use App\Models\GameResearchAssessment;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjuryObservation;
use App\Models\NBA\ResearchContextSnapshot;
use App\Models\NBA\Team;
use App\Models\ResearchSource;
use App\Models\SportEvent;
use App\Services\CommandHeartbeatService;
use App\Services\ESPN\NBA\EspnService;
use App\Services\NBA\Injuries\InjuryAcquisitionOrder;
use App\Services\NBA\Injuries\InjuryFeedClient;
use App\Services\NBA\Injuries\StructuredInjuryEvidence;
use App\Services\NBA\Research\CaptureGameContext;
use App\Services\NBA\Research\OvernightSourceBridge;
use App\Services\Predictions\Review\NbaReviewReuse;
use App\Services\Research\GameResearchService;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures;

function fakeNbaBridgeResponses(callable $callback): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake($callback);
}

beforeEach(function () {
    $this->travelTo('2026-11-22 00:12:00 America/Chicago');
    config(['nba.source_bridge.enabled' => true, 'nba.injuries.observations_enabled' => true,
        'nba.injuries.research_adapter_enabled' => true, 'research.enabled' => true, 'research.sports.nba.enabled' => true,
        'espn.cache.enabled' => false, 'prediction_review.enabled' => true, 'prediction_review.sports.nba' => true,
        'game_analysis.schedule_enabled' => false, 'game_analysis.publisher_enabled' => false]);
    Http::preventStrayRequests();
    Queue::fake();
    PredictionReviewAgent::fake([])->preventStrayPrompts();
    [$this->game, , $this->prediction] = PredictionReviewFixtures::game('nba');
    $this->game->sportEvent->update(['season' => $this->game->season, 'season_type' => $this->game->season_type]);
    $this->game->update(['venue_city' => 'Target City']);
    $this->ids = [$this->game->home_team_id, $this->game->away_team_id];
    sort($this->ids);
    config(['nba.injuries.canary_team_ids' => $this->ids, 'nba.injuries.canary_expires_at' => now()->addHours(8)->toIso8601String()]);
    $this->responses = [CaptureGameContext::summaryUrl($this->game) => ['header' => []]];
    $this->sources = [];
    foreach ($this->ids as $id) {
        $team = Team::findOrFail($id);
        $url = app(InjuryFeedClient::class)->teamUrl((string) $team->espn_id);
        $this->responses[$url] = ['count' => 0, 'pageCount' => 1, 'items' => []];
        $this->sources[$id] = ResearchSource::create(['sport' => 'nba', 'team_id' => $id, 'kind' => 'structured_espn',
            'source_key' => StructuredInjuryEvidence::sourceKey((string) $team->espn_id), 'url' => $url,
            'allowed_hosts' => ['site.api.espn.com', 'sports.core.api.espn.com'], 'enabled' => true]);
        $players = collect(range(1, 5))->map(fn ($i) => Player::factory()->create(['team_id' => $id, 'espn_id' => $id.'000'.$i]));
        $positions = [];
        foreach ($players as $i => $player) {
            $positions['slot'.$i] = ['athletes' => [['rank' => 1, 'athlete' => ['id' => $player->espn_id]]]];
        }
        $this->responses['https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/'.$this->game->season.'/teams/'.$team->espn_id.'/depthcharts'] = ['count' => 1, 'items' => [['positions' => $positions]]];
        foreach ([1, 3, 5] as $days) {
            $prior = Game::factory()->create(['season' => $this->game->season, 'season_type' => $this->game->season_type,
                'home_team_id' => $id, 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'period' => 4,
                'game_date' => now()->subDays($days)->utc()->toDateString(), 'game_time' => '01:00:00', 'venue_city' => 'Prior City']);
            $competitors = Team::whereIn('id', [$prior->home_team_id, $prior->away_team_id])->get()->map(fn ($t) => ['team' => ['id' => $t->espn_id]])->all();
            $start = app(SportsDateWindowService::class)->gameDateTimeUtc($prior->game_date, $prior->game_time);
            $this->responses[CaptureGameContext::summaryUrl($prior)] = ['header' => ['id' => $prior->espn_event_id,
                'competitions' => [['date' => $start->toIso8601String(), 'competitors' => $competitors, 'status' => ['type' => ['name' => 'STATUS_FINAL']]]]],
                'boxscore' => ['players' => [['team' => ['id' => $team->espn_id], 'statistics' => [['labels' => ['MIN'],
                    'athletes' => $players->map(fn ($p) => ['athlete' => ['id' => $p->espn_id], 'stats' => ['48'], 'didNotPlay' => false])->all()]]]]]];
        }
    }
    $this->contextSource = ResearchSource::create(['sport' => 'nba', 'kind' => 'structured_espn', 'team_id' => null,
        'source_key' => CaptureGameContext::sourceKey($this->game), 'url' => CaptureGameContext::summaryUrl($this->game),
        'allowed_hosts' => ['site.api.espn.com', 'sports.core.api.espn.com'], 'enabled' => true]);
    fakeNbaBridgeResponses(fn ($request) => Http::response($this->responses[$request->url()] ?? [], isset($this->responses[$request->url()]) ? 200 : 503));
});

afterEach(function () {
    $this->travelBack();
    expect(AiGeneration::query()->count())->toBe(0)->and(AnalysisWorkItem::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does nothing while disabled and offers a read-only dry run', function () {
    config(['nba.source_bridge.enabled' => false]);
    $this->artisan('nba:refresh-overnight-sources')->assertSuccessful()->expectsOutputToContain('bridge_disabled');
    expect(CommandHeartbeat::count())->toBe(0);
    config(['nba.source_bridge.enabled' => true]);
    $this->artisan('nba:refresh-overnight-sources', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('ready');
    expect(PlayerInjuryObservation::count())->toBe(0)->and(GameResearchAssessment::count())->toBe(0)->and(CommandHeartbeat::count())->toBe(0);
    Http::assertNothingSent();
});

it('acquires and assesses sources only, retains original content identity, and deduplicates a completed slot', function () {
    $before = $this->prediction->getRawOriginal();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $bridge = app(OvernightSourceBridge::class);
    $first = $bridge->run();
    expect($first['status'])->toBe('success')->and($first['reason'])->toBe('sources_acquired_and_assessed')
        ->and($first['fetches_used'])->toBe(11)->and($first['teams'])->toHaveCount(2)->and($first['games'])->toHaveCount(1)
        ->and($first['games'][0]['status'])->toBe('partial')->and($first['games'][0]['verified'])->toBeTrue();
    $evidence = app(GameResearchService::class)->forGame($this->game, 'nba');
    $repeat = $bridge->run();
    expect($repeat['reason'])->toBe('slot_already_completed')->and(PlayerInjuryObservation::count())->toBe(2);
    $this->travel(30)->minutes();
    $next = $bridge->run();
    $nextEvidence = app(GameResearchService::class)->forGame($this->game, 'nba');
    expect($next['status'])->toBe('success')->and(ResearchContextSnapshot::count())->toBe(2)
        ->and(PlayerInjuryObservation::count())->toBe(4)->and(GameResearchAssessment::count())->toBe(2)
        ->and(app(NbaReviewReuse::class)->materialHash($nextEvidence))->toBe(app(NbaReviewReuse::class)->materialHash($evidence))
        ->and($this->prediction->fresh()->getRawOriginal())->toBe($before);
    expect(collect(DB::getQueryLog())->contains(fn ($q) => preg_match('/^\s*(insert|update|delete|replace).*\b(nfl_|predictions\b|bet_decisions\b|nba_player_stats\b)/i', $q['query'])))->toBeFalse();
    DB::disableQueryLog();
    Http::assertSentCount(22);
});

it('records completed team health distinctly when no game is within the horizon', function () {
    $this->game->sportEvent->update(['starts_at' => now()->addHours(9)]);
    $this->artisan('nba:refresh-overnight-sources')->assertSuccessful()->expectsOutputToContain('team_health_only_no_upcoming_game');
    $health = CommandHeartbeat::where('source', 'nba_source_bridge')->sole();
    expect($health->metadata['teams'])->toHaveCount(2)->and($health->metadata['games'])->toBe([])
        ->and(ResearchContextSnapshot::count())->toBe(0)->and(GameResearchAssessment::count())->toBe(0);
    Http::assertSentCount(2);
});

it('refuses expired scope or revoked approval before acquisition', function (string $failure) {
    match ($failure) {
        'expiry' => config(['nba.injuries.canary_expires_at' => now()->subSecond()->toIso8601String()]),
        'source' => $this->sources[$this->ids[0]]->update(['enabled' => false]),
        'context' => $this->contextSource->update(['enabled' => false]),
        'capability' => config(['nba.injuries.observations_enabled' => false]),
        'duplicate scope' => config(['nba.injuries.canary_team_ids' => [$this->ids[0], $this->ids[0]]]),
    };
    $this->artisan('nba:refresh-overnight-sources')->assertFailed();
    expect(CommandHeartbeat::sole()->status)->toBe('failure')->and(PlayerInjuryObservation::count())->toBe(0);
    Http::assertNothingSent();
})->with(['expiry', 'source', 'context', 'capability', 'duplicate scope']);

it('does not assess an incomplete team collection and permits a later explicit retry', function () {
    fakeNbaBridgeResponses(fn () => Http::response([], 503));
    $bridge = app(OvernightSourceBridge::class);
    $result = $bridge->run();
    expect($result['status'])->toBe('failed')->and($result['reason'])->toBe('team_observation_unverified')
        ->and(GameResearchAssessment::count())->toBe(0)->and(ResearchContextSnapshot::count())->toBe(0);
    fakeNbaBridgeResponses(fn ($request) => Http::response($this->responses[$request->url()] ?? [], isset($this->responses[$request->url()]) ? 200 : 503));
    expect($bridge->run()['status'])->toBe('success');
});

it('refuses overlapping work without acquisition', function () {
    $lock = Cache::lock('nba:overnight-source-bridge', 660);
    $lock->get();
    try {
        expect(app(OvernightSourceBridge::class)->run()['reason'])->toBe('bridge_busy');
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('does not report a revoked or overtaken collection as verified', function (string $failure) {
    $once = false;
    fakeNbaBridgeResponses(function ($request) use ($failure, &$once) {
        if (! $once) {
            $once = true;
            match ($failure) {
                'revoke' => $this->sources[$this->ids[0]]->update(['enabled' => false]),
                'overtake' => app(InjuryAcquisitionOrder::class)->begin($this->ids[0]),
                'expire' => $this->travel(9)->hours(),
            };
        }

        return Http::response($this->responses[$request->url()] ?? []);
    });
    expect(app(OvernightSourceBridge::class)->run()['status'])->toBe('failed')
        ->and(GameResearchAssessment::count())->toBe(0)->and(ResearchContextSnapshot::count())->toBe(0);
})->with(['revoke', 'overtake', 'expire']);

it('caps acquisition reference expansion across the whole run', function () {
    $url = app(InjuryFeedClient::class)->teamUrl((string) Team::findOrFail($this->ids[0])->espn_id);
    $refs = array_map(fn ($i) => ['$ref' => 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/injuries/'.$i], range(1, 30));
    fakeNbaBridgeResponses(fn ($request) => Http::response($request->url() === $url ? ['count' => 30, 'pageCount' => 1, 'items' => $refs]
        : ['id' => 'ref', 'athlete' => ['id' => 'unknown'], 'status' => 'Out']));
    $result = app(OvernightSourceBridge::class)->run();
    expect($result['status'])->toBe('failed')->and($result['fetches_used'])->toBe(24)->and(GameResearchAssessment::count())->toBe(0);
    Http::assertSentCount(24);
});

it('retains an incomplete context packet without claiming assessment success', function (bool $transportFailure) {
    $depth = collect(array_keys($this->responses))->first(fn ($url) => str_contains($url, '/depthcharts'));
    if ($transportFailure) {
        unset($this->responses[$depth]);
    } else {
        $this->responses[$depth] = ['unexpected_shape' => true];
    }
    $result = app(OvernightSourceBridge::class)->run();
    expect($result['status'])->toBe('failed')->and(ResearchContextSnapshot::count())->toBe(1)
        ->and($result['reason'])->toBe($transportFailure ? 'context_acquisition_incomplete' : 'assessment_coverage_incomplete');
})->with([true, false]);

it('rejects multiple admitted games instead of silently assessing only one', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba', 'starts_at' => now()->addHours(6)]);
    Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $this->game->home_team_id,
        'away_team_id' => $this->game->away_team_id, 'status' => 'STATUS_SCHEDULED']);
    expect(app(OvernightSourceBridge::class)->run()['reason'])->toBe('game_limit_exceeded');
    Http::assertNothingSent();
});

it('refuses stale cached collections without refreshing their source timestamp', function () {
    config(['espn.cache.enabled' => true]);
    $team = Team::findOrFail($this->ids[0]);
    $url = app(InjuryFeedClient::class)->teamUrl((string) $team->espn_id);
    Cache::put('nba:injury-feed:v1:'.hash('sha256', $url), ['payload' => ['count' => 0, 'items' => []],
        'fetched_at' => now()->subHours(3)->toIso8601String(), 'http_status' => 200, 'error_code' => null], now()->addHour());
    $result = app(OvernightSourceBridge::class)->run();
    expect($result['status'])->toBe('failed')->and($result['teams'][0]['verified'])->toBeFalse()
        ->and(PlayerInjuryObservation::sole()->observed_at->timestamp)->toBe(now()->subHours(3)->timestamp);
    Http::assertNothingSent();
});

it('does not fetch a fallback from an unapproved host under another sources authority', function () {
    $team = Team::findOrFail($this->ids[0]);
    $this->sources[$team->id]->update(['allowed_hosts' => ['sports.core.api.espn.com']]);
    fakeNbaBridgeResponses(fn () => Http::response([], 503));
    expect(app(OvernightSourceBridge::class)->run()['status'])->toBe('failed');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'site.api.espn.com'));
});

it('blocks further context requests when its approval is revoked during capture', function () {
    fakeNbaBridgeResponses(function ($request) {
        if ($request->url() === CaptureGameContext::summaryUrl($this->game)) {
            $this->contextSource->update(['enabled' => false]);
        }

        return Http::response($this->responses[$request->url()] ?? []);
    });
    expect(app(OvernightSourceBridge::class)->run()['reason'])->toBe('context_acquisition_incomplete')
        ->and(GameResearchAssessment::count())->toBe(0);
    Http::assertSentCount(3);
});

it('does not let the slot marker suppress assessment after the current head changes', function () {
    $bridge = app(OvernightSourceBridge::class);
    expect($bridge->run()['status'])->toBe('success');
    $team = Team::findOrFail($this->ids[0]);
    (new SyncPlayerInjuries(new EspnService))->execute((string) $team->espn_id);
    expect($bridge->run()['status'])->toBe('success')->and(GameResearchAssessment::count())->toBe(2);
});

it('retains the existing approved HTTP-to-HTTPS ESPN reference upgrade', function () {
    $team = Team::findOrFail($this->ids[0]);
    $player = Player::where('team_id', $team->id)->firstOrFail();
    $url = app(InjuryFeedClient::class)->teamUrl((string) $team->espn_id);
    $ref = 'http://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/injuries/bridge-reference';
    $this->responses[$url] = ['count' => 1, 'items' => [['$ref' => $ref]]];
    $this->responses[str_replace('http:', 'https:', $ref)] = ['id' => 'bridge-reference', 'athlete' => ['id' => $player->espn_id], 'status' => 'Out'];
    expect(app(OvernightSourceBridge::class)->run()['status'])->toBe('success');
    Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'http:'));
});

it('never falls back to legacy acquisition when strict capture admission is absent', function () {
    config(['nba.injuries.observations_enabled' => false]);
    $team = Team::findOrFail($this->ids[0]);
    $action = new SyncPlayerInjuries(new EspnService);
    expect(fn () => $action->execute((string) $team->espn_id, requireCapture: true))->toThrow(RuntimeException::class);
    config(['nba.injuries.observations_enabled' => true, 'nba.injuries.canary_expires_at' => now()->subSecond()->toIso8601String()]);
    expect(fn () => $action->execute((string) $team->espn_id, requireCapture: true))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('uses distinct UTC run slots for both instances of the fall DST hour', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01T01:12:00-05:00'));
    config(['nba.injuries.canary_expires_at' => now()->addHours(4)->toIso8601String()]);
    $this->game->sportEvent->update(['starts_at' => now()->addHours(20)]);
    $bridge = app(OvernightSourceBridge::class);
    $first = $bridge->run();
    $this->travelTo(CarbonImmutable::parse('2026-11-01T01:12:00-06:00'));
    $second = $bridge->run();
    expect($first['status'])->toBe('success', json_encode($first));
    expect($second['status'])->toBe('success', json_encode($second))
        ->and($first['slot_utc'])->not->toBe($second['slot_utc'])->and(PlayerInjuryObservation::count())->toBe(4);
    Http::assertSentCount(4);
});

it('records dedicated worker completion separately from verified source results', function () {
    $worker = new RefreshOvernightSources;
    $worker->handle(app(CommandHeartbeatService::class));
    $source = CommandHeartbeat::where('source', 'nba_source_bridge')->sole();
    $completion = CommandHeartbeat::where('source', 'nba_source_worker')->sole();
    expect($source->status)->toBe('success')->and($source->metadata['games'][0]['verified'])->toBeTrue()
        ->and($completion->metadata['queue'])->toBe('nba-sources')->and($completion->metadata['acquisition_result_source'])->toBe('nba_source_bridge');
});

it('fails the dedicated worker when source acquisition fails without a retry purchase', function () {
    fakeNbaBridgeResponses(fn () => Http::response([], 503));
    $worker = new RefreshOvernightSources;
    expect(fn () => $worker->handle(app(CommandHeartbeatService::class)))->toThrow(RuntimeException::class);
    $worker->failed(new RuntimeException('simulated terminal failure'));
    expect(CommandHeartbeat::where('status', 'failure')->count())->toBe(2)->and(GameResearchAssessment::count())->toBe(0);
});
