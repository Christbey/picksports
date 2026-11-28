<?php

use App\Actions\CBB\CalculateGamePlayEpa;
use App\Actions\CBB\RefreshTeamPlayEpa;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;
use App\Models\EpaStateBaseline;
use App\Models\ModelRun;
use App\Services\CBB\ObservedTerminalEpaContext;
use App\Services\CBB\TeamMetricSample;
use App\Services\NBA\TrueEpaCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    $this->travelTo('2026-11-27T12:00:00Z');
    Http::preventStrayRequests();
    $this->ownerName = DB::getDefaultConnection();
    config(['database.connections.cbb_native_peer' => config('database.connections.'.$this->ownerName)]);
    foreach ([$this->ownerName, 'cbb_native_peer'] as $name) {
        DB::connection($name)->statement("SET SESSION time_zone = '+00:00'");
        DB::connection($name)->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::connection($name)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }
    expect(DB::connection()->getPdo())->not->toBe(DB::connection('cbb_native_peer')->getPdo());
});

afterEach(function () {
    DB::setDefaultConnection($this->ownerName);
    foreach ([$this->ownerName, 'cbb_native_peer'] as $name) {
        while (DB::connection($name)->transactionLevel() > 0) {
            DB::connection($name)->rollBack();
        }
    }
    DB::purge('cbb_native_peer');
    $this->travelBack();
});

function cbbNativeSource(): array
{
    $home = Team::factory()->create(['espn_id' => '500001', 'abbreviation' => 'CSH']);
    $away = Team::factory()->create(['espn_id' => '500002', 'abbreviation' => 'CSA']);
    $game = Game::factory()->create(['espn_event_id' => '401000001', 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'season_type' => 2, 'game_date' => '2026-11-26', 'status' => 'STATUS_FINAL',
        'home_score' => 3, 'away_score' => 0, 'period' => 2, 'game_clock' => '0:00']);
    foreach ([$home, $away] as $team) {
        TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $team->id, 'points' => $team->id === $home->id ? 3 : 0,
            'field_goals_made' => $team->id === $home->id ? 1 : 0, 'field_goals_attempted' => 3,
            'three_point_made' => $team->id === $home->id ? 1 : 0, 'three_point_attempted' => 1,
            'free_throws_made' => 0, 'free_throws_attempted' => 0, 'possessions' => 10]);
    }
    foreach ([['start', 'Jumpball', null, 0, '20:00'], ['home', 'JumpShot', $home->id, 3, '1:00'],
        ['away', 'JumpShot', $away->id, 3, '0:01'], ['end', 'End Game', null, 3, '0:00']] as $i => [$id, $type, $team, $score, $clock]) {
        Play::factory()->create(['game_id' => $game->id, 'espn_play_id' => $id, 'sequence_number' => $i,
            'period' => $i === 0 ? 1 : 2, 'clock' => $clock, 'play_type' => $type, 'play_text' => $type,
            'possession_team_id' => $team, 'home_score' => $score, 'away_score' => 0,
            'true_epa' => null, 'epa_calculated_at' => null]);
    }
    $baseline = EpaStateBaseline::create(['sport' => 'cbb', 'season' => 2027, 'source_season' => 2026,
        'state_key' => 'H2|q1|1-5', 'expected_points' => 1.25, 'sample_size' => 12]);
    config(['epa.state_baseline.enabled' => true]);
    $metric = TeamMetric::factory()->create(['team_id' => $home->id, 'season' => 2027, 'games_played' => 1]);

    return compact('game', 'home', 'away', 'baseline', 'metric');
}

function cbbNativeBlocked(Closure $write): void
{
    $peer = DB::connection('cbb_native_peer');
    $peer->beginTransaction();
    $failure = null;
    try {
        $write($peer);
    } catch (QueryException $exception) {
        $failure = $exception;
    } finally {
        $peer->rollBack();
    }
    expect($failure)->toBeInstanceOf(QueryException::class)->and((int) ($failure?->errorInfo[1] ?? 0))->toBe(1205);
}

