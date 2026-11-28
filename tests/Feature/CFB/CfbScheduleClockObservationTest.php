<?php

use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Actions\ESPN\CFB\SyncGamesFromSchedule;
use App\Actions\ESPN\CFB\SyncGamesFromScoreboard;
use App\Actions\OddsApi\CFB\SyncPlayerPropsForGames;
use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\User;
use App\Services\BettingRecommendations\CfbPropCalendarRead;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\CFB\Live\LivePropProjector;
use App\Services\CFB\Live\LiveSnapshotRecorder;
use App\Services\ESPN\CFB\EspnService;
use App\Services\OddsApi\OddsApiService;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\CFB\CfbGameStart;
use App\Support\Sports\CanonicalEventStart;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('does not publish a known instant from explicitly unconfirmed provider schedule time', function (string $kind) {
    Http::preventStrayRequests();
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $raw = ['id' => '900001', 'date' => '2027-01-13T04:00:00Z', 'season' => ['year' => 2026, 'type' => 3], 'week' => ['number' => 1],
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
        'competitions' => [['date' => '2027-01-13T04:00:00Z', 'dateValid' => true, 'timeValid' => true,
            'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
            'competitors' => [['homeAway' => 'home', 'team' => ['id' => $home->espn_id]], ['homeAway' => 'away', 'team' => ['id' => $away->espn_id]]]]]];
    if ($kind === 'time_invalid') {
        data_set($raw, 'competitions.0.timeValid', false);
    } elseif ($kind === 'tbd') {
        data_set($raw, 'competitions.0.status.type.detail', '1/12 - TBD');
    } elseif ($kind === 'date_only') {
        $raw['date'] = $raw['competitions'][0]['date'] = '2027-01-13';
    } else {
        $raw['date'] = $raw['competitions'][0]['date'] = '2027-01-13T00:00:00Z';
    }
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getSchedule')->once()->with((string) $home->espn_id, 2026)->andReturn(['events' => [$raw]]);
    expect((new SyncGamesFromSchedule($service))->execute((string) $home->espn_id, 2026))->toBe(1);
    $game = Game::where('espn_event_id', '900001')->firstOrFail();
    expect($game->game_date->toDateString())->toBe('2027-01-13');
    expect(GameDateTimePresenter::currentStoredStartsAt($game))->toBe($kind === 'known_midnight' ? '2027-01-13T00:00:00+00:00' : null);
    Http::assertNothingSent();
})->with(['time_invalid', 'tbd', 'date_only', 'known_midnight']);

it('downgrades a same event explicitly unconfirmed summary without erasing its stored carriers', function (string $kind) {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['espn_event_id' => '900002', 'season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => '2027-01-13', 'game_time' => '00:00:00', 'status' => 'STATUS_SCHEDULED', 'venue_name' => 'Keep venue']);
    $payload = ['header' => ['id' => '900002', 'season' => ['year' => 2026], 'competitions' => [[
        'date' => '2027-01-13T00:00:00Z', 'timeValid' => true, 'dateValid' => true,
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']], 'competitors' => [
            ['homeAway' => 'home', 'team' => ['id' => $home->espn_id]], ['homeAway' => 'away', 'team' => ['id' => $away->espn_id]],
        ],
    ]]]];
    $unconfirmed = $payload;
    data_set($unconfirmed, $kind === 'time_invalid' ? 'header.competitions.0.timeValid' : 'header.competitions.0.status.type.detail', $kind === 'time_invalid' ? false : '1/12 - TBD');
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->times(3)->andReturn($payload, $unconfirmed, $payload);
    $sync = new SyncGameMetadata($service);
    expect($sync->execute($game))->toBeTrue();
    $known = $game->fresh();
    expect(GameDateTimePresenter::currentStoredStartsAt($known))->toBe('2027-01-13T00:00:00+00:00');
    expect(fn () => $sync->execute($known))->toThrow(RuntimeException::class);
    $unknown = $game->fresh();
    expect(GameDateTimePresenter::currentStoredStartsAt($unknown))->toBeNull()
        ->and($unknown->game_date->toDateString())->toBe('2027-01-13')->and($unknown->game_time)->toBe('00:00:00')
        ->and($unknown->venue_name)->toBe('Keep venue')->and($unknown->sportEvent->starts_at)->toBeNull();
    expect(CanonicalEventStart::forCurrentCfbEvent($known->sportEvent))->toBeNull();
    expect($sync->execute($unknown))->toBeTrue()
        ->and(GameDateTimePresenter::currentStoredStartsAt($game->fresh()))->toBe('2027-01-13T00:00:00+00:00');
})->with(['time_invalid', 'tbd']);

