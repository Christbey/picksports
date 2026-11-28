<?php

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\Team;
use App\Models\ProviderRefreshAttempt;
use App\Models\ProviderRefreshHead;
use App\Services\ProviderData\Refresh\ProviderRefreshCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../../Support/ProviderRefreshFixture.php';

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Refresh races require the guarded disposable MySQL target.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    config(['database.connections.refresh_peer' => config('database.connections.mysql')]);
    $this->refreshTeam = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $this->refreshTeam->id, 'espn_id' => '123']);
    PlayerInjury::create(['team_id' => $this->refreshTeam->id, 'player_id' => $player->id,
        'injury_key' => 'espn:known', 'status' => 'Out', 'is_active' => true]);
    DepthChartEntry::create(['team_id' => $this->refreshTeam->id, 'season' => 2026,
        'espn_athlete_id' => '123', 'position_slot_key' => 'qb', 'depth_rank' => 1, 'is_starter' => true]);
    enableSyntheticRefreshScope($this->refreshTeam, 'injuries', 0);
    enableSyntheticRefreshScope($this->refreshTeam, 'depth_chart', 2026);
    $this->refreshDirectory = sys_get_temp_dir().'/picksports-refresh-race-'.bin2hex(random_bytes(8));
    mkdir($this->refreshDirectory, 0700);
    $this->refreshWorkers = [];
});

afterEach(function () {
    foreach ($this->refreshWorkers ?? [] as $worker) {
        $worker->stop(0);
    }
    foreach (DB::getConnections() as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement("SET SESSION time_zone = '+00:00'");
        }
    }
    DB::purge('refresh_peer');
    $this->travelBack();
    if (isset($this->refreshDirectory)) {
        File::deleteDirectory($this->refreshDirectory);
    }
});

function refreshRaceBarrier(Closure $condition): void
{
    $deadline = microtime(true) + 15;
    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Refresh parent barrier exceeded deadline.');
        }
        usleep(10000);
    }
}

function startRefreshRace($test, string $component, string $role, bool $newerFails = false): Process
{
    $config = config('database.connections.mysql');
    $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/provider-refresh-worker.php'), $component, $role,
        (string) $test->refreshTeam->id, $test->refreshDirectory, $newerFails ? '1' : '0'], base_path(), [
            'APP_BASE_PATH' => base_path(), 'LARAVEL_STORAGE_PATH' => storage_path(), 'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_SOCKET' => $config['unix_socket'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'PICKSPORTS_TEST_MYSQL_DATABASE' => $config['database'], 'PICKSPORTS_TEST_MYSQL_SOCKET' => $config['unix_socket'],
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'NIGHTWATCH_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
        ]);
    $worker->setTimeout(25);
    $worker->start();
    $test->refreshWorkers[] = $worker;

    return $worker;
}

function assertRefreshWorkerIsolation($test, string $role): void
{
    $result = json_decode(file_get_contents($test->refreshDirectory.'/result-'.$role), true, flags: JSON_THROW_ON_ERROR);
    expect($result['private_paths_verified'])->toBeTrue()->and($result['base_path'])->toBe(base_path())
        ->and($result['storage_path'])->toBe(storage_path());
    foreach ($result['class_paths'] as $path) {
        expect(str_starts_with($path, base_path().'/'))->toBeTrue();
    }
}

it('fences an older remote acquisition behind newer success or failure on independent processes', function (string $component, bool $newerFails) {
    $older = startRefreshRace($this, $component, 'older', $newerFails);
    refreshRaceBarrier(fn () => is_file($this->refreshDirectory.'/acquired-older'));
    $newer = startRefreshRace($this, $component, 'newer', $newerFails);
    $newer->wait();
    expect($newer->isSuccessful())->toBeTrue();
    touch($this->refreshDirectory.'/release-older');
    $older->wait();
    expect($older->isSuccessful())->toBeTrue();
    assertRefreshWorkerIsolation($this, 'older');
    assertRefreshWorkerIsolation($this, 'newer');
    $head = ProviderRefreshHead::where('component', $component)->sole();
    $attempts = ProviderRefreshAttempt::where('head_id', $head->id)->orderBy('sequence')->get();
    expect($attempts->pluck('sequence')->all())->toBe([1, 2])
        ->and($attempts[0]->outcome)->toBe('superseded')
        ->and($attempts[1]->outcome)->toBe($newerFails ? ($component === 'injuries' ? 'unavailable' : 'failed') : 'applied')
        ->and($head->issued_sequence)->toBe(2)->and($head->latest_terminal_sequence)->toBe(2)
        ->and($head->latest_applied_sequence)->toBe($newerFails ? 0 : 2);
    if ($component === 'injuries') {
        expect(PlayerInjury::query()->sole()->status)->toBe($newerFails ? 'Out' : 'Doubtful')
            ->and(DB::table('nfl_player_injury_snapshots')->count())->toBe($newerFails ? 0 : 1);
    } else {
        expect((int) DepthChartEntry::query()->sole()->depth_rank)->toBe($newerFails ? 1 : 3)
            ->and(DB::table('nfl_depth_chart_snapshots')->count())->toBe($newerFails ? 0 : 1);
    }
})->with([['injuries', false], ['injuries', true], ['depth_chart', false], ['depth_chart', true]]);

