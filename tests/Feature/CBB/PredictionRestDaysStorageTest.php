<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('persists a season-opening prediction with offseason rest', function (string $sport): void {
    $namespace = strtoupper($sport);
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $actionClass = "App\\Actions\\{$namespace}\\GeneratePrediction";
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => false]);
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());

    $home = $teamClass::factory()->create();
    $away = $teamClass::factory()->create();
    $gameClass::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'game_date' => now()->subDays(212)->toDateString(),
    ]);
    $game = $gameClass::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2027,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->toDateString(),
        'game_time' => '23:00:00',
    ]);

    $prediction = app($actionClass)->execute($game, false)->fresh();

    expect($prediction->rest_days_home)->toBe(212)
        ->and($prediction->rest_days_away)->toBe(212);

    $columns = collect(Schema::getColumns("{$sport}_predictions"))->keyBy('name');
    foreach (['rest_days_home', 'rest_days_away'] as $column) {
        expect($columns[$column]['type_name'])->toBe(DB::getDriverName() === 'sqlite' ? 'integer' : 'smallint')
            ->and($columns[$column]['nullable'])->toBeTrue();
    }
})->with(['cbb', 'wcbb']);

it('widens both college basketball tables in the production MySQL migration SQL', function (): void {
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('mysql');

    try {
        $migration = require database_path('migrations/2026_11_02_184014_widen_college_basketball_prediction_rest_days.php');
        $queries = DB::connection('mysql')->pretend(fn () => $migration->up());
    } finally {
        DB::setDefaultConnection($original);
    }

    expect($queries)->toHaveCount(4);
    foreach (['cbb', 'wcbb'] as $sport) {
        $sql = collect($queries)->pluck('query')->filter(fn (string $query): bool => str_contains($query, "alter table `{$sport}_predictions`"))->implode('; ');
        expect($sql)->toContain("alter table `{$sport}_predictions`")
            ->toContain('`rest_days_home` smallint null')
            ->toContain('`rest_days_away` smallint null');
    }
});
