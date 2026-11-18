<?php

use App\Jobs\ESPN\CBB\FetchPlayerInjuries;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerInjury;
use App\Models\CBB\Team;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

it('fans out the all-team refresh without making provider requests in the parent', function () {
    $teams = Team::factory()->count(3)->create();
    Bus::fake();
    Http::fake(['*' => Http::response(['items' => []])]);

    (new FetchPlayerInjuries)->handle();

    Bus::assertDispatchedTimes(FetchPlayerInjuries::class, 3);
    foreach ($teams as $team) {
        Bus::assertDispatched(FetchPlayerInjuries::class, fn ($job) => $job->teamEspnId === (string) $team->espn_id);
    }
    Http::assertNothingSent();
});

it('bounds and deduplicates injury work by team on the existing queue', function () {
    $first = new FetchPlayerInjuries('22');
    $same = new FetchPlayerInjuries('22');
    $other = new FetchPlayerInjuries('23');
    expect($first)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($first->uniqueId())->toBe($same->uniqueId())
        ->and($first->uniqueId())->not->toBe($other->uniqueId())
        ->and($first->timeout)->toBeLessThanOrEqual(config('queue.worker_contracts.default.timeout'))
        ->and($first->tries)->toBe(3);
});

it('keeps stored injuries and exposes an unavailable provider as a failed team job', function (string $failure) {
    $team = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => 'player-'.$team->id]);
    $injury = PlayerInjury::create(['team_id' => $team->id, 'player_id' => $player->id,
        'injury_key' => 'existing', 'status' => 'Out', 'is_active' => true]);
    Http::fake(function () use ($failure) {
        if ($failure === 'timeout') {
            throw new ConnectionException('Simulated provider request timeout');
        }

        $payload = match ($failure) {
            'malformed' => ['unexpected' => 'payload'],
            'malformed_items' => ['items' => ['invalid']],
            'missing_athlete' => ['items' => [['status' => 'Out']]],
            default => [],
        };

        return Http::response($payload, $failure === 'unavailable' ? 503 : 200);
    });

    expect(fn () => (new FetchPlayerInjuries('22'))->handle())->toThrow(RuntimeException::class);
    expect($injury->fresh()->is_active)->toBeTrue();
})->with(['unavailable', 'timeout', 'malformed', 'malformed_items', 'missing_athlete']);

it('handles a verified empty report for one team without touching another team', function () {
    $teams = Team::factory()->count(2)->create();
    $injuries = $teams->map(function ($team) {
        $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => 'player-'.$team->id]);

        return PlayerInjury::create(['team_id' => $team->id, 'player_id' => $player->id,
            'injury_key' => 'existing', 'status' => 'Out', 'is_active' => true]);
    });
    Http::fake(['*' => Http::response(['items' => [], 'athletes' => []])]);

    (new FetchPlayerInjuries((string) $teams[0]->espn_id))->handle();

    expect($injuries[0]->fresh()->is_active)->toBeFalse()
        ->and($injuries[1]->fresh()->is_active)->toBeTrue();
});

it('deduplicates queued refreshes for the same team while allowing other teams', function () {
    Bus::fake();
    FetchPlayerInjuries::dispatch('22');
    FetchPlayerInjuries::dispatch('22');
    FetchPlayerInjuries::dispatch('23');

    Bus::assertDispatchedTimes(FetchPlayerInjuries::class, 2);
});

it('allows a healthy team to finish after another team request times out', function () {
    $teams = Team::factory()->count(2)->create();
    Http::fake(function ($request) use ($teams) {
        if (str_contains($request->url(), '/teams/'.$teams[0]->espn_id.'/')) {
            throw new ConnectionException('Simulated slow team request');
        }

        return Http::response(['items' => [], 'athletes' => []]);
    });
    expect(fn () => (new FetchPlayerInjuries((string) $teams[0]->espn_id))->handle())->toThrow(RuntimeException::class);
    (new FetchPlayerInjuries((string) $teams[1]->espn_id))->handle();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/teams/'.$teams[1]->espn_id.'/'));
});

it('stores a reported injury using the CBB provider without fetching other teams', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    Http::fake(['*' => Http::response(['items' => [[
        'id' => 'injury-123', 'athlete' => ['id' => '123'], 'status' => 'Out',
        'details' => 'Ankle injury',
    ]]])]);

    (new FetchPlayerInjuries('22'))->handle();

    $injury = PlayerInjury::sole();
    expect($injury->player_id)->toBe($player->id)->and($injury->is_active)->toBeTrue()
        ->and($injury->status)->toBe('Out');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/mens-college-basketball/teams/22/injuries'));
});

it('preserves stored injuries when an injury reference cannot be resolved', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    $injury = PlayerInjury::create(['team_id' => $team->id, 'player_id' => $player->id,
        'injury_key' => 'existing', 'status' => 'Out', 'is_active' => true]);
    Http::fake(function ($request) {
        return str_contains($request->url(), '/teams/22/injuries')
            ? Http::response(['items' => [['$ref' => 'https://sports.core.api.espn.com/injury/unavailable']]])
            : Http::response([], 503);
    });

    expect(fn () => (new FetchPlayerInjuries('22'))->handle())->toThrow(RuntimeException::class);
    expect($injury->fresh()->is_active)->toBeTrue();
});