it('uses current native sources through an older RR view and publishes a genuine complete aggregate', function () {
    $f = cbbNativeSource();
    $game = $f['game'];
    $connection = DB::connection();
    $peer = DB::connection('cbb_native_peer');
    $connection->beginTransaction();
    try {
        expect($connection->table('cbb_games')->where('id', $game->id)->value('home_score'))->toBe(3);
        $peer->transaction(function () use ($peer, $f, $game) {
            $peer->table('cbb_games')->where('id', $game->id)->update(['home_score' => 4]);
            $peer->table('cbb_plays')->where('game_id', $game->id)->where('espn_play_id', '!=', 'start')->update(['home_score' => 4]);
            $peer->table('cbb_team_stats')->where('game_id', $game->id)->where('team_id', $f['home']->id)->update(['points' => 4, 'field_goals_made' => 2, 'three_point_made' => 0]);
        });
        expect($connection->table('cbb_games')->where('id', $game->id)->value('home_score'))->toBe(3);
        expect(app(CalculateGamePlayEpa::class)->execute($game))->toBe(2);
        $current = Game::query()->useWritePdo()->whereKey($game->id)->lockForUpdate()->firstOrFail();
        $plays = Play::query()->useWritePdo()->where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->lockForUpdate()->get();
        expect($current->home_score)->toBe(4)->and(ObservedTerminalEpaContext::capture($current, $plays))->not->toBeNull();
        $currentReceipt = ModelRun::query()->useWritePdo()->where('run_type', 'play_epa')->lockForUpdate()->sole();
        expect($currentReceipt->metadata['source_hash'])->toBe(ObservedTerminalEpaContext::sourceHash($current, $plays, ['H2|q1|1-5' => 1.25], $currentReceipt->metadata['baseline_source_hash']));
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        } throw $exception;
    }
    expect($connection->getReadPdo())->toBe($connection->getPdo());
    expect(app(RefreshTeamPlayEpa::class)->execute($f['home'], 2027))->toBeTrue()->and($f['metric']->fresh()->net_true_epa_per_play)->not->toBeNull();
    $receipt = ModelRun::where('run_type', 'play_epa')->sole();
    expect($receipt->metadata['terminal_authorized'])->toBeTrue()->and($receipt->metadata['baseline_source_hash'])->toHaveLength(64);
    $bytes = $receipt->getAttributes();
    expect($receipt->save())->toBeTrue()->and($receipt->fresh()->getAttributes())->toBe($bytes);
    expect(fn () => $receipt->updateQuietly(['metadata' => []]))->toThrow(LogicException::class);
});

it('blocks actual play and baseline writers then sees retry provenance and a fresh-primary pending hold', function () {
    $f = cbbNativeSource();
    $game = $f['game'];
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real, $game, $f) {
        cbbNativeBlocked(fn ($peer) => $peer->table('cbb_plays')->where('game_id', $game->id)->where('espn_play_id', 'home')->update(['play_text' => 'Observed shooting correction']));
        cbbNativeBlocked(fn ($peer) => $peer->table('epa_state_baselines')->insert(['sport' => 'cbb', 'season' => 2027, 'source_season' => 2026, 'state_key' => 'unused-observed-state', 'expected_points' => 0, 'sample_size' => 1]));
        cbbNativeBlocked(fn ($peer) => $peer->table('epa_state_baselines')->where('id', $f['baseline']->id)->increment('sample_size'));

        return $real->calculateForGame(...$arguments);
    });
    expect((new CalculateGamePlayEpa($mock))->execute($game))->toBe(2);
    $first = ModelRun::where('run_type', 'play_epa')->sole();
    $values = Play::where('game_id', $game->id)->orderBy('id')->pluck('true_epa', 'id')->all();
    $peer = DB::connection('cbb_native_peer');
    expect($peer->table('cbb_plays')->where('game_id', $game->id)->where('espn_play_id', 'home')->update(['play_text' => 'Observed shooting correction']))->toBe(1);
    expect($peer->table('epa_state_baselines')->insert(['sport' => 'cbb', 'season' => 2027, 'source_season' => 2026, 'state_key' => 'unused-observed-state', 'expected_points' => 0, 'sample_size' => 1]))->toBeTrue();
    expect($peer->table('epa_state_baselines')->where('id', $f['baseline']->id)->increment('sample_size'))->toBe(1);
    app(CalculateGamePlayEpa::class)->execute($game);
    $second = ModelRun::where('run_type', 'play_epa')->where('id', '!=', $first->id)->sole();
    expect($second->metadata['source_hash'])->not->toBe($first->metadata['source_hash'])->and($second->metadata['baseline_source_hash'])->not->toBe($first->metadata['baseline_source_hash'])
        ->and(Play::where('game_id', $game->id)->orderBy('id')->pluck('true_epa', 'id')->all())->toBe($values);
    $peer->table('cbb_plays')->insert(['game_id' => $game->id, 'espn_play_id' => 'pending', 'sequence_number' => 4, 'period' => 2, 'clock' => '0:00',
        'play_type' => 'Lost Ball Turnover', 'play_text' => 'Pending observed transition', 'possession_team_id' => $f['home']->id, 'home_score' => 3, 'away_score' => 0]);
    $sample = app(TeamMetricSample::class);
    expect($sample->missingEpaGameIds($sample->forTeam($f['home'], 2027)))->toBe([$game->id]);
    expect(app(RefreshTeamPlayEpa::class)->execute($f['home'], 2027))->toBeFalse()->and($f['metric']->fresh()->net_true_epa_per_play)->toBeNull();
    $beforeIds = ModelRun::where('run_type', 'play_epa')->pluck('id');
    app(CalculateGamePlayEpa::class)->execute($game);
    expect(ModelRun::where('run_type', 'play_epa')->whereNotIn('id', $beforeIds)->sole()->metadata['terminal_authorized'])->toBeFalse();
});

