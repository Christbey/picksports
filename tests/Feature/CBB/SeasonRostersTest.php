<?php

use App\Actions\CBB\GenerateCanonicalPrediction;
use App\Actions\ESPN\CBB\SyncPlayers;
use App\Jobs\ESPN\CBB\FetchPlayers;
use App\Models\CBB\Game;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use App\Models\CBB\RosterEntry;
use App\Models\CBB\RosterSnapshot;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\CBB\CbbRosterStrength;
use App\Services\CBB\CbbScoreMarginRatings;
use App\Services\CBB\Predictions\CbbCalculationReleaseRegistrar;
use App\Services\ESPN\CBB\EspnService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->travelTo('2026-11-05 12:00:00');
    $this->team = Team::factory()->create(['espn_id' => '142']);
});
function cbbSeasonRosterPayload(string $teamId = '142', int $season = 2027, array $ids = [1, 2, 3, 4, 5, 6]): array
{
    return ['season' => ['year' => $season], 'team' => ['id' => $teamId], 'athletes' => array_map(fn ($id): array => ['id' => (string) $id, 'fullName' => 'Roster Player '.$id, 'firstName' => 'Roster', 'lastName' => 'Player '.$id, 'jersey' => (string) $id, 'position' => ['abbreviation' => 'G'], 'year' => 'Junior'], $ids)];
}
function cbbRosterSyncResponse(array $payload, Team $team, int $season = 2027): int
{
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getRosterForSeason')->once()->with((string) $team->espn_id, $season)->andReturn($payload);

    return (new SyncPlayers($service))->execute((string) $team->espn_id, $season);
}
it('replaces current membership while preserving identities and previous observations', function (): void {
    expect(cbbRosterSyncResponse(cbbSeasonRosterPayload(), $this->team))->toBe(6);
    $old = RosterSnapshot::query()->firstOrFail();
    $before = $old->observed_at;
    $departed = Player::query()->where('espn_id', '1')->firstOrFail();
    $this->travel(1)->hours();
    cbbRosterSyncResponse(cbbSeasonRosterPayload(ids: [3, 4, 5, 6, 7, 8]), $this->team);
    $latest = RosterSnapshot::knownAt($this->team->id, 2027, now());
    expect($latest->entries()->where('player_id', $departed->id)->exists())->toBeFalse()
        ->and($old->entries()->where('player_id', $departed->id)->exists())->toBeTrue()
        ->and($departed->fresh())->not->toBeNull()
        ->and(RosterSnapshot::knownAt($this->team->id, 2027, $before)->id)->toBe($old->id)
        ->and(Player::count())->toBe(8);
});
it('rejects wrong-season wrong-team duplicate and partial responses without changing a valid roster', function (string $failure): void {
    cbbRosterSyncResponse(cbbSeasonRosterPayload(), $this->team);
    $payload = cbbSeasonRosterPayload();
    if ($failure === 'season') {
        $payload['season']['year'] = 2026;
    }
    if ($failure === 'team') {
        $payload['team']['id'] = '999';
    }
    if ($failure === 'duplicate') {
        $payload['athletes'][1]['id'] = '1';
    }
    if ($failure === 'partial') {
        $payload['count'] = 20;
    }
    if ($failure === 'short') {
        $payload['athletes'] = array_slice($payload['athletes'], 0, 4);
    }
    expect(fn () => cbbRosterSyncResponse($payload, $this->team))->toThrow(UnexpectedValueException::class);
    expect(RosterSnapshot::count())->toBe(1)->and(Player::count())->toBe(6);
})->with(['season', 'team', 'duplicate', 'partial', 'short']);
it('retains the last good snapshot for an empty response', function (): void {
    cbbRosterSyncResponse(cbbSeasonRosterPayload(), $this->team);
    expect(cbbRosterSyncResponse(cbbSeasonRosterPayload(ids: []), $this->team))->toBe(0)
        ->and(RosterSnapshot::count())->toBe(1);
});
it('preserves current player ownership when syncing a historical transfer roster', function (): void {
    $current = Team::factory()->create(['espn_id' => '99']);
    cbbRosterSyncResponse(cbbSeasonRosterPayload('99'), $current);
    $historical = cbbSeasonRosterPayload(season: 2026);
    foreach ($historical['athletes'] as &$athlete) {
        $athlete['jersey'] = '42';
        $athlete['position'] = ['abbreviation' => 'F'];
    } unset($athlete);
    cbbRosterSyncResponse($historical, $this->team, 2026);
    $player = Player::query()->where('espn_id', '1')->firstOrFail();
    expect($player->team_id)->toBe($current->id)->and($player->jersey_number)->toBe('1');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/cbb/teams/'.$this->team->id.'/players?season=2026&position=F')->assertSuccessful()
        ->assertJsonCount(6, 'data')->assertJsonPath('data.0.team_id', $this->team->id)
        ->assertJsonPath('data.0.jersey_number', '42')->assertJsonPath('data.0.position', 'F')
        ->assertJsonPath('meta.roster.season', 2026);
});
it('reports an unavailable season roster instead of returning legacy accumulated players', function (): void {
    Player::factory()->create(['team_id' => $this->team->id, 'espn_id' => '9876', 'full_name' => 'Legacy Player']);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/cbb/teams/'.$this->team->id.'/players?season=2027')->assertSuccessful()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.roster.status', 'unavailable')
        ->assertJsonPath('meta.warnings.0', 'season_roster_unavailable');
});
it('keeps queued season identity explicit across a clock rollover', function (): void {
    Bus::fake();
    $this->artisan('espn:sync-cbb-players', ['teamEspnId' => '142', '--season' => 2026])->assertSuccessful();
    Bus::assertDispatched(FetchPlayers::class, fn ($job): bool => $job->season === 2026 && $job->teamEspnId === '142');
});
it('does not expose observations or late-ingested backdated membership before they were recorded', function (): void {
    $old = RosterSnapshot::factory()->create(['team_id' => $this->team->id, 'observed_at' => now()->subDay(), 'created_at' => now()->subDay()]);
    RosterSnapshot::factory()->create(['team_id' => $this->team->id, 'observed_at' => now()->subHour(), 'created_at' => now()->addHour()]);
    RosterSnapshot::factory()->create(['team_id' => $this->team->id, 'observed_at' => now()->addHour()]);
    expect(RosterSnapshot::knownAt($this->team->id, 2027, now())->id)->toBe($old->id)
        ->and(fn () => $old->update(['season' => 2026]))->toThrow(LogicException::class);
});
it('does not replace a complete observation with a suspiciously truncated roster', function (): void {
    cbbRosterSyncResponse(cbbSeasonRosterPayload(ids: range(1, 16)), $this->team);
    expect(fn () => cbbRosterSyncResponse(cbbSeasonRosterPayload(ids: range(1, 5)), $this->team))->toThrow(UnexpectedValueException::class);
    expect(RosterSnapshot::count())->toBe(1);
});

