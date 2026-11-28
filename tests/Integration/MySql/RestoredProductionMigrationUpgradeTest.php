<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Committed fixtures are visible to migration transactions; no RefreshDatabase wrapper.
uses(TestCase::class);

beforeEach(function () {
    $this->assertSame('mysql', DB::connection()->getDriverName());
    $this->directory = sys_get_temp_dir().'/picksports-migration-upgrade-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
});

afterEach(function () {
    if (isset($this->directory)) {
        File::deleteDirectory($this->directory);
    }
});

it('preserves existing rows while restoring deployed history and applying both new additive migrations', function () {
    $manifest = json_decode(file_get_contents(base_path('docs/production-readiness/restored-production-migrations-2026-11-25.json')), true, flags: JSON_THROW_ON_ERROR);
    $restoredNames = [];
    $upgradePaths = [];
    foreach ($manifest['restored_files'] as $file) {
        $path = base_path($file['path']);
        $this->assertSame($file['sha256'], hash_file('sha256', $path), 'Historical migration bytes must match the deployed source manifest.');
        $restoredNames[] = pathinfo($path, PATHINFO_FILENAME);
        $upgradePaths[] = $path;
    }
    $this->assertCount(13, $restoredNames);
    foreach ($manifest['production_migration_names'] as $name) {
        if (! in_array($name, $restoredNames, true)) {
            copy(database_path('migrations/'.$name.'.php'), $this->directory.'/'.$name.'.php');
        }
    }
    $this->artisan('migrate:fresh', ['--path' => $this->directory, '--realpath' => true, '--force' => true])->assertSuccessful();
    $this->assertSame(count($manifest['production_migration_names']) - 13, DB::table('migrations')->count());
    $this->assertFalse(Schema::hasColumn('cbb_games', 'neutral_site'));
    $this->assertFalse(Schema::hasColumn('sports_ai_prediction_analyses', 'canonical_prediction_id'));
    $this->assertFalse(Schema::hasTable('snapshot_metadata_payloads'));
    $this->assertSame('decimal(5,3)', collect(Schema::getColumns('cbb_predictions'))->firstWhere('name', 'home_recent_form')['type']);

    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $predictionId = DB::table('cbb_predictions')->insertGetId(['game_id' => $game->id, 'home_recent_form' => -99.999, 'away_recent_form' => null]);
    $legacyAnalysis = [
        'sport' => 'cbb', 'game_id' => $game->id, 'prediction_id' => $predictionId,
        'as_of_date' => '2026-11-24', 'market' => 'spread', 'input_hash' => str_repeat('a', 64),
        'raw_payload' => '{}', 'recommendation' => 'hold', 'ai_confidence' => 0,
        'analysis_confidence' => 0, 'bet_classification' => 'hold', 'summary' => 'Preserved legacy evidence.',
    ];
    $analysisId = DB::table('sports_ai_prediction_analyses')->insertGetId($legacyAnalysis);
    $beforePrediction = (array) DB::table('cbb_predictions')->find($predictionId);
    $beforeAnalysis = (array) DB::table('sports_ai_prediction_analyses')->find($analysisId);

    $this->artisan('migrate', ['--path' => $upgradePaths, '--realpath' => true, '--force' => true])->assertSuccessful();
    $this->assertSame(count($manifest['production_migration_names']), DB::table('migrations')->count());
    foreach ($beforePrediction as $column => $value) {
        $this->assertEquals($value, DB::table('cbb_predictions')->where('id', $predictionId)->value($column), 'Legacy prediction column changed: '.$column);
    }
    foreach ($beforeAnalysis as $column => $value) {
        $this->assertSame($value, DB::table('sports_ai_prediction_analyses')->where('id', $analysisId)->value($column), 'Legacy analysis column changed: '.$column);
    }
    foreach (['snapshot_metadata_payloads', 'mlb_game_reconciliation_records', 'cbb_roster_snapshots', 'cbb_roster_entries', 'cfb_availability_reports'] as $table) {
        $this->assertTrue(Schema::hasTable($table), 'Missing restored table: '.$table);
    }
    foreach (['nba_games', 'cbb_games', 'wcbb_games'] as $table) {
        $this->assertTrue(collect(Schema::getColumns($table))->firstWhere('name', 'neutral_site')['nullable']);
    }
    foreach (['sports_ai_prediction_analyses', 'mlb_bet_filter_results', 'nfl_signal_observations', 'prediction_feature_snapshots', 'mlb_period_feature_snapshots'] as $table) {
        $this->assertTrue(collect(Schema::getForeignKeys($table))->contains(fn ($foreign) => $foreign['columns'] === ['canonical_prediction_id'] && $foreign['foreign_table'] === 'predictions'));
    }
    foreach (['home_recent_form', 'away_recent_form'] as $name) {
        $column = collect(Schema::getColumns('cbb_predictions'))->firstWhere('name', $name);
        $this->assertSame('decimal(7,3)', $column['type']);
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);
    }
    DB::table('cbb_predictions')->where('id', $predictionId)->update(['home_recent_form' => -123.456, 'away_recent_form' => 123.456]);
    $this->assertEquals(-123.456, DB::table('cbb_predictions')->where('id', $predictionId)->value('home_recent_form'));
    $this->assertEquals(123.456, DB::table('cbb_predictions')->where('id', $predictionId)->value('away_recent_form'));
    $native = CanonicalPrediction::factory()->create();
    DB::table('sports_ai_prediction_analyses')->insert([...$legacyAnalysis, 'prediction_id' => null, 'canonical_prediction_id' => $native->id]);
    $this->assertSame(2, DB::table('sports_ai_prediction_analyses')->count());
    $this->assertNull(DB::table('cbb_games')->where('id', $game->id)->value('details_attempted_at'));
    $this->assertTrue(Schema::hasColumns('cbb_games', ['details_attempted_at', 'details_completed_at', 'details_status']));
    $this->assertTrue(Schema::hasColumn('nfl_research_revisions', 'payload_archive'));

    $this->artisan('migrate', ['--path' => $upgradePaths, '--realpath' => true, '--force' => true])->assertSuccessful();
    $this->assertSame(count($manifest['production_migration_names']), DB::table('migrations')->count());
    $this->assertSame(2, DB::table('sports_ai_prediction_analyses')->count());

    $legacyRuns = [];
    foreach (['failed', 'running', 'succeeded'] as $status) {
        $event = SportEvent::factory()->create(['sport' => 'nba']);
        $snapshot = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nba']);
        $release = CalculationRelease::factory()->approved()->create(['sport' => 'nba']);
        $run = CalculationRun::factory()->create([
            'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id,
            'calculation_release_id' => $release->id, 'status' => $status,
            'started_at' => now()->subMinutes(10),
            'completed_at' => $status === 'running' ? null : now()->subMinutes(9),
            'failure_code' => $status === 'failed' ? 'fixture_transient_failure' : null,
            'failure_message' => $status === 'failed' ? 'Preserve this original failure.' : null,
            'output_hash' => $status === 'succeeded' ? str_repeat('b', 64) : null,
        ]);
        $legacyRuns[$run->id] = (array) DB::table('calculation_runs')->find($run->id);
    }
    $legacyReceipts = [];
    foreach (['pending', 'completed'] as $status) {
        $id = DB::table('api_idempotency_keys')->insertGetId([
            'principal_type' => 'fixture_principal', 'principal_id' => '1',
            'route_scope' => 'v2.user-bets.store', 'key_hash' => hash('sha256', $status),
            'scope_hash' => hash('sha256', 'fixture_scope_'.$status),
            'request_fingerprint' => hash('sha256', 'fixture_request_'.$status),
            'expires_at' => now()->addDay(),
            'response_status' => $status === 'completed' ? 201 : null,
            'response_headers' => $status === 'completed' ? '{"Content-Type":"application/json"}' : null,
            'response_body' => $status === 'completed' ? '{"fixture":"retained"}' : null,
            'completed_at' => $status === 'completed' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacyReceipts[$id] = (array) DB::table('api_idempotency_keys')->find($id);
    }
    $this->assertFalse(Schema::hasTable('calculation_run_attempts'));
    $this->assertFalse(Schema::hasColumn('api_idempotency_keys', 'execution_mode'));
    $newPaths = [
        database_path('migrations/2026_11_25_010000_add_atomic_mode_to_api_idempotency_keys.php'),
        database_path('migrations/2026_11_25_010000_create_calculation_run_attempts.php'),
    ];
    $this->artisan('migrate', ['--path' => $newPaths, '--realpath' => true, '--force' => true])->assertSuccessful();
    $this->assertSame(count($manifest['production_migration_names']) + 2, DB::table('migrations')->count());
    $this->assertSame(0, DB::table('calculation_run_attempts')->count(), 'Migration must not invent historical attempts.');
    foreach ($legacyRuns as $id => $before) {
        $this->assertSame($before, (array) DB::table('calculation_runs')->find($id), 'Existing run identity, state and diagnostics must be preserved.');
    }
    foreach ($legacyReceipts as $id => $before) {
        $after = (array) DB::table('api_idempotency_keys')->find($id);
        $this->assertArrayHasKey('execution_mode', $after);
        $this->assertNull($after['execution_mode'], 'Existing receipts must retain legacy provenance.');
        unset($after['execution_mode']);
        $this->assertSame($before, $after, 'Existing pending/completed receipts must not be rewritten.');
    }
});
