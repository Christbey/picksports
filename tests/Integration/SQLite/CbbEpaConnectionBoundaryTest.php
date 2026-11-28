<?php

use App\Actions\CBB\CalculateGamePlayEpa;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\EpaStateBaseline;
use App\Services\NBA\TrueEpaCalculator;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function cbbOwnerFixture(): Game
{
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['espn_event_id' => '401000001', 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL', 'home_score' => 3, 'away_score' => 0, 'season' => 2027, 'period' => 2, 'game_clock' => '0:00']);
    foreach ([['start', 'Jumpball', null, 0, '20:00'], ['home', 'JumpShot', $home->id, 3, '1:00'],
        ['away', 'JumpShot', $away->id, 3, '0:01'], ['end', 'End Game', null, 3, '0:00']] as $i => [$id, $type, $team, $score, $clock]) {
        Play::factory()->create(['game_id' => $game->id, 'espn_play_id' => $id, 'sequence_number' => $i,
            'period' => $i === 0 ? 1 : 2, 'clock' => $clock, 'play_type' => $type, 'play_text' => $type,
            'possession_team_id' => $team, 'home_score' => $score, 'away_score' => 0, 'true_epa' => null, 'epa_calculated_at' => null]);
    }
    EpaStateBaseline::create(['sport' => 'cbb', 'season' => 2027, 'source_season' => 2026,
        'state_key' => 'H2|q1|1-5', 'expected_points' => 1.25, 'sample_size' => 12]);
    config(['epa.state_baseline.enabled' => true]);

    return $game;
}

/** Only synthetic fixture tables/rows; never a production copy or a real credential. */
function cbbOwnerPdoClone(PDO $source): PDO
{
    $target = new PDO('sqlite::memory:');
    $target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (['cbb_teams', 'cbb_games', 'cbb_plays', 'model_runs', 'epa_state_baselines'] as $table) {
        $sql = $source->query("select sql from sqlite_master where type='table' and name=".$source->quote($table))->fetchColumn();
        $target->exec($sql);
        foreach ($source->query('select * from "'.$table.'"')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns = implode(',', array_map(fn ($name) => '"'.str_replace('"', '""', $name).'"', array_keys($row)));
            $statement = $target->prepare('insert into "'.$table.'" ('.$columns.') values ('.implode(',', array_fill(0, count($row), '?')).')');
            $statement->execute(array_values($row));
        }
    }

    return $target;
}

it('does not follow a changed default connection into colliding game and baseline identities', function () {
    $game = cbbOwnerFixture();
    $owner = DB::connection();
    $default = DB::getDefaultConnection();
    $game->setConnection(null);
    expect($game->getConnectionName())->toBeNull()->and($game->getConnection())->toBe($owner);
    $other = cbbOwnerPdoClone($owner->getPdo());
    config(['database.connections.cbb_other' => config('database.connections.'.$default)]);
    $secondary = DB::connection('cbb_other');
    $secondary->setPdo($other)->setReadPdo($other);
    $crossed = false;
    $secondary->beforeExecuting(function (string $sql) use (&$crossed) {
        if (str_contains($sql, 'cbb_games')) {
            $crossed = true;
        }
    });
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real) {
        $result = $real->calculateForGame(...$arguments);
        DB::setDefaultConnection('cbb_other');

        return $result;
    });
    try {
        (new CalculateGamePlayEpa($mock))->execute($game);
        expect($crossed)->toBeFalse()
            ->and((int) $owner->table('model_runs')->where('run_type', 'play_epa')->count())->toBe(1)
            ->and((int) $other->query("select count(*) from model_runs where run_type='play_epa'")->fetchColumn())->toBe(0);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('cbb_other');
    }
});

it('refuses primary PDO replacement before persisting into a colliding independent database', function () {
    $game = cbbOwnerFixture();
    $connection = DB::connection();
    $original = $connection->getPdo();
    $originalRead = $connection->getReadPdo();
    $other = cbbOwnerPdoClone($original);
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real, $connection, $other) {
        $result = $real->calculateForGame(...$arguments);
        $connection->setPdo($other)->setReadPdo($other);

        return $result;
    });
    $failure = null;
    try {
        try {
            (new CalculateGamePlayEpa($mock))->execute($game);
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $otherWrites = (int) $other->query("select count(*) from model_runs where run_type='play_epa'")->fetchColumn();
        $otherEpa = (int) $other->query('select count(*) from cbb_plays where true_epa is not null')->fetchColumn();
        expect(['exception' => $failure === null ? null : $failure::class, 'other_receipts' => $otherWrites, 'other_epa_rows' => $otherEpa])
            ->toBe(['exception' => RuntimeException::class, 'other_receipts' => 0, 'other_epa_rows' => 0]);
    } finally {
        if ($original->inTransaction()) {
            $original->rollBack();
        }
        if ($other->inTransaction()) {
            $other->rollBack();
        }
        $connection->setPdo($original)->setReadPdo($originalRead);
    }
});

