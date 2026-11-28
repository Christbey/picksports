<?php

use App\Actions\NFL\CalculateElo;
use App\Models\MLB\Player;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportEloApplication;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Models\SportEloWriterGate;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSeasonRollover;
use App\Services\Sports\Elo\EloSourceContext;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriterProtocol;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Illuminate\Database\QueryException;
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

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Coordinated Elo races require explicitly selected disposable MySQL.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    config(['database.connections.elo_writer_peer' => config('database.connections.mysql'), 'elo_integrity.enabled' => true]);
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return $sport === 'nfl' && $protocol === 1;
        }
    });
    $definition = app(EloSportRegistry::class)->resolve('nfl', 'team');
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'admitting', 'calculation_version' => $definition['version'],
        'configuration_hash' => $definition['configuration_hash'], 'drain_reference' => 'synthetic-mysql-drain', 'drain_verified_at' => now()->subMinute()]);
    $this->eloHome = Team::factory()->create(['elo_rating' => 1500]);
    $this->eloAway = Team::factory()->create(['elo_rating' => 1500]);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('nfl', 2026, 'team', [$this->eloHome->id, $this->eloAway->id]);
    $admission->apply('nfl', 2026, 'team', [$this->eloHome->id, $this->eloAway->id], $report['fingerprint'], 'synthetic-reviewed');
    config(['elo_integrity.write_target_fingerprints.nfl' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'active']);
    $this->eloFirst = Game::factory()->create(['home_team_id' => $this->eloHome->id, 'away_team_id' => $this->eloAway->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '18:00:00', 'home_score' => 20, 'away_score' => 10]);
    $this->eloDirectory = sys_get_temp_dir().'/picksports-elo-race-'.bin2hex(random_bytes(8));
    mkdir($this->eloDirectory, 0700);
    $this->eloWorkers = [];
});

afterEach(function () {
    foreach ($this->eloWorkers ?? [] as $worker) {
        $worker->stop(0);
    }
    foreach ([DB::connection(), DB::connection('elo_writer_peer')] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('elo_writer_peer');
    if (isset($this->eloDirectory)) {
        File::deleteDirectory($this->eloDirectory);
    }
});

function eloRaceWait(Closure $condition): void
{
    $deadline = microtime(true) + 15;
    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Elo race barrier deadline exceeded.');
        }
        usleep(10000);
    }
}

function eloRaceStart($test, string $mode, int $id, string $label): Process
{
    $config = config('database.connections.mysql');
    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/elo-coordinated-worker.php'), $mode, (string) $id, $test->eloDirectory, $label], base_path(), [
        'APP_BASE_PATH' => base_path(), 'LARAVEL_STORAGE_PATH' => storage_path(),
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
        'DB_SOCKET' => $config['unix_socket'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
        'PICKSPORTS_TEST_MYSQL_DATABASE' => $config['database'], 'PICKSPORTS_TEST_MYSQL_SOCKET' => $config['unix_socket'],
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
        'NIGHTWATCH_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
    ]);
    $process->setTimeout(25);
    $process->start();
    $test->eloWorkers[] = $process;

    return $process;
}

