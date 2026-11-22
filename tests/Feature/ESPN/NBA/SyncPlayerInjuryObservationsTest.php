<?php

use App\Actions\ESPN\NBA\SyncPlayerInjuries;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\PlayerInjuryObservation;
use App\Models\NBA\PlayerInjurySnapshot;
use App\Models\NBA\PlayerInjurySnapshotEntry;
use App\Models\NBA\Team;
use App\Services\ESPN\BaseEspnService;
use App\Services\NBA\Injuries\InjuryFeedClient;
use App\Services\NBA\Injuries\InjurySnapshotRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['nba.injuries.observations_enabled' => true, 'espn.cache.enabled' => false, 'app.timezone' => 'America/Chicago']);
    Http::preventStrayRequests();
    Queue::fake();
    $this->team = Team::factory()->create(['espn_id' => '23']);
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'espn_id' => '123']);
    config(['nba.injuries.canary_team_ids' => [$this->team->id], 'nba.injuries.canary_expires_at' => now()->addDay()->toIso8601String()]);
    $this->url = app(InjuryFeedClient::class)->teamUrl('23');
    $this->rosterUrl = app(InjuryFeedClient::class)->teamUrl('23', true);
    $this->entry = ['id' => 'injury-123', 'athlete' => ['id' => '123'], 'status' => 'Out', 'date' => '2026-11-21T04:06:00Z'];
    $this->service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nba';

        public array $payload = [];

        public function getTeamInjuries(string $teamId): ?array
        {
            return $this->payload;
        }
    };
    $this->sync = new SyncPlayerInjuries($this->service);
});

afterEach(function () {
    $this->travelBack();
    Queue::assertNothingPushed();
});