it('refuses a replacement manager Connection object before entering its independent source world', function () {
    $game = cbbOwnerFixture();
    $owner = DB::connection();
    $name = $owner->getName();
    $other = cbbOwnerPdoClone($owner->getPdo());
    $replacement = new SQLiteConnection($other, ':memory:', '', ['name' => $name]);
    $manager = DB::getFacadeRoot();
    $property = new ReflectionProperty($manager, 'connections');
    $originals = $property->getValue($manager);
    $crossed = false;
    $replacement->beforeExecuting(function () use (&$crossed) {
        $crossed = true;
    });
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real, $manager, $property, $name, $replacement) {
        $result = $real->calculateForGame(...$arguments);
        $connections = $property->getValue($manager);
        $connections[$name] = $replacement;
        $property->setValue($manager, $connections);

        return $result;
    });
    try {
        expect(fn () => (new CalculateGamePlayEpa($mock))->execute($game))->toThrow(RuntimeException::class, 'ownership changed');
        expect($crossed)->toBeFalse()->and($owner->getPdo()->inTransaction())->toBeFalse()
            ->and((int) $other->query("select count(*) from model_runs where run_type='play_epa'")->fetchColumn())->toBe(0);
    } finally {
        $property->setValue($manager, $originals);
    }
});

it('keeps the captured game identity when a caller mutates its model during calculation', function () {
    $game = cbbOwnerFixture();
    $id = $game->id;
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real, $game) {
        $result = $real->calculateForGame(...$arguments);
        $game->id = 999999;

        return $result;
    });
    expect((new CalculateGamePlayEpa($mock))->execute($game))->toBe(2)
        ->and(DB::table('model_runs')->where('run_type', 'play_epa')->value('metadata'))->toContain('"game_id":'.$id)
        ->and(Play::where('game_id', $id)->whereNotNull('true_epa')->count())->toBe(2);
});

it('does not roll back a replacement PDO transaction owned by another scope', function () {
    $game = cbbOwnerFixture();
    $connection = DB::connection();
    $original = $connection->getPdo();
    $originalRead = $connection->getReadPdo();
    $other = cbbOwnerPdoClone($original);
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real, $connection, $other) {
        $result = $real->calculateForGame(...$arguments);
        $other->beginTransaction();
        $connection->setPdo($other)->setReadPdo($other);

        return $result;
    });
    try {
        expect(fn () => (new CalculateGamePlayEpa($mock))->execute($game))->toThrow(RuntimeException::class, 'ownership changed');
        expect($original->inTransaction())->toBeFalse()->and($other->inTransaction())->toBeTrue()
            ->and((int) $other->query("select count(*) from model_runs where run_type='play_epa'")->fetchColumn())->toBe(0);
    } finally {
        if ($original->inTransaction()) {
            $original->rollBack();
        }
        if ($other->inTransaction()) {
            $other->rollBack();
        }
        $connection->setPdo($original)->setReadPdo($originalRead);
    }
});

it('cleans only its savepoint when an inherited transaction loses its PDO reference', function () {
    $game = cbbOwnerFixture();
    $connection = DB::connection();
    $original = $connection->getPdo();
    $originalRead = $connection->getReadPdo();
    $other = cbbOwnerPdoClone($original);
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($real, $connection, $other) {
        $result = $real->calculateForGame(...$arguments);
        $connection->setPdo($other)->setReadPdo($other);

        return $result;
    });
    $connection->beginTransaction();
    try {
        expect(fn () => (new CalculateGamePlayEpa($mock))->execute($game))->toThrow(RuntimeException::class, 'ownership changed');
        expect($original->inTransaction())->toBeTrue()->and($other->inTransaction())->toBeFalse()
            ->and((int) $original->query("select count(*) from model_runs where run_type='play_epa'")->fetchColumn())->toBe(0)
            ->and((int) $other->query("select count(*) from model_runs where run_type='play_epa'")->fetchColumn())->toBe(0);
    } finally {
        if ($original->inTransaction()) {
            $original->rollBack();
        }
        if ($other->inTransaction()) {
            $other->rollBack();
        }
        $connection->setPdo($original)->setReadPdo($originalRead);
    }
});
