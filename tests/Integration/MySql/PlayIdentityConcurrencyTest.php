<?php

namespace Tests\Integration\MySql;

use App\Actions\ESPN\CFB\SyncPlays;
use App\Models\NBA\Game;
use App\Models\NBA\Play;
use App\Models\NBA\Team;
use App\Services\ESPN\CFB\EspnService;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlayIdentityConcurrencyTest extends TestCase
{
    private string $directory;

    private ?Process $worker = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->directory = sys_get_temp_dir().'/picksports-play-lock-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->worker?->isRunning()) {
            $this->worker->stop(0, 9);
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_game_change_committed_while_worker_waits_for_lock_preserves_prior_rows(): void
    {
        $this->race('game');
    }

    public function test_team_binding_change_committed_while_worker_waits_preserves_prior_rows(): void
    {
        $this->race('team');
    }

    private function race(string $change): void
    {
        $home = Team::factory()->create(['espn_id' => '501']);
        $away = Team::factory()->create(['espn_id' => '502']);
        $replacement = Team::factory()->create(['espn_id' => '503']);
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
        $old = Play::factory()->create(['game_id' => $game->id]);
        $before = $old->fresh()->getRawOriginal();
        DB::beginTransaction();
        DB::table('nba_games')->where('id', $game->id)->lockForUpdate()->first();
        $this->startWorker($game, $home);
        $this->waitFor('captured');
        touch($this->directory.'/release');
        $this->waitFor('locking');
        usleep(150000);
        $this->assertTrue($this->worker->isRunning(), 'Worker must wait for the held game lock.');
        if ($change === 'game') {
            DB::table('nba_games')->where('id', $game->id)->update(['away_team_id' => $replacement->id]);
        } else {
            DB::table('nba_teams')->where('id', $home->id)->update(['espn_id' => 'changed-binding']);
        }
        DB::commit();
        $this->worker->wait();
        $this->assertSame(0, $this->worker->getExitCode(), $this->worker->getErrorOutput());
        $result = json_decode($this->worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($change === 'game' ? 'game_changed' : 'team_changed', $result['status']);
        $this->assertSame(base_path(), $result['base']);
        $this->assertSame(storage_path(), $result['storage']);
        $this->assertSame(base_path('app/Actions/ESPN/NBA/SyncPlays.php'), $result['source']);
        $this->assertSame($before, $old->fresh()->getRawOriginal());
        $this->assertSame(1, Play::where('game_id', $game->id)->count());
    }

    public function test_cfb_opaque_case_distinct_ids_are_never_aliased_by_mysql_collation(): void
    {
        Storage::fake('local');
        config(['cfb.data.source_disk' => 'local']);
        $home = \App\Models\CFB\Team::factory()->create(['espn_id' => '601']);
        $away = \App\Models\CFB\Team::factory()->create(['espn_id' => '602']);
        $game = \App\Models\CFB\Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_IN_PROGRESS']);
        $raw = ['period' => ['number' => 1], 'clock' => ['displayValue' => '10:00'], 'text' => 'Synthetic play', 'homeScore' => 0, 'awayScore' => 0, 'start' => ['team' => ['id' => '601'], 'down' => 1, 'distance' => 10, 'yardsToEndzone' => 75]];
        $service = \Mockery::mock(EspnService::class);
        $service->shouldReceive('getPlays')->once()->andReturn(['count' => 2, 'items' => [[...$raw, 'id' => 'Case-A'], [...$raw, 'id' => 'case-a']]]);
        $this->assertSame(2, (new SyncPlays($service))->execute($game->espn_event_id));
        $first = \App\Models\CFB\Play::where('game_id', $game->id)->orderBy('sequence_number')->get();
        $this->assertSame(['Case-A', 'case-a'], $first->pluck('espn_play_id')->all());
        $secondId = $first[1]->id;
        $service = \Mockery::mock(EspnService::class);
        $service->shouldReceive('getPlays')->once()->andReturn(['count' => 1, 'items' => [[...$raw, 'id' => 'case-a']]]);
        $this->assertSame(1, (new SyncPlays($service))->execute($game->espn_event_id));
        $remaining = \App\Models\CFB\Play::where('game_id', $game->id)->sole();
        $this->assertSame($secondId, $remaining->id);
        $this->assertSame('case-a', $remaining->espn_play_id);
        Http::assertNothingSent();
    }

    private function startWorker(Game $game, Team $home): void
    {
        $connection = config('database.connections.mysql');
        $this->worker = new Process([PHP_BINARY, base_path('tests/Fixtures/play-identity-concurrency-worker.php'), $game->espn_event_id, $home->espn_id, $this->directory], base_path(), [
            'APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path(), 'LARAVEL_STORAGE_PATH' => storage_path(), 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_SOCKET' => $connection['unix_socket'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'PICKSPORTS_TEST_MYSQL_DATABASE' => Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), 'PICKSPORTS_TEST_MYSQL_SOCKET' => Env::get('PICKSPORTS_TEST_MYSQL_SOCKET') ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false',
        ]);
        $this->worker->setTimeout(25);
        $this->worker->start();
    }

    private function waitFor(string $file): void
    {
        $deadline = microtime(true) + 12;
        while (! is_file($this->directory.'/'.$file)) {
            $this->worker->checkTimeout();
            if (! $this->worker->isRunning() || microtime(true) > $deadline) {
                $this->fail('Worker barrier failure: '.$file.' '.$this->worker->getErrorOutput());
            }
            usleep(10000);
        }
    }
}