it('leaves the legacy NBA path unchanged when capture is disabled', function () {
    config(['nba.injuries.observations_enabled' => false]);
    $this->service->payload = ['items' => [$this->entry]];
    expect($this->sync->execute('23'))->toBe(1)
        ->and(PlayerInjury::query()->sole()->source_updated_at->toDateTimeString())->toBe('2026-11-21 04:06:00')
        ->and(PlayerInjuryObservation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('normalizes offset source timestamps and preserves their original value', function (string $source, string $expected) {
    Http::fake([$this->url => Http::response(['count' => 1, 'items' => [[...$this->entry, 'lastUpdated' => $source]]])]);
    expect($this->sync->execute('23'))->toBe(1);
    $entry = PlayerInjurySnapshotEntry::query()->sole();
    expect($entry->source_updated_at->toDateTimeString())->toBe($expected)
        ->and($entry->source_time_raw)->toBe($source)
        ->and($entry->source_time_field)->toBe('lastUpdated')
        ->and(PlayerInjury::query()->sole()->source_updated_at->toDateTimeString())->toBe($expected)
        ->and(PlayerInjuryObservation::query()->sole()->outcome)->toBe('complete');
})->with([
    ['2026-11-21T04:06:00Z', '2026-11-20 22:06:00'],
    ['2026-07-21T04:06:00Z', '2026-07-20 23:06:00'],
    ['2026-11-20T23:06:00-05:00', '2026-11-20 22:06:00'],
]);

it('records unchanged polls without rewriting historical team content', function () {
    Http::fake([$this->url => fn () => Http::response(['count' => 1, 'items' => [$this->entry]])]);
    $this->travelTo(now()->setDate(2026, 11, 21)->setTime(10, 0));
    $this->sync->execute('23');
    $snapshot = PlayerInjurySnapshot::query()->sole()->toArray();
    $this->travel(30)->minutes();
    $this->sync->execute('23');
    expect(PlayerInjurySnapshot::query()->sole()->toArray())->toBe($snapshot)
        ->and(PlayerInjurySnapshotEntry::query()->count())->toBe(1)
        ->and(PlayerInjuryObservation::query()->count())->toBe(2);
    $polls = PlayerInjuryObservation::query()->orderBy('id')->get();
    expect($polls[1]->observed_at->diffInMinutes($polls[0]->observed_at, true))->toBe(30.0);
    Http::assertSentCount(2);
});

it('does not turn cache reads into fresh network observations', function () {
    config(['espn.cache.enabled' => true]);
    Cache::flush();
    Http::fake([$this->url => Http::response(['count' => 1, 'items' => [$this->entry]])]);
    $this->sync->execute('23');
    $this->travel(2)->minutes();
    $this->sync->execute('23');
    $polls = PlayerInjuryObservation::query()->orderBy('id')->get();
    expect($polls[1]->cache_hit)->toBeTrue()
        ->and($polls[1]->observed_at->equalTo($polls[0]->observed_at))->toBeTrue()
        ->and($polls[1]->completed_at->greaterThan($polls[0]->completed_at))->toBeTrue();
    Http::assertSentCount(1);
});

it('keeps the original snapshot when status changes', function () {
    Http::fake([$this->url => Http::sequence()
        ->push(['items' => [$this->entry]])
        ->push(['items' => [[...$this->entry, 'status' => 'Questionable']]])]);
    $this->sync->execute('23');
    $this->sync->execute('23');
    expect(PlayerInjurySnapshot::query()->count())->toBe(2)
        ->and(PlayerInjurySnapshotEntry::query()->orderBy('id')->pluck('status')->all())->toBe(['Out', 'Questionable'])
        ->and(PlayerInjury::query()->sole()->status)->toBe('Questionable');
});

it('retires current rows only for a verified explicitly empty collection', function () {
    Http::fake([$this->url => Http::sequence()->push(['items' => [$this->entry]])->push(['count' => 0, 'items' => []])]);
    $this->sync->execute('23');
    $this->sync->execute('23');
    expect(PlayerInjury::query()->sole()->is_active)->toBeFalse()
        ->and(PlayerInjuryObservation::query()->latest('id')->first()->outcome)->toBe('empty')
        ->and(PlayerInjurySnapshot::query()->latest('id')->first()->entry_count)->toBe(0);
    Http::assertSentCount(2);
});

it('preserves current rows for incomplete and untrusted feed responses', function (array $payload, string $outcome) {
    Http::fake([$this->url => Http::sequence()->push(['items' => [$this->entry]])->push($payload),
        $this->rosterUrl => Http::response([], 503)]);
    $this->sync->execute('23');
    $this->sync->execute('23');
    expect(PlayerInjury::query()->sole()->is_active)->toBeTrue()
        ->and(PlayerInjuryObservation::query()->latest('id')->first()->outcome)->toBe($outcome);
})->with([
    'count mismatch' => [['count' => 1, 'items' => []], 'partial'],
    'next page' => [['items' => [], 'nextPageUrl' => 'next'], 'partial'],
    'negative page' => [['items' => [], 'page' => -1], 'partial'],
    'malformed' => [['items' => 'unavailable'], 'failed'],
    'unresolved reference' => [['items' => [['$ref' => 'https://example.com/injury']]], 'partial'],
    'wrong team' => [['items' => [['id' => 'injury-123', 'athlete' => ['id' => '123'], 'team' => ['id' => '99'], 'status' => 'Active']]], 'partial'],
]);

it('retains unmapped athlete evidence without treating coverage as complete', function () {
    Http::fake([$this->url => Http::response(['items' => [[...$this->entry, 'athlete' => ['id' => '999']]]])]);
    expect($this->sync->execute('23'))->toBe(0);
    $entry = PlayerInjurySnapshotEntry::query()->sole();
    expect($entry->player_id)->toBeNull()->and($entry->espn_athlete_id)->toBe('999')
        ->and($entry->raw_payload['status'])->toBe('Out')
        ->and(PlayerInjuryObservation::query()->sole()->outcome)->toBe('partial');
});

it('preserves unknown timezone values without inventing a source instant', function () {
    Http::fake([$this->url => Http::response(['items' => [[...$this->entry, 'lastUpdated' => '2026-11-21 04:06:00']]])]);
    $this->sync->execute('23');
    $entry = PlayerInjurySnapshotEntry::query()->sole();
    expect($entry->source_updated_at)->toBeNull()
        ->and($entry->source_time_raw)->toBe('2026-11-21 04:06:00')
        ->and($entry->issues)->toContain('source_time_unknown');
});

it('distinguishes verified roster emptiness from missing injury coverage', function (array $athlete, string $outcome) {
    Http::fake([$this->url => Http::response([], 503), $this->rosterUrl => Http::response(['athletes' => [$athlete]])]);
    $this->sync->execute('23');
    expect(PlayerInjuryObservation::query()->sole()->outcome)->toBe($outcome)
        ->and(PlayerInjurySnapshot::query()->sole()->source_type)->toBe('roster');
})->with([
    [['id' => '123', 'injuries' => []], 'empty'],
    [['id' => '123'], 'partial'],
]);

it('rejects Eloquent mutations to snapshots entries and poll receipts', function () {
    Http::fake([$this->url => Http::response(['items' => [$this->entry]])]);
    $this->sync->execute('23');
    foreach ([PlayerInjurySnapshot::query()->sole(), PlayerInjurySnapshotEntry::query()->sole(), PlayerInjuryObservation::query()->sole()] as $record) {
        expect(fn () => $record->update($record instanceof PlayerInjurySnapshotEntry ? ['status' => 'Changed'] : ['outcome' => 'changed']))->toThrow(LogicException::class);
        expect(fn () => $record->delete())->toThrow(LogicException::class);
    }
});

it('rolls back the current projection when evidence persistence fails', function () {
    Http::fake([$this->url => Http::sequence()->push(['items' => [$this->entry]])
        ->push(['items' => [[...$this->entry, 'status' => 'Questionable']]])]);
    $this->sync->execute('23');
    $this->mock(InjurySnapshotRecorder::class)
        ->shouldReceive('record')->once()->andThrow(new RuntimeException('Storage failure'));
    expect(fn () => $this->sync->execute('23'))->toThrow(RuntimeException::class, 'Storage failure');
    expect(PlayerInjury::query()->sole()->status)->toBe('Out')
        ->and(PlayerInjurySnapshot::query()->count())->toBe(1)
        ->and(PlayerInjuryObservation::query()->count())->toBe(1);
});

it('resolves approved NBA references while retaining raw acquisition provenance', function () {
    $reference = 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/injuries/123';
    Http::fake([$this->url => Http::response(['items' => [['$ref' => $reference]]]),
        $reference => Http::response($this->entry)]);
    expect($this->sync->execute('23'))->toBe(1);
    $poll = PlayerInjuryObservation::query()->sole();
    expect($poll->outcome)->toBe('complete')->and($poll->fetch_metadata)->toHaveCount(2)
        ->and(PlayerInjurySnapshot::query()->sole()->raw_payload[0]['items'][0]['$ref'])->toBe($reference);
    Http::assertSentCount(2);
});

it('rejects oversized acquisition without retiring current rows', function () {
    Http::fake([$this->url => Http::sequence()->push(['items' => [$this->entry]])
        ->push(['items' => [], 'padding' => str_repeat('x', 2048)]), $this->rosterUrl => Http::response([], 503)]);
    $this->sync->execute('23');
    config(['nba.injuries.maximum_response_bytes' => 1024]);
    $this->sync->execute('23');
    $poll = PlayerInjuryObservation::query()->latest('id')->first();
    expect($poll->outcome)->toBe('failed')->and($poll->fetch_metadata[0]['error_code'])->toBe('response_too_large')
        ->and($poll->observed_at)->toBeNull()->and(PlayerInjury::query()->sole()->is_active)->toBeTrue();
});

it('separates provider timestamp changes from material availability changes', function () {
    Http::fake([$this->url => Http::sequence()->push(['items' => [$this->entry]])
        ->push(['items' => [[...$this->entry, 'lastUpdated' => '2026-11-21T06:06:00Z']]])]);
    $this->sync->execute('23');
    $this->sync->execute('23');
    $snapshots = PlayerInjurySnapshot::query()->orderBy('id')->get();
    expect($snapshots)->toHaveCount(2)
        ->and($snapshots[0]->material_hash)->toBe($snapshots[1]->material_hash)
        ->and($snapshots[0]->payload_hash)->not->toBe($snapshots[1]->payload_hash);
});

it('round trips the additive migration without altering NFL storage or gates', function () {
    $nflTeam = App\Models\NFL\Team::factory()->create();
    $schema = Schema::getColumns('nfl_player_injuries');
    $gates = [config('nfl_research.enabled'), config('prediction_lifecycle.canonical_reads.nfl'), config('nba.injuries.observations_enabled')];
    $migration = require database_path('migrations/2026_11_21_120000_create_nba_injury_observation_tables.php');
    $ordering = require database_path('migrations/2026_11_21_130000_add_nba_injury_acquisition_ordering.php');
    $ordering->down();
    $migration->down();
    expect(Schema::hasTable('nba_player_injury_observations'))->toBeFalse();
    expect(fn () => $this->sync->execute('23'))->toThrow(RuntimeException::class, 'Install NBA injury observation storage');
    Http::assertNothingSent();
    $migration->up();
    $ordering->up();
    expect(Schema::hasTable('nba_player_injury_snapshot_entries'))->toBeTrue()
        ->and(Schema::getColumns('nfl_player_injuries'))->toBe($schema)
        ->and($nflTeam->fresh()->id)->toBe($nflTeam->id)
        ->and($this->team->fresh()->id)->toBe($this->team->id)
        ->and([config('nfl_research.enabled'), config('prediction_lifecycle.canonical_reads.nfl'), config('nba.injuries.observations_enabled')])->toBe($gates);
});

it('does not infer team health from an empty or truncated fallback roster', function (array $roster) {
    Http::fake([$this->url => Http::sequence()->push(['items' => [$this->entry]])->push([], 503),
        $this->rosterUrl => Http::response(['athletes' => $roster])]);
    $this->sync->execute('23');
    $this->sync->execute('23');
    expect(PlayerInjuryObservation::query()->latest('id')->first()->outcome)->toBe('partial')
        ->and(PlayerInjury::query()->sole()->is_active)->toBeTrue();
})->with([
    'empty roster' => [[]],
    'missing known athlete' => [[['id' => '999', 'injuries' => []]]],
]);

it('never lets an older overlapping response overwrite the newest team attempt', function (bool $newerFails, bool $cacheEnabled) {
    config(['espn.cache.enabled' => $cacheEnabled]);
    Cache::flush();
    PlayerInjury::query()->create(['player_id' => $this->player->id, 'team_id' => $this->team->id,
        'injury_key' => 'espn:injury-123', 'status' => 'Out', 'is_active' => true]);
    $requests = 0;
    Http::fake([$this->url => function () use (&$requests, $newerFails) {
        if (++$requests === 1) {
            (new SyncPlayerInjuries($this->service))->execute('23');

            return Http::response(['items' => [[...$this->entry, 'status' => 'Questionable']]]);
        }

        return $newerFails ? Http::response([], 503) : Http::response(['items' => [[...$this->entry, 'status' => 'Doubtful']]]);
    }, $this->rosterUrl => Http::response([], 503)]);
    expect($this->sync->execute('23'))->toBe(0);
    $old = PlayerInjuryObservation::query()->where('acquisition_sequence', 1)->sole();
    $new = PlayerInjuryObservation::query()->where('acquisition_sequence', 2)->sole();
    expect($old->projection_applied)->toBeFalse()->and($old->outcome)->toBe('complete')
        ->and(DB::table('nba_injury_team_states')->value('latest_observation_id'))->toBe($new->id)
        ->and(PlayerInjury::query()->where('is_active', true)->sole()->status)->toBe($newerFails ? 'Out' : 'Doubtful');
    if ($cacheEnabled) {
        $cached = Cache::get('nba:injury-feed:v1:'.hash('sha256', $this->url));
        expect($newerFails ? $cached : $cached['payload']['items'][0]['status'])->toBe($newerFails ? null : 'Doubtful');
    }
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('keeps unselected or expired teams on the legacy path without capture side effects', function (string $policy) {
    if ($policy === 'unselected') {
        config(['nba.injuries.canary_team_ids' => [$this->team->id + 1000]]);
    } elseif ($policy === 'empty') {
        config(['nba.injuries.canary_team_ids' => []]);
    } else {
        config(['nba.injuries.canary_expires_at' => now()->subMinute()->toIso8601String()]);
    }
    $this->service->payload = ['items' => [$this->entry]];
    expect($this->sync->execute('23'))->toBe(1)
        ->and(PlayerInjury::query()->sole()->source_updated_at->toDateTimeString())->toBe('2026-11-21 04:06:00')
        ->and(PlayerInjuryObservation::query()->count())->toBe(0)
        ->and(DB::table('nba_injury_team_states')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['unselected', 'empty', 'expired']);

it('can manually capture complete NBA observations overnight when explicitly admitted without scheduling work', function (string $time) {
    $this->travelTo(CarbonImmutable::parse($time));
    config(['nba.injuries.canary_expires_at' => now()->addHours(2)->toIso8601String()]);
    $entry = [...$this->entry, 'date' => now()->subDay()->utc()->toIso8601String()];
    Http::fake([$this->url => Http::response(['count' => 1, 'items' => [$entry]])]);
    $nflSchema = Schema::getColumns('nfl_player_injuries');
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($this->sync->execute('23'))->toBe(1);
    $receipt = PlayerInjuryObservation::query()->sole();
    expect($receipt->outcome)->toBe('complete')->and($receipt->projection_applied)->toBeTrue()
        ->and($receipt->observed_at->timestamp)->toBe(now()->timestamp)
        ->and($receipt->cache_hit)->toBeFalse()->and($receipt->issues)->toBe([])
        ->and(Schema::getColumns('nfl_player_injuries'))->toBe($nflSchema);
    expect(collect(DB::getQueryLog())->contains(fn ($query) => preg_match('/^\s*(insert|update|delete).*\bnfl_/i', $query['query'])))->toBeFalse();
    DB::disableQueryLog();
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
})->with([
    'midnight' => '2026-11-22T00:02:00-06:00',
    'overnight' => '2026-11-22T03:32:00-06:00',
    'before daytime acquisition' => '2026-11-22T07:32:00-06:00',
    'spring DST before jump' => '2026-03-08T01:32:00-06:00',
    'spring DST after jump' => '2026-03-08T03:02:00-05:00',
    'fall DST first hour' => '2026-11-01T01:32:00-05:00',
    'fall DST repeated hour' => '2026-11-01T01:32:00-06:00',
]);