it('requires bounded current schema and matching carriers rather than inferring known from absence', function (string $kind) {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'schema-clock', 'game_date' => '2027-01-13', 'game_time' => '00:00:00']);
    $observation = CfbGameStart::observation('2027-01-13T00:00:00Z', [], 'espn_schedule', 'schema-clock');
    if ($kind !== 'absent') {
        $game->forceFill(['kickoff_observation' => $observation]);
    }
    if ($kind === 'changed_clock') {
        $game->game_time = '01:00:00';
    } elseif ($kind === 'oversized') {
        $game->forceFill(['kickoff_observation' => [...$observation, 'raw_date' => str_repeat('x', 2048)]]);
    } elseif ($kind === 'wrong_owner') {
        $game->espn_event_id = 'different';
    } elseif ($kind === 'wrong_source') {
        $game->forceFill(['kickoff_observation' => [...$observation, 'source' => 'guessed']]);
    } elseif ($kind === 'partial') {
        unset($observation['time_valid']);
        $game->forceFill(['kickoff_observation' => $observation]);
    }
    expect(CfbGameStart::for($game)?->toIso8601String())
        ->toBe($kind === 'known_midnight' ? '2027-01-13T00:00:00+00:00' : null);
    if ($kind === 'absent') {
        expect($game->getRawOriginal('kickoff_observation'))->toBeNull()
            ->and(CfbGameStart::status($game))->toBe('legacy_clock_unverified');
    }
})->with(['absent', 'known_midnight', 'changed_clock', 'oversized', 'wrong_owner', 'wrong_source', 'partial']);

it('rechecks the current confirmed kickoff after synthetic provider IO without retiring existing quotes', function () {
    $this->travelTo(Carbon\Carbon::parse('2027-01-12T18:00:00Z'));
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'after-io', 'odds_api_event_id' => 'after-io', 'season' => 2026, 'status' => 'STATUS_SCHEDULED',
        ...CfbGameStart::attributes('2027-01-13T00:00:00Z', [], 'espn_schedule', 'after-io')]);
    $prop = PlayerProp::create(['game_id' => $game->id, 'player_name' => 'Preserved', 'market' => 'player_pass_yds', 'line' => 0,
        'is_current' => true, 'fetched_at' => now()]);
    $before = $prop->fresh()->getRawOriginal();
    $service = Mockery::mock(OddsApiService::class);
    $reached = false;
    $service->shouldReceive('getPlayerProps')->once()->andReturnUsing(function () use ($game, &$reached) {
        $reached = true;
        $game->forceFill(['kickoff_observation' => CfbGameStart::observation('2027-01-13T00:00:00Z', ['timeValid' => false], 'espn_schedule', 'after-io')])->saveQuietly();

        return ['id' => 'after-io', 'commence_time' => '2027-01-13T00:00:00Z', 'bookmakers' => []];
    });
    $result = (new SyncPlayerPropsForGames($service))->execute('2027-01-12', $game->id, false);
    expect($reached)->toBeTrue()->and($result['stored'])->toBe(0)->and($prop->fresh()->getRawOriginal())->toBe($before);
    $service = Mockery::mock(OddsApiService::class);
    $service->shouldNotReceive('getPlayerProps');
    expect((new SyncPlayerPropsForGames($service))->execute('2027-01-13', $game->id, false)['games'])->toBe(0);
});