it('refuses a previously unknown current predecessor behind an old repeatable-read head snapshot', function () {
    $next = Game::factory()->create(['home_team_id' => $this->eloHome->id, 'away_team_id' => $this->eloAway->id,
        'season' => 2026, 'week' => 2, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-08', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
    DB::beginTransaction();
    SportEloEntityHead::get();
    (new CalculateElo)->execute(Game::on('elo_writer_peer')->findOrFail($this->eloFirst->id));
    expect(fn () => (new CalculateElo)->execute($next))->toThrow(RuntimeException::class, 'planned locked source set');
    DB::rollBack();
    expect(SportEloApplication::count())->toBe(1)->and(EloRating::count())->toBe(2)
        ->and(SportEloEntityHead::where('last_game_id', $this->eloFirst->id)->count())->toBe(2);
});

it('detects a corrected predecessor committed after an older source snapshot', function () {
    (new CalculateElo)->execute($this->eloFirst);
    $next = Game::factory()->create(['home_team_id' => $this->eloHome->id, 'away_team_id' => $this->eloAway->id,
        'season' => 2026, 'week' => 2, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-08', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
    DB::beginTransaction();
    Game::whereKey($this->eloFirst->id)->first();
    DB::connection('elo_writer_peer')->table('nfl_games')->where('id', $this->eloFirst->id)->update(['away_score' => 40]);
    expect(fn () => (new CalculateElo)->execute($next))->toThrow(RuntimeException::class, 'predecessor');
    DB::rollBack();
    expect(SportEloApplication::count())->toBe(1)->and(EloRating::count())->toBe(2);
});

it('serializes two independent applications of one game without duplicate history or a lost update', function () {
    $one = eloRaceStart($this, 'apply', $this->eloFirst->id, 'one');
    $two = eloRaceStart($this, 'apply', $this->eloFirst->id, 'two');
    eloRaceWait(fn () => is_file($this->eloDirectory.'/ready-one') && is_file($this->eloDirectory.'/ready-two'));
    touch($this->eloDirectory.'/go');
    $one->wait();
    $two->wait();
    expect($one->isSuccessful())->toBeTrue()->and($two->isSuccessful())->toBeTrue();
    $results = [json_decode(file_get_contents($this->eloDirectory.'/result-one'), true), json_decode(file_get_contents($this->eloDirectory.'/result-two'), true)];
    expect(array_column($results, 'status'))->toBe(['succeeded', 'succeeded']);
    $skipped = array_column($results, 'skipped');
    sort($skipped);
    expect($skipped)->toBe([false, true])->and(SportEloApplication::count())->toBe(1)->and(EloRating::count())->toBe(2)
        ->and(SportEloEntityHead::where('generation', 2)->count())->toBe(2);
});

it('serializes rollover and a new-season game with a bounded explicit retry when rollover loses admission order', function () {
    // Prior-season facts must be receipted before this valid rollover/game race.
    (new CalculateElo)->execute($this->eloFirst);
    $next = Game::factory()->create(['home_team_id' => $this->eloHome->id, 'away_team_id' => $this->eloAway->id,
        'season' => 2027, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2027-10-01', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
    $rollover = eloRaceStart($this, 'rollover', 2027, 'rollover');
    $game = eloRaceStart($this, 'apply', $next->id, 'game');
    eloRaceWait(fn () => is_file($this->eloDirectory.'/ready-rollover') && is_file($this->eloDirectory.'/ready-game'));
    touch($this->eloDirectory.'/go');
    $rollover->wait();
    $game->wait();
    expect($rollover->isSuccessful())->toBeTrue()->and($game->isSuccessful())->toBeTrue();
    $result = json_decode(file_get_contents($this->eloDirectory.'/result-rollover'), true);
    expect($result['status'])->toBe('succeeded');
    (new CalculateElo)->execute($next);
    expect(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(2)
        ->and(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(6)
        ->and(SportEloEntityHead::where('season', 2027)->where('last_game_id', $next->id)->count())->toBe(2);
});

it('holds the current final-event range against a newly inserted earlier game before team locks', function () {
    $connection = DB::connection();
    $definition = app(EloSportRegistry::class)->resolve('nfl', 'team');
    $source = app(EloSourceContext::class);
    $connection->beginTransaction();
    app(EloWriterProtocol::class)->lockGate($connection, 'nfl', $definition);
    $source->beginObservation();
    $source->lockGames($connection, 'nfl', $definition, ['team' => [$this->eloHome->id, $this->eloAway->id]], [$this->eloFirst->id], 2026, [2026]);
    $peer = DB::connection('elo_writer_peer');
    $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
    $row = ['espn_event_id' => 'synthetic-range-insert', 'season' => 2026, 'week' => 1, 'season_type' => 2,
        'status' => 'STATUS_FINAL', 'game_date' => '2026-09-01', 'game_time' => '12:00:00',
        'home_team_id' => $this->eloHome->id, 'away_team_id' => $this->eloAway->id, 'home_score' => 0, 'away_score' => 0];
    try {
        $peer->table('nfl_games')->insert($row);
        $this->fail('A new prior final escaped the locked source range.');
    } catch (QueryException $exception) {
        expect((int) $exception->errorInfo[1])->toBe(1205);
    } finally {
        $connection->rollBack();
    }
    expect($peer->table('nfl_games')->insert($row))->toBeTrue()
        ->and(SportEloApplication::count())->toBe(0)->and(EloRating::count())->toBe(0);
});

it('fences newly discovered historical pitcher participation using the season game range before entity locks', function () {
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return in_array($sport, ['nfl', 'mlb'], true) && $protocol === 1;
        }
    });
    $teams = collect(range(1, 4))->map(fn () => App\Models\MLB\Team::factory()->create(['elo_rating' => 1500]));
    $first = App\Models\MLB\Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-01', 'game_time' => '18:00:00', 'home_score' => 1, 'away_score' => 0]);
    $other = App\Models\MLB\Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $teams[2]->id, 'away_team_id' => $teams[3]->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-09-01', 'game_time' => '18:00:00', 'home_score' => 1, 'away_score' => 0]);
    $pitcher = Player::factory()->pitcher()->create(['team_id' => $teams[0]->id]);
    $definition = app(EloSportRegistry::class)->resolve('mlb', 'team');
    SportEloWriterGate::whereKey('mlb')->update(['mode' => 'active', 'calculation_version' => $definition['version'],
        'configuration_hash' => $definition['configuration_hash'], 'drain_reference' => 'synthetic-source-range-drain', 'drain_verified_at' => now()->subMinute()]);
    config(['elo_integrity.write_target_fingerprints.mlb' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    $connection = DB::connection();
    $connection->beginTransaction();
    app(EloWriterProtocol::class)->lockGate($connection, 'mlb', $definition);
    $source = app(EloSourceContext::class);
    $source->beginObservation();
    $source->lockGames($connection, 'mlb', $definition, ['team' => [$teams[0]->id, $teams[1]->id], 'pitcher' => [$pitcher->id]], [$first->id], 2026, [2026]);
    $peer = DB::connection('elo_writer_peer');
    $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
    $row = ['game_id' => $other->id, 'team_id' => $teams[2]->id, 'player_id' => $pitcher->id, 'stat_type' => 'pitching', 'innings_pitched' => '1.0'];
    try {
        $peer->table('mlb_player_stats')->insert($row);
        $this->fail('New historical pitcher participation escaped the locked final-game range.');
    } catch (QueryException $exception) {
        expect((int) $exception->errorInfo[1])->toBe(1205);
    } finally {
        $connection->rollBack();
    }
    expect($peer->table('mlb_player_stats')->insert($row))->toBeTrue()
        ->and(SportEloApplication::where('sport', 'mlb')->count())->toBe(0)
        ->and(App\Models\MLB\EloRating::count())->toBe(0);
});

it('uses current rollover values after a projection repair behind an old entity snapshot', function () {
    (new CalculateElo)->execute($this->eloFirst);
    $headRating = SportEloEntityHead::where('entity_type', 'team')->where('entity_id', $this->eloHome->id)->value('rating');
    DB::table('nfl_teams')->where('id', $this->eloHome->id)->update(['elo_rating' => 999]);
    DB::beginTransaction();
    DB::table('nfl_teams')->where('id', $this->eloHome->id)->first();
    DB::connection('elo_writer_peer')->table('nfl_teams')->where('id', $this->eloHome->id)->update(['elo_rating' => (float) $headRating]);
    $result = app(EloSeasonRollover::class)->execute('nfl', 2027, ['team' => '0.3']);
    DB::commit();
    $receipt = SportEloSeasonInitialization::where(['sport' => 'nfl', 'entity_type' => 'team', 'entity_id' => $this->eloHome->id,
        'season' => 2027, 'kind' => 'season_rollover'])->sole();
    expect($result['changed_entities'])->toBe(2)->and($receipt->rating_before)->toBe(number_format((float) $headRating, 4, '.', ''))
        ->and(SportEloEntityHead::where('season', 2027)->count())->toBe(2);
});