it('preserves injuries when roster fallback contents are malformed', function (array $athletes) {
    $team = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    $injury = PlayerInjury::create(['team_id' => $team->id, 'player_id' => $player->id,
        'injury_key' => 'existing', 'status' => 'Out', 'is_active' => true]);
    Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/roster') ? ['athletes' => $athletes] : []));

    expect(fn () => (new FetchPlayerInjuries('22'))->handle())->toThrow(RuntimeException::class);
    expect($injury->fresh()->is_active)->toBeTrue();
})->with([
    'scalar athlete' => [[null]],
    'missing athlete identity' => [[['unexpected' => 'value']]],
    'malformed injury list' => [[['id' => '123', 'injuries' => 'invalid']]],
    'malformed grouped roster' => [[['items' => ['invalid']]]],
    'malformed injury entry' => [[['id' => '123', 'injuries' => [null]]]],
    'empty injury entry' => [[['id' => '123', 'injuries' => [[]]]]],
]);

it('does not clear absent injuries from an incomplete collection', function (array $metadata, string $source) {
    $team = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    $injury = PlayerInjury::create(['team_id' => $team->id, 'player_id' => $player->id,
        'injury_key' => 'existing', 'status' => 'Out', 'is_active' => true]);
    Http::fake(function ($request) use ($metadata, $source) {
        $roster = str_contains($request->url(), '/roster');
        $payload = $roster ? ['athletes' => []] : ['items' => []];
        if (($source === 'roster') === $roster) {
            $payload = [...$payload, ...$metadata];
        }

        return Http::response($payload);
    });

    expect(fn () => (new FetchPlayerInjuries('22'))->handle())->toThrow(RuntimeException::class);
    expect($injury->fresh()->is_active)->toBeTrue();
})->with([
    'missing records' => [['count' => 2]],
    'multiple pages' => [['pageCount' => 2]],
    'later page' => [['pageIndex' => 2]],
    'invalid record count' => [['count' => 'invalid']],
    'more records flag' => [['hasMore' => true]],
    'next page link' => [['links' => ['next' => 'https://example.test/next']]],
    'next relation' => [['links' => [['rel' => 'next', 'href' => 'https://example.test/next']]]],
])->with(['injuries', 'roster']);

it('accepts complete grouped rosters and single-page empty injury collections', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    $injury = PlayerInjury::create(['team_id' => $team->id, 'player_id' => $player->id,
        'injury_key' => 'existing', 'status' => 'Out', 'is_active' => true]);
    Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/roster')
        ? ['athletes' => [['items' => [['id' => '123', 'injuries' => []]]]], 'count' => 1]
        : ['items' => [], 'count' => 0, 'pageCount' => 1, 'pageIndex' => 1]));

    (new FetchPlayerInjuries('22'))->handle();

    expect($injury->fresh()->is_active)->toBeFalse();
});

it('records a real worker timeout and releases the team uniqueness lock', function () {
    $directory = sys_get_temp_dir().'/cbb-injury-worker-'.Str::uuid();
    File::makeDirectory($directory, 0700, true);
    touch($directory.'/queue.sqlite');
    $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/config.php',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/queue.sqlite',
        'CACHE_STORE' => 'file', 'CACHE_PREFIX' => basename($directory), 'QUEUE_CONNECTION' => 'database', 'NIGHTWATCH_ENABLED' => 'false',
        'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false'];
    try {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/cbb-injury-timeout-worker.php'), $directory], base_path(), $environment);
        $worker->setTimeout(15);
        try {
            $worker->run();
        } catch (ProcessSignaledException $error) {
            expect($error->getProcess()->getTermSignal())->toBe(SIGKILL);
        }
        expect($worker->getExitCode())->toBeIn([1, 128 + SIGKILL]);
        $inspect = new Process([PHP_BINARY, base_path('tests/Fixtures/cbb-injury-timeout-worker.php'), $directory, 'inspect'], base_path(), $environment);
        $inspect->setTimeout(15);
        $inspect->mustRun();
        $result = json_decode($inspect->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['failed_count'])->toBe(1)
            ->and($result['exception'])->toContain('TimeoutExceededException')
            ->and($result['queued_count'])->toBe(1);
    } finally {
        File::deleteDirectory($directory);
    }
})->skip(fn () => ! extension_loaded('pcntl'), 'The queue timeout integration test requires PCNTL.');

it('preserves injuries missing from a truncated nonempty provider page', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $players = collect(['123', '124'])->map(fn ($id) => Player::factory()->create(['team_id' => $team->id, 'espn_id' => $id]));
    $absent = PlayerInjury::create(['team_id' => $team->id, 'player_id' => $players[1]->id,
        'injury_key' => 'existing', 'status' => 'Out', 'is_active' => true]);
    Http::fake(['*' => Http::response(['count' => 2, 'pageCount' => 2, 'pageIndex' => 1,
        'items' => [['id' => 'injury-123', 'athlete' => ['id' => '123'], 'status' => 'Out']]])]);

    expect(fn () => (new FetchPlayerInjuries('22'))->handle())->toThrow(RuntimeException::class);
    expect($absent->fresh()->is_active)->toBeTrue();
});