it('refuses legacy unverified eligibility without losing its stored quote or actual statistics', function () {
    $this->travelTo(Carbon\Carbon::parse('2027-01-12T18:00:00Z'));
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => '2027-01-13',
        'game_time' => '00:00:00', 'status' => 'STATUS_SCHEDULED']);
    $player = Player::factory()->create(['team_id' => $home->id, 'espn_id' => 'known-stat-player', 'full_name' => 'Known stat player']);
    $prop = PlayerProp::create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => $player->full_name,
        'market' => 'player_pass_yds', 'line' => 0, 'is_current' => true, 'over_price' => -110, 'under_price' => -110, 'fetched_at' => now()]);
    $stat = PlayerStat::factory()->create(['game_id' => $game->id, 'player_id' => $player->id, 'team_id' => $home->id, 'passing_yards' => 0]);
    $beforeProp = $prop->fresh()->getRawOriginal();
    $beforeStat = $stat->fresh()->getRawOriginal();
    expect(CfbPropEligibility::eligible($prop->fresh()))->toBeFalse();
    $rows = app(LivePropProjector::class)->project($game, ['seconds_remaining' => 1800], null, true);
    expect($rows)->toHaveCount(1)->and($rows[0]['actual'])->toBe(0.0)
        ->and($rows[0]['pregame_line'])->toBeNull()->and($rows[0]['projected'])->toBeNull()->and($rows[0]['status'])->toBe('kickoff_unknown')
        ->and($prop->fresh()->getRawOriginal())->toBe($beforeProp)->and($stat->fresh()->getRawOriginal())->toBe($beforeStat);
});

it('retains unverified raw date inventory and labels while known midnight belongs to its Central day', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $raw = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => '2027-01-13', 'game_time' => '00:00:00']);
    $known = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'espn_event_id' => 'known-calendar',
        ...CfbGameStart::attributes('2027-01-13T00:00:00Z', [], 'espn_schedule', 'known-calendar')]);
    foreach ([$raw, $known] as $game) {
        PlayerProp::create(['game_id' => $game->id, 'player_name' => 'Calendar player', 'market' => 'player_pass_yds', 'line' => 0, 'fetched_at' => now()]);
    }
    $eligibility = CfbPropEligibility::class;
    expect($eligibility::onDate(Game::query(), '2027-01-13')->pluck('id')->all())->toBe([$raw->id])
        ->and($eligibility::onDate(Game::query(), '2027-01-12')->pluck('id')->all())->toBe([$known->id]);
    $analyzer = app(PlayerPropAnalyzer::class);
    $games = $analyzer->getAvailableGamesForSport('CFB', '2027-01-13');
    expect($games->pluck('id')->all())->toBe([$raw->id])->and($games->first()['label'])->toContain('Time unverified')
        ->and($games->first()['date'])->toBe('2027-01-13')->and($games->first()['kickoff_status'])->toBe('legacy_clock_unverified');
    $dates = $analyzer->getAvailableDatesForSport('CFB')->keyBy('value');
    expect($dates['2027-01-13']['label'])->toStartWith('Unverified date:')
        ->and($dates->keys()->all())->toBe(['2027-01-12', '2027-01-13']);
});

it('does not reuse a cached event instant or frozen live baseline after the current clock becomes unconfirmed', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'baseline-clock', 'status' => 'STATUS_IN_PROGRESS',
        ...CfbGameStart::attributes('2027-01-13T00:00:00Z', [], 'espn_schedule', 'baseline-clock')]);
    app(SportEventIdentitySynchronizer::class)->sync('cfb', $game);
    $cachedEvent = $game->fresh()->sportEvent;
    LivePredictionSnapshot::create(['game_id' => $game->id, 'source' => 'live_feed', 'state_hash' => hash('sha256', 'frozen-baseline'),
        'pregame' => ['baseline_contract' => 'canonical-or-closing-v2', 'source' => 'canonical_pregame', 'spread' => 0, 'total' => 0],
        'state' => [], 'projection' => null, 'markets' => [], 'props' => [], 'status' => 'live', 'observed_at' => now()]);
    $history = LivePredictionSnapshot::first()->getRawOriginal();
    $game->forceFill(['kickoff_observation' => CfbGameStart::observation('2027-01-13T00:00:00Z', ['timeValid' => false], 'espn_scoreboard', 'baseline-clock')])->saveQuietly();
    expect(CanonicalEventStart::forCurrentCfbEvent($cachedEvent))->toBeNull();
    $baseline = app(LiveSnapshotRecorder::class)->baseline($game->fresh());
    expect($baseline['source'])->toBe('unavailable')->and($baseline['total'])->toBeNull()
        ->and(LivePredictionSnapshot::first()->getRawOriginal())->toBe($history);
});