it('refuses current body growth after stale early hints without hydrating the oversized field', function () {
    $f = cbbNativeSource();
    $game = $f['game'];
    $connection = DB::connection();
    $peer = DB::connection('cbb_native_peer');
    $changed = false;
    $connection->beforeExecuting(function (string $sql) use (&$changed, $peer, $game) {
        if (! $changed && str_contains($sql, 'cbb_plays') && str_contains($sql, 'source_oversized') && str_contains(strtolower($sql), 'for update')) {
            $changed = true;
            $peer->table('cbb_plays')->where('game_id', $game->id)->where('espn_play_id', 'home')->update(['play_text' => str_repeat('x', 2049)]);
        }
    });
    expect(fn () => app(CalculateGamePlayEpa::class)->execute($game))->toThrow(RuntimeException::class, 'projection policy');
    expect($changed)->toBeTrue()->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(0)->and(Play::where('game_id', $game->id)->whereNotNull('true_epa')->count())->toBe(0);
    $row = Play::query()->useWritePdo()->select(ObservedTerminalEpaContext::projection($connection, ObservedTerminalEpaContext::SOURCE_FIELDS))->where('game_id', $game->id)->where('espn_play_id', 'home')->firstOrFail();
    expect($row->play_text)->toBeNull()->and((int) $row->source_oversized)->toBe(1);
});

it('rolls back changed source and a confirmed inserted receipt when dependent persistence fails', function () {
    $f = cbbNativeSource();
    $game = $f['game'];
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real, $f) {
        DB::table('epa_state_baselines')->where('id', $f['baseline']->id)->increment('sample_size');

        return $real->calculateForGame(...$arguments);
    });
    expect(fn () => (new CalculateGamePlayEpa($mock))->execute($game))->toThrow(RuntimeException::class, 'source changed');
    expect($f['baseline']->fresh()->sample_size)->toBe(12)->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(0);
    $confirmed = false;
    $pdo = DB::connection()->getPdo();
    DB::connection()->beforeExecuting(function (string $sql) use (&$confirmed, $pdo) {
        if (str_contains(strtolower($sql), 'insert into `cbb_plays`')) {
            $confirmed = (int) $pdo->query("select count(*) from model_runs where run_type='play_epa'")->fetchColumn() === 1;
            throw new RuntimeException('Synthetic dependent EPA write failure.');
        }
    });
    expect(fn () => app(CalculateGamePlayEpa::class)->execute($game))->toThrow(RuntimeException::class, 'Synthetic dependent');
    expect($confirmed)->toBeTrue()->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(0)->and(Play::where('game_id', $game->id)->whereNotNull('true_epa')->count())->toBe(0);
});

it('pins native PDO ownership and cleans only the original root or savepoint scope', function () {
    $f = cbbNativeSource();
    $game = $f['game'];
    $owner = DB::connection();
    $original = $owner->getPdo();
    $read = $owner->getReadPdo();
    $peer = DB::connection('cbb_native_peer');
    $replacement = $peer->getPdo();
    foreach ([false, true] as $inherited) {
        if ($inherited) {
            $owner->beginTransaction();
        }
        $real = app(TrueEpaCalculator::class);
        $mock = Mockery::mock(TrueEpaCalculator::class);
        $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real, $owner, $replacement) {
            $result = $real->calculateForGame(...$arguments);
            $replacement->beginTransaction();
            $owner->setPdo($replacement)->setReadPdo($replacement);

            return $result;
        });
        try {
            expect(fn () => (new CalculateGamePlayEpa($mock))->execute($game))->toThrow(RuntimeException::class, 'ownership changed');
            expect($original->inTransaction())->toBe($inherited)->and($replacement->inTransaction())->toBeTrue();
            if (! $inherited) {
                expect((int) $replacement->query('select id from cbb_games where id='.(int) $game->id.' for update')->fetchColumn())->toBe($game->id);
            }
            expect((int) $replacement->query("select count(*) from model_runs where run_type='play_epa'")->fetchColumn())->toBe(0);
        } finally {
            if ($replacement->inTransaction()) {
                $replacement->rollBack();
            }
            if ($original->inTransaction()) {
                $original->rollBack();
            }
            $owner->setPdo($original)->setReadPdo($read);
        }
    }
    expect($owner->transactionLevel())->toBe(0)->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(0);
});
