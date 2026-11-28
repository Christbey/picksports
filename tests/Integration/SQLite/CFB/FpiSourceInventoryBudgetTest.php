<?php

use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\Fpi\FpiSourceInventory;
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

it('admits the exact declared team count and refuses one additional stored identity', function () {
    $template = Team::factory()->make()->getAttributes();
    $rows = [];
    for ($id = 1; $id <= 1024; $id++) {
        $rows[] = array_replace($template, ['espn_id' => (string) $id, 'abbreviation' => 'B'.$id, 'school' => 'Bounded College '.$id,
            'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('cfb_teams')->insert($chunk);
    }
    $inventory = app(FpiSourceInventory::class);
    expect($inventory->capture(DB::connection(), 2026, false)['data']['teams'])->toHaveCount(1024);
    DB::table('cfb_teams')->insert(array_replace($template, ['espn_id' => '1025', 'abbreviation' => 'B1025', 'school' => 'Bounded College 1025',
        'created_at' => now(), 'updated_at' => now()]));
    expect(fn () => $inventory->capture(DB::connection(), 2026, false))->toThrow(RuntimeException::class, 'budget');
});

it('bounds stored source text before projecting its body into PHP', function () {
    $team = Team::factory()->create(['school' => str_repeat('a', 256)]);
    $inventory = app(FpiSourceInventory::class);
    expect($inventory->capture(DB::connection(), 2026, false)['data']['teams'][0]['school'])->toHaveLength(256);
    // SQLite deliberately permits beyond VARCHAR; no native VARCHAR-capacity parity claim.
    DB::table('cfb_teams')->where('id', $team->id)->update(['school' => str_repeat('a', 257)]);
    expect(fn () => $inventory->capture(DB::connection(), 2026, false))->toThrow(RuntimeException::class, 'budget');
});

it('admits sixteen bounded mapping aliases and refuses an additional alias', function () {
    $mapping = CfbdTeamMapping::create(['sport' => 'cfb', 'cfbd_team_id' => 1, 'cfbd_team_name' => 'Alias College', 'alternate_names' => array_fill(0, 16, str_repeat('x', 256))]);
    $inventory = app(FpiSourceInventory::class);
    expect($inventory->capture(DB::connection(), 2026, false)['data']['mappings'])->toHaveCount(1);
    DB::table('cfbd_team_mappings')->where('id', $mapping->id)->update(['alternate_names' => json_encode(array_fill(0, 17, 'name'))]);
    expect(fn () => $inventory->capture(DB::connection(), 2026, false))->toThrow(RuntimeException::class, 'sixteen');
});

it('refuses aggregate source bytes even when each row satisfies the scalar and alias limits', function () {
    $rows = [];
    for ($id = 1; $id <= 600; $id++) {
        $rows[] = ['cfbd_team_id' => $id, 'cfbd_team_name' => 'Aggregate College '.$id, 'sport' => 'cfb',
            'alternate_names' => json_encode(array_fill(0, 16, str_repeat('x', 256))), 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('cfbd_team_mappings')->insert($chunk);
    }
    expect(fn () => app(FpiSourceInventory::class)->capture(DB::connection(), 2026, false))->toThrow(RuntimeException::class, 'aggregate');
});

it('projects only actual stored identity columns rather than SQLite quoted identifier literals', function () {
    $team = Team::factory()->create();
    CfbdTeamMapping::create(['sport' => 'cfb', 'cfbd_team_id' => 2, 'cfbd_team_name' => 'Actual Column College']);
    TeamSeasonAffiliation::create(['team_id' => $team->id, 'season' => 2026, 'subdivision' => 'FBS', 'source' => 'synthetic-schema-contract']);
    $data = app(FpiSourceInventory::class)->capture(DB::connection(), 2026, false)['data'];
    foreach (['teams' => 'cfb_teams', 'mappings' => 'cfbd_team_mappings', 'affiliations' => 'cfb_team_season_affiliations'] as $key => $table) {
        foreach ($data[$key] as $row) {
            expect(array_diff(array_keys($row), DB::connection()->getSchemaBuilder()->getColumnListing($table)))->toBe([]);
        }
    }
});