it('serializes explicit clock status and nullable instant through actual game endpoint without losing zero scores', function (bool $known) {
    Http::preventStrayRequests();
    Sanctum::actingAs(User::factory()->admin()->create());
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'api-clock', 'game_date' => '2027-01-13', 'game_time' => '00:00:00', 'home_score' => 0, 'away_score' => 0,
        ...($known ? CfbGameStart::attributes('2027-01-13T00:00:00Z', [], 'espn_schedule', 'api-clock') : [])]);
    $this->getJson('/api/v2/sports/cfb/games/'.$game->id)->assertOk()
        ->assertJsonPath('data.starts_at', $known ? '2027-01-13T00:00:00+00:00' : null)
        ->assertJsonPath('data.start_time_provenance', $known ? 'provider_clock_known_v1' : 'legacy_clock_unverified')
        ->assertJsonPath('data.home_score', 0)->assertJsonPath('data.away_score', 0);
    $this->getJson('/api/v2/sports/cfb/games')->assertOk()
        ->assertJsonPath('data.0.start_time_provenance', $known ? 'provider_clock_known_v1' : 'legacy_clock_unverified');
    Http::assertNothingSent();
})->with([false, true]);

it('publishes the three CFB clock statuses in the existing bounded OpenAPI provenance enum', function () {
    Artisan::call('api:v2-openapi-generate', ['--stdout' => true]);
    $spec = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $enums = collect($spec['components']['schemas'])->filter(fn ($schema) => isset($schema['properties']['start_time_provenance']))
        ->pluck('properties.start_time_provenance.enum');
    expect($enums)->not->toBeEmpty();
    foreach ($enums as $enum) {
        expect($enum)->toContain('provider_clock_known_v1', 'legacy_clock_unverified', 'clock_unconfirmed_or_invalid', null);
    }
});

it('records scoreboard current observations and preserves prior carriers when a same event becomes TBD', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $payload = ['id' => 'scoreboard-clock', 'date' => '2027-01-13T00:00:00Z', 'season' => ['year' => 2026, 'type' => 3], 'week' => ['number' => 1],
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']], 'competitions' => [['date' => '2027-01-13T00:00:00Z', 'timeValid' => true,
            'competitors' => [['homeAway' => 'home', 'team' => ['id' => $home->espn_id]], ['homeAway' => 'away', 'team' => ['id' => $away->espn_id]]]]]];
    $unknown = $payload;
    $unknown['date'] = '2027-01-13T04:00:00Z';
    data_set($unknown, 'status.type.detail', 'TBD');
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getScoreboard')->times(3)->andReturn(['events' => [$payload]], ['events' => [$unknown]], ['events' => [$payload]]);
    $action = new SyncGamesFromScoreboard($service, new class
    {
        public function execute(Game $game): void {}
    });
    expect($action->execute('20270112'))->toBe(1);
    $game = Game::where('espn_event_id', 'scoreboard-clock')->firstOrFail();
    expect(GameDateTimePresenter::currentStoredStartsAt($game))->toBe('2027-01-13T00:00:00+00:00');
    expect($action->execute('20270112'))->toBe(1);
    $current = $game->fresh();
    expect(GameDateTimePresenter::currentStoredStartsAt($current))->toBeNull()
        ->and($current->game_time)->toBe('00:00:00')->and($current->sportEvent->starts_at)->toBeNull();
    expect($action->execute('20270112'))->toBe(1)
        ->and(GameDateTimePresenter::currentStoredStartsAt($game->fresh()))->toBe('2027-01-13T00:00:00+00:00');
});

