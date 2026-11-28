<?php

use App\Models\NFL\Team;
use App\Services\ProviderData\Refresh\ProviderRefreshCoordinator;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/ProviderRefreshFixture.php';

it('refuses disabled activated refresh even when a read replica lacks the new schema', function (bool $primeSchema) {
    $team = Team::factory()->create(['espn_id' => '22']);
    $connection = $team->getConnection();
    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Independent SQLite PDO metadata seam; native current-read controls are separate.');
    }
    enableSyntheticRefreshScope($team, 'injuries', 0);
    config(['provider_refresh.enabled' => false]);
    config(['database.connections.refresh_metadata_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $probe = DB::connection('refresh_metadata_probe');
    $write = new PDO('sqlite::memory:');
    $write->exec('CREATE TABLE nfl_teams (id INTEGER PRIMARY KEY, espn_id TEXT)');
    $write->exec('CREATE TABLE provider_refresh_heads (id INTEGER PRIMARY KEY, provider TEXT, sport TEXT, component TEXT, team_id INTEGER, season_key INTEGER)');
    $statement = $write->prepare('INSERT INTO nfl_teams VALUES (?, ?)');
    $statement->execute([$team->id, '22']);
    $statement = $write->prepare('INSERT INTO provider_refresh_heads VALUES (1, ?, ?, ?, ?, 0)');
    $statement->execute(['espn', 'nfl', 'injuries', $team->id]);
    $probe->setPdo($write);
    $probe->setReadPdo(new PDO('sqlite::memory:'));
    $team->setConnection('refresh_metadata_probe');
    try {
        if ($primeSchema) {
            expect($probe->getSchemaBuilder()->hasTable('provider_refresh_heads'))->toBeFalse();
        } else {
            expect($probe->getSchemaGrammar())->toBeNull();
        }
        expect(fn () => app(ProviderRefreshCoordinator::class)->begin($team, 'injuries', 0))
            ->toThrow(RuntimeException::class, 'Activated refresh scope cannot fall back');
    } finally {
        DB::purge('refresh_metadata_probe');
    }
})->with([true, false]);
