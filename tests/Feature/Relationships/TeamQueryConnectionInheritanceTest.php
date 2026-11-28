<?php

use App\Models\CBB\Team;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->originalConnection = config('database.default');
    foreach (['team_query_poison', 'team_query_selected'] as $name) {
        config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge($name);
    }
    // Deliberate isolated synthetic databases, configured after the initial test-target guard.
    config(['database.default' => 'team_query_poison']);
});

afterEach(function () {
    config(['database.default' => $this->originalConnection]);
    foreach (['team_query_poison', 'team_query_selected'] as $name) {
        DB::purge($name);
    }
});

function seedTeamQueryConnection(string $sport, string $connection, int $base): void
{
    $schema = DB::connection($connection)->getSchemaBuilder();
    $schema->create("{$sport}_teams", function (Blueprint $table) {
        $table->unsignedBigInteger('id')->primary();
    });
    $schema->create("{$sport}_games", function (Blueprint $table) {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('home_team_id');
        $table->unsignedBigInteger('away_team_id');
    });
    $schema->create("{$sport}_predictions", function (Blueprint $table) {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('game_id');
    });
    DB::connection($connection)->table("{$sport}_teams")->insert(['id' => 7]);
    DB::connection($connection)->table("{$sport}_games")->insert([
        ['id' => $base + 1, 'home_team_id' => 7, 'away_team_id' => 8],
        ['id' => $base + 2, 'home_team_id' => 8, 'away_team_id' => 7],
        ['id' => $base + 3, 'home_team_id' => 8, 'away_team_id' => 9],
    ]);
    DB::connection($connection)->table("{$sport}_predictions")->insert([
        ['id' => $base + 11, 'game_id' => $base + 1],
        ['id' => $base + 12, 'game_id' => $base + 2],
        ['id' => $base + 13, 'game_id' => $base + 3],
    ]);
}

it('queries either-side forecasts on the selected team connection while preserving default behavior', function (string $sport) {
    seedTeamQueryConnection($sport, 'team_query_poison', 100);
    seedTeamQueryConnection($sport, 'team_query_selected', 200);
    $class = 'App\\Models\\'.strtoupper($sport).'\\Team';
    $selected = $class::on('team_query_selected')->findOrFail(7);
    $default = $class::findOrFail(7);

    expect($selected->queryPredictions()->orderBy('id')->pluck('id')->all())->toBe([211, 212])
        ->and($selected->queryPredictions()->getConnection()->getName())->toBe('team_query_selected')
        ->and($default->queryPredictions()->orderBy('id')->pluck('id')->all())->toBe([111, 112]);
})->with(['nfl', 'cfb', 'cbb', 'nba', 'wcbb', 'wnba']);

it('queries either-side CBB games on the selected team connection while preserving default behavior', function () {
    seedTeamQueryConnection('cbb', 'team_query_poison', 100);
    seedTeamQueryConnection('cbb', 'team_query_selected', 200);
    $selected = Team::on('team_query_selected')->findOrFail(7);
    $default = Team::findOrFail(7);

    expect($selected->games()->orderBy('id')->pluck('id')->all())->toBe([201, 202])
        ->and($selected->games()->getConnection()->getName())->toBe('team_query_selected')
        ->and($default->games()->orderBy('id')->pluck('id')->all())->toBe([101, 102]);
});