function cbbRosterStrengthHistory(Team $home, Team $source): array
{
    $players = [];
    foreach ([$home, $source] as $team) {
        for ($p = 0; $p < 5; $p++) {
            $players[$team->id][] = Player::factory()->create(['espn_id' => $team->id.'00'.$p, 'full_name' => 'History '.$team->id.' '.$p, 'team_id' => $team->id]);
        }
    }
    for ($day = 1; $day <= 8; $day++) {
        $game = Game::factory()->create(['season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-03-0'.$day, 'home_team_id' => $home->id, 'away_team_id' => $source->id, 'home_score' => 50, 'away_score' => 60, 'neutral_site' => true]);
        foreach ([$home, $source] as $team) {
            $score = $team->id === $home->id ? 50 : 60;
            TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $team->id, 'points' => $score, 'possessions' => 70, 'field_goals_made' => $score / 2, 'field_goals_attempted' => 40, 'three_point_made' => 0, 'three_point_attempted' => 0, 'free_throws_made' => 0, 'free_throws_attempted' => 0]);
            foreach ($players[$team->id] as $player) {
                PlayerStat::factory()->create(['game_id' => $game->id, 'team_id' => $team->id, 'player_id' => $player->id, 'minutes_played' => '40:00', 'points' => $score / 5]);
            }
        }
    }

    return $players;
}
function cbbRecordRosterMembers(Team $team, int $season, array $players): RosterSnapshot
{
    $snapshot = RosterSnapshot::factory()->create(['team_id' => $team->id, 'season' => $season]);
    foreach ($players as $player) {
        RosterEntry::factory()->create(['roster_snapshot_id' => $snapshot->id, 'player_id' => $player->id]);
    }

    return $snapshot;
}
it('separates returning membership incoming membership and measured prior production', function (): void {
    $source = Team::factory()->create();
    $players = cbbRosterStrengthHistory($this->team, $source);
    cbbRecordRosterMembers($this->team, 2026, $players[$this->team->id]);
    $current = [...array_slice($players[$this->team->id], 0, 2), ...array_slice($players[$source->id], 0, 3)];
    $snapshot = cbbRecordRosterMembers($this->team, 2027, $current);
    $result = app(CbbRosterStrength::class)->forTeam($this->team->id, 2027, [$this->team->id => -10, $source->id => 20], now());
    expect($result['available'])->toBeTrue()->and($result['snapshot_id'])->toBe($snapshot->id)
        ->and($result['returning_roster_players'])->toBe(2)->and($result['incoming_roster_players'])->toBe(3)
        ->and($result['returning_measured_players'])->toBe(2)->and($result['incoming_measured_players'])->toBe(3)
        ->and($result['returning_minutes_share'])->toBe(0.4)->and($result['returning_points_share'])->toBe(0.4)
        ->and($result['candidate_prior_rating'])->toBe(8.0)->and($result['prior_adjustment'])->toBe(18.0)
        ->and($result['prior_games'])->toBe(8);
});
it('does not equate an unmeasured incoming player with a proven replacement', function (): void {
    $source = Team::factory()->create();
    $players = cbbRosterStrengthHistory($this->team, $source);
    cbbRecordRosterMembers($this->team, 2026, $players[$this->team->id]);
    $unknown = [];
    for ($i = 0; $i < 5; $i++) {
        $unknown[] = Player::factory()->create(['espn_id' => '9000'.$i, 'full_name' => 'Unmeasured '.$i, 'team_id' => $this->team->id]);
    }
    cbbRecordRosterMembers($this->team, 2027, $unknown);
    $result = app(CbbRosterStrength::class)->forTeam($this->team->id, 2027, [$this->team->id => -10, $source->id => 20], now());
    expect($result['available'])->toBeFalse()->and($result['incoming_roster_players'])->toBe(5)
        ->and($result['unmeasured_players'])->toBe(5)->and($result['reason'])->toBe('insufficient_incoming_player_measurements');
});
it('excludes post-tipoff roster observations from a historical prediction preview', function (): void {
    cbbRosterSyncResponse(cbbSeasonRosterPayload(), $this->team);
    $asOf = now()->subHour();
    $service = app(CbbRosterStrength::class);
    $result = $service->forTeam($this->team->id, 2027, [$this->team->id => 10], $asOf);
    expect($result['available'])->toBeFalse()->and($result['reason'])->toBe('season_roster_unavailable');
    $this->travel(4)->days();
    $stale = $service->forTeam($this->team->id, 2027, [$this->team->id => 10], now());
    expect($stale['available'])->toBeFalse()->and($stale['reason'])->toBe('season_roster_stale');
});
it('captures a roster candidate without changing a forecast unless explicitly enabled', function (): void {
    $source = Team::factory()->create();
    $players = cbbRosterStrengthHistory($this->team, $source);
    cbbRecordRosterMembers($this->team, 2026, $players[$this->team->id]);
    cbbRecordRosterMembers($this->team, 2027, [...array_slice($players[$this->team->id], 0, 2), ...array_slice($players[$source->id], 0, 3)]);
    cbbRecordRosterMembers($source, 2026, $players[$source->id]);
    cbbRecordRosterMembers($source, 2027, $players[$source->id]);
    $game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $source->id, 'season' => 2027, 'season_type' => 2, 'game_date' => '2026-11-06', 'neutral_site' => true]);
    config(['cbb.prediction.score_margin.enabled' => true, 'cbb.prediction.roster.enabled' => true, 'cbb.prediction.roster.apply_adjustment' => false]);
    $service = app(CbbScoreMarginRatings::class);
    $shadow = $service->forGame($game);
    expect($shadow['home_margin'])->toBe(-7.0)->and($shadow['roster']['applied'])->toBeFalse()
        ->and($shadow['roster']['candidate_spread_adjustment'])->toBe(4.2);
    config(['cbb.prediction.roster.apply_adjustment' => true]);
    $preview = $service->forGame($game);
    expect($preview['roster']['applied'])->toBeTrue()->and($preview['home_margin'])->toBe(-2.8);
});
it('requests an explicit provider season through the container-resolved action', function (): void {
    Http::fake(['*teams/142/roster?season=2027' => Http::response(cbbSeasonRosterPayload())]);
    expect(app(SyncPlayers::class)->execute('142', 2027))->toBe(6);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'roster?season=2027'));
});
it('seeds a missing prior roster when explicitly requested without changing the current profile', function (): void {
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getRosterForSeason')->with('142', 2027)->once()->andReturn(cbbSeasonRosterPayload());
    $service->shouldReceive('getRosterForSeason')->with('142', 2026)->once()->andReturn(cbbSeasonRosterPayload(season: 2026));
    $this->instance(EspnService::class, $service);
    $this->artisan('espn:sync-cbb-players', ['teamEspnId' => '142', '--season' => 2027, '--sync' => true, '--include-prior' => true])->assertSuccessful();
    expect(RosterSnapshot::query()->pluck('season')->sort()->values()->all())->toBe([2026, 2027]);
});
it('keeps newly added queue properties compatible with older serialized jobs', function (): void {
    $job = (new ReflectionClass(FetchPlayers::class))->newInstanceWithoutConstructor();
    expect($job->season)->toBeNull()->and($job->includePrior)->toBeFalse();
});
it('rejects incomplete player boxes as evidence for roster strength', function (): void {
    $source = Team::factory()->create();
    $players = cbbRosterStrengthHistory($this->team, $source);
    cbbRecordRosterMembers($this->team, 2027, [...array_slice($players[$this->team->id], 0, 2), ...array_slice($players[$source->id], 0, 3)]);
    PlayerStat::query()->where('team_id', $this->team->id)->firstOrFail()->update(['points' => 99]);
    $result = app(CbbRosterStrength::class)->forTeam($this->team->id, 2027, [$this->team->id => -10, $source->id => 20], now());
    expect($result['available'])->toBeFalse()->and($result['prior_games'])->toBe(7)
        ->and($result['reason'])->toBe('insufficient_prior_player_box_scores');
});
it('does not use a newly observed roster in a past-game rating', function (): void {
    $source = Team::factory()->create();
    cbbRosterStrengthHistory($this->team, $source);
    cbbRosterSyncResponse(cbbSeasonRosterPayload(), $this->team);
    $game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $source->id, 'season' => 2027, 'season_type' => 2, 'game_date' => '2026-11-04', 'game_time' => '23:00:00', 'neutral_site' => true]);
    config(['cbb.prediction.roster.enabled' => true]);
    $rating = app(CbbScoreMarginRatings::class)->forGame($game);
    expect($rating['roster']['home']['reason'])->toBe('season_roster_unavailable')->and($rating['roster']['applied'])->toBeFalse();
});
it('freezes roster membership and measured production with a non-adjusting canonical release', function (): void {
    $source = Team::factory()->create();
    $players = cbbRosterStrengthHistory($this->team, $source);
    cbbRecordRosterMembers($this->team, 2026, $players[$this->team->id]);
    cbbRecordRosterMembers($this->team, 2027, [...array_slice($players[$this->team->id], 0, 2), ...array_slice($players[$source->id], 0, 3)]);
    cbbRecordRosterMembers($source, 2026, $players[$source->id]);
    cbbRecordRosterMembers($source, 2027, $players[$source->id]);
    $event = SportEvent::factory()->create(['sport' => 'cbb', 'season' => 2027, 'season_type' => '2', 'starts_at' => now()->addDay(), 'neutral_site' => true]);
    $tip = $event->starts_at->utc();
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'status' => 'STATUS_SCHEDULED', 'home_team_id' => $this->team->id, 'away_team_id' => $source->id, 'season' => 2027, 'season_type' => 2, 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'neutral_site' => true, 'odds_data' => null, 'odds_updated_at' => null]);
    app(CbbCalculationReleaseRegistrar::class)->register('1.5.0', effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($game);
    $input = $prediction->calculationRun->inputSnapshot;
    $stored = $input->inputs;
    expect($prediction->publication_state)->toBe('published')->and($prediction->model_version)->toBe('1.5.0')
        ->and((float) data_get($stored, 'established_model.outputs.predicted_spread'))->toBe(-7.0)
        ->and(data_get($stored, 'established_model.model_metadata.score_margin_rating.roster.applied'))->toBeFalse()
        ->and(data_get($stored, 'established_model.model_metadata.score_margin_rating.roster.home.returning_roster_players'))->toBe(2)
        ->and(data_get($stored, 'established_model.model_metadata.score_margin_rating.roster.home.incoming_roster_players'))->toBe(3);
    $this->travel(1)->hours();
    cbbRecordRosterMembers($this->team, 2027, $players[$this->team->id]);
    expect($input->fresh()->inputs)->toBe($stored);
});
it('requires a team when selecting a CBB roster season on the player directory', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/cbb/players?season=2027')->assertUnprocessable()->assertJsonValidationErrors('team_id');
});
it('rolls back both profiles and membership when a roster write fails midway', function (): void {
    cbbRosterSyncResponse(cbbSeasonRosterPayload(), $this->team);
    $before = Player::query()->orderBy('id')->get()->toArray();
    $event = 'eloquent.creating: '.RosterEntry::class;
    Event::listen($event, fn () => throw new RuntimeException('Simulated member write failure'));
    $this->travel(1)->hours();
    try {
        expect(fn () => cbbRosterSyncResponse(cbbSeasonRosterPayload(), $this->team))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect(RosterSnapshot::count())->toBe(1)->and(Player::query()->orderBy('id')->get()->toArray())->toBe($before);
});
it('reports uninitialized roster storage safely during an additive schema rollout', function (): void {
    Schema::drop('cbb_roster_entries');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/cbb/teams/'.$this->team->id.'/players?season=2027')->assertSuccessful()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.roster.status', 'not_initialized')
        ->assertJsonPath('meta.warnings.0', 'season_roster_storage_unavailable');
});
it('keeps a verified current refresh successful when its historical provider roster is incomplete', function (): void {
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getRosterForSeason')->with('142', 2027)->once()->andReturn(cbbSeasonRosterPayload());
    $service->shouldReceive('getRosterForSeason')->with('142', 2026)->once()->andReturn(cbbSeasonRosterPayload(season: 2026, ids: [1, 2, 3]));
    $this->instance(EspnService::class, $service);
    $this->artisan('espn:sync-cbb-players', ['teamEspnId' => '142', '--season' => 2027, '--sync' => true, '--include-prior' => true])
        ->expectsOutputToContain('1 synced, 0 unavailable, 0 rejected, 1 prior gaps, 0 failed')->assertSuccessful();
    expect(RosterSnapshot::query()->where('season', 2027)->count())->toBe(1)
        ->and(RosterSnapshot::query()->where('season', 2026)->count())->toBe(0);
});
it('fails an inline refresh with no verified current roster and preserves the rejection warning', function (): void {
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getRosterForSeason')->with('142', 2027)->once()->andReturn(cbbSeasonRosterPayload(season: 2026));
    $this->instance(EspnService::class, $service);
    $this->artisan('espn:sync-cbb-players', ['teamEspnId' => '142', '--season' => 2027, '--sync' => true])
        ->expectsOutputToContain('0 synced, 0 unavailable, 1 rejected')->assertFailed();
    expect(RosterSnapshot::count())->toBe(0);
});
it('does not discard verified current membership when another team has rejected provider data', function (): void {
    $other = Team::factory()->create(['espn_id' => '99']);
    Game::factory()->create(['season' => 2027, 'home_team_id' => $this->team->id, 'away_team_id' => $other->id]);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getRosterForSeason')->with('142', 2027)->once()->andReturn(cbbSeasonRosterPayload());
    $service->shouldReceive('getRosterForSeason')->with('99', 2027)->once()->andReturn(cbbSeasonRosterPayload('99', ids: [1, 2, 3]));
    $this->instance(EspnService::class, $service);
    $this->artisan('espn:sync-cbb-players', ['--season' => 2027, '--sync' => true])
        ->expectsOutputToContain('1 synced, 0 unavailable, 1 rejected')->assertSuccessful();
    expect(RosterSnapshot::knownAt($this->team->id, 2027, now()))->not->toBeNull()
        ->and(RosterSnapshot::knownAt($other->id, 2027, now()))->toBeNull();
});
it('still fails the refresh for an unexpected roster write error', function (): void {
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getRosterForSeason')->with('142', 2027)->once()->andThrow(new RuntimeException('Simulated storage failure'));
    $this->instance(EspnService::class, $service);
    $this->artisan('espn:sync-cbb-players', ['teamEspnId' => '142', '--season' => 2027, '--sync' => true])
        ->expectsOutputToContain('1 failed')->assertFailed();
});
it('does not retry a valid current queue refresh because prior roster coverage is partial', function (): void {
    Http::fake([
        '*teams/142/roster?season=2027' => Http::response(cbbSeasonRosterPayload()),
        '*teams/142/roster?season=2026' => Http::response(cbbSeasonRosterPayload(season: 2026, ids: [1, 2, 3])),
    ]);
    Log::spy();
    (new FetchPlayers('142', 2027, true))->handle();
    expect(RosterSnapshot::query()->where('season', 2027)->count())->toBe(1)
        ->and(RosterSnapshot::query()->where('season', 2026)->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('CBB: Prior roster observation rejected', Mockery::on(fn ($context): bool => $context['season'] === 2026))->once();
});