it('keeps current observed evidence after access or wrong identity summary failure', function (string $kind) {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'refused-summary', 'season' => 2026, 'status' => 'STATUS_SCHEDULED',
        ...CfbGameStart::attributes('2027-01-13T00:00:00Z', [], 'espn_schedule', 'refused-summary')]);
    app(SportEventIdentitySynchronizer::class)->sync('cfb', $game);
    $before = $game->fresh()->getRawOriginal();
    $event = $game->fresh()->sportEvent->getRawOriginal();
    $service = Mockery::mock(EspnService::class);
    if ($kind === 'access') {
        $service->shouldReceive('getGame')->once()->andThrow(new RuntimeException('synthetic access failure'));
    } else {
        $service->shouldReceive('getGame')->once()->andReturn(['header' => ['id' => 'wrong-event', 'season' => ['year' => 2026],
            'competitions' => [['date' => '2027-01-13T00:00:00Z', 'timeValid' => false, 'status' => ['type' => ['name' => 'STATUS_SCHEDULED']]]]]]);
    }
    expect(fn () => (new SyncGameMetadata($service))->execute($game))->toThrow(RuntimeException::class);
    expect($game->fresh()->getRawOriginal())->toBe($before)->and($game->fresh()->sportEvent->getRawOriginal())->toBe($event);
})->with(['access', 'identity']);

it('uses unknown raw date inventory when a known status descriptor no longer matches current clock or event identity', function (string $kind) {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'calendar-edge', ...CfbGameStart::attributes('2027-01-13T02:00:00Z', [], 'espn_schedule', 'calendar-edge')]);
    $game->forceFill($kind === 'clock' ? ['game_time' => '03:00:00'] : ['espn_event_id' => 'different-calendar-event'])->saveQuietly();
    expect(CfbGameStart::for($game->fresh()))->toBeNull();
    $calendar = CfbPropEligibility::class;
    expect($calendar::onDate(Game::query(), '2027-01-13')->pluck('id')->all())->toBe([$game->id])
        ->and($calendar::onDate(Game::query(), '2027-01-12')->pluck('id')->all())->toBe([]);
})->with(['clock', 'event_identity']);

it('refuses over-capacity calendar candidates without returning a silently truncated day', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'calendar-capacity-base', 'game_date' => '2027-01-13', 'game_time' => '00:00:00']);
    $row = $game->getRawOriginal();
    unset($row['id']);
    foreach (array_chunk(range(1, 1000), 100) as $ids) {
        $rows = array_map(fn ($id) => [...$row, 'espn_event_id' => 'calendar-capacity-'.$id, 'espn_uid' => 'calendar-capacity-uid-'.$id], $ids);
        DB::table('cfb_games')->insert($rows);
    }
    expect(Game::count())->toBe(1001);
    expect(fn () => CfbPropEligibility::onDate(Game::query(), '2027-01-13')->pluck('id'))
        ->toThrow(LengthException::class, 'no partial inventory');
});

it('projects bounded clock headers rather than whole Game bodies during calendar qualification', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'calendar-projection', 'game_date' => '2027-01-13', 'game_time' => '00:00:00',
        'odds_data' => ['synthetic_large_unused' => str_repeat('body', 100000)],
        'kickoff_observation' => ['version' => 1, 'raw_date' => str_repeat('x', 2048), 'status' => 'known']]);
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    $queries = [];
    $connection->listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    try {
        $query = CfbPropEligibility::onDate(Game::query(), '2027-01-13');
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
    expect($query->pluck('id')->all())->toBe([$game->id])->and($queries)->not->toBeEmpty();
    $dates = app(CfbPropCalendarRead::class)->datesFor(Game::whereKey($game->id));
    expect($dates->first()['clock_status'])->toBe('clock_unconfirmed_or_invalid')
        ->and($dates->first()['label'])->toStartWith('Unverified date:');
    foreach ($queries as $sql) {
        expect(strtolower($sql))->not->toContain('odds_data')->not->toMatch('/select\s+\*/')
            ->and($sql)->toContain('CASE WHEN');
    }
});
