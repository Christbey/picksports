<?php

namespace Tests\Support;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

/** Explicit disposable-fixture setup, never an application session repair. */
final class EloClockFixture
{
    public static function prepare(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }
        TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(),
            Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
        $connection->getPdo()->exec("SET SESSION time_zone = '+00:00'");
        if ($connection->getReadPdo() !== $connection->getPdo()) {
            $connection->getReadPdo()->exec("SET SESSION time_zone = '+00:00'");
        }
    }
}