it('serializes independent first reservations without duplicate or lost scope sequences', function () {
    $one = startRefreshRace($this, 'reserve', 'one');
    $two = startRefreshRace($this, 'reserve', 'two');
    refreshRaceBarrier(fn () => is_file($this->refreshDirectory.'/ready-one') && is_file($this->refreshDirectory.'/ready-two'));
    touch($this->refreshDirectory.'/go');
    $one->wait();
    $two->wait();
    expect($one->isSuccessful())->toBeTrue()->and($two->isSuccessful())->toBeTrue();
    assertRefreshWorkerIsolation($this, 'one');
    assertRefreshWorkerIsolation($this, 'two');
    $sequences = [json_decode(file_get_contents($this->refreshDirectory.'/result-one'), true, flags: JSON_THROW_ON_ERROR)['sequence'],
        json_decode(file_get_contents($this->refreshDirectory.'/result-two'), true, flags: JSON_THROW_ON_ERROR)['sequence']];
    sort($sequences);
    $head = ProviderRefreshHead::where('component', 'injuries')->sole();
    expect($sequences)->toBe([1, 2])->and($head->issued_sequence)->toBe(2)
        ->and($head->latest_terminal_sequence)->toBe(2)->and(ProviderRefreshAttempt::where('head_id', $head->id)->count())->toBe(2);
});

it('reserves and applies using current reads after an older repeatable-read head snapshot', function () {
    DB::beginTransaction();
    ProviderRefreshHead::where('component', 'injuries')->firstOrFail();
    $peer = app(ProviderRefreshCoordinator::class)->begin(Team::on('refresh_peer')->findOrFail($this->refreshTeam->id), 'injuries', 0);
    $current = app(ProviderRefreshCoordinator::class)->begin($this->refreshTeam, 'injuries', 0);
    expect($current->attempt->sequence)->toBe(2);
    $current->commit(true, [], fn () => 0);
    DB::commit();
    $peer->finish();
    $head = ProviderRefreshHead::where('component', 'injuries')->sole();
    expect($head->issued_sequence)->toBe(2)->and($head->latest_applied_sequence)->toBe(2)
        ->and(ProviderRefreshAttempt::where('outcome', 'applied')->count())->toBe(1);
});

it('round trips both fold instants and UTC audits across actual MySQL session zones', function (string $instant, string $utc) {
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse($instant));
    DB::statement("SET SESSION time_zone = '+09:00'");
    $team = Team::factory()->create(['espn_id' => '44']);
    enableSyntheticRefreshScope($team, 'injuries', 0);
    $context = app(ProviderRefreshCoordinator::class)->begin($team, 'injuries', 0);
    $raw = (array) DB::table('provider_refresh_attempts')->find($context->attempt->id);
    expect(substr($raw['started_at'], 0, 19))->toBe($utc)->and(substr($raw['created_at'], 0, 19))->toBe($utc);
    DB::statement("SET SESSION time_zone = '-05:00'");
    $changed = (array) DB::table('provider_refresh_attempts')->find($context->attempt->id);
    expect($changed)->toBe($raw)->and($context->attempt->fresh()->started_at->utc()->format('Y-m-d H:i:s'))->toBe($utc);
    $columns = DB::select("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type, DATETIME_PRECISION AS precision_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('provider_refresh_heads','provider_refresh_attempts') AND COLUMN_NAME IN ('activated_at','drained_at','started_at','expires_at','completed_at','created_at','updated_at')");
    expect($columns)->toHaveCount(9);
    foreach ($columns as $column) {
        expect($column->data_type)->toBe('datetime')->and((int) $column->precision_value)->toBe(6);
    }
    $constraints = DB::select("SELECT DELETE_RULE AS delete_rule FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('provider_refresh_heads','provider_refresh_attempts')");
    expect($constraints)->toHaveCount(5);
    foreach ($constraints as $constraint) {
        expect($constraint->delete_rule)->toBe('RESTRICT');
    }
})->with([['2026-11-01T01:30:00-05:00', '2026-11-01 06:30:00'], ['2026-11-01T01:30:00-06:00', '2026-11-01 07:30:00']]);
