<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('adds empty publication receipts without manufacturing or changing historical publications', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $migration = '2026_11_25_030000_create_prediction_publication_effects.php';
    $directory = sys_get_temp_dir().'/picksports-publication-upgrade-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    try {
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (basename($path) !== $migration) {
                copy($path, $directory.'/'.basename($path));
            }
        }
        $this->artisan('migrate:fresh', ['--path' => $directory, '--realpath' => true, '--force' => true])->assertSuccessful();
        expect(Schema::hasTable('prediction_publication_effects'))->toBeFalse();
        $event = SportEvent::factory()->create(['sport' => 'nba']);
        $snapshot = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nba']);
        $release = CalculationRelease::factory()->approved()->create(['sport' => 'nba']);
        $run = CalculationRun::factory()->create([
            'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id,
            'calculation_release_id' => $release->id, 'status' => 'succeeded',
            'completed_at' => now(), 'output_hash' => str_repeat('b', 64),
        ]);
        $prediction = CanonicalPrediction::factory()->create([
            'sport_event_id' => $event->id, 'sport' => 'nba', 'calculation_run_id' => $run->id,
            'phase' => 'pregame', 'publication_state' => 'published', 'published_at' => now(),
            'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
            'output_hash' => str_repeat('b', 64),
        ]);
        $legacy = CanonicalPrediction::factory()->create();
        $legacyBefore = (array) DB::table('predictions')->find($legacy->id);
        $runBefore = (array) DB::table('calculation_runs')->find($run->id);
        $before = (array) DB::table('predictions')->find($prediction->id);
        $ledgerCount = DB::table('migrations')->count();

        $this->artisan('migrate', ['--path' => database_path('migrations/'.$migration), '--realpath' => true, '--force' => true])->assertSuccessful();
        expect(Schema::hasTable('prediction_publication_effects'))->toBeTrue()
            ->and(DB::table('prediction_publication_effects')->count())->toBe(0)
            ->and(DB::table('migrations')->count())->toBe($ledgerCount + 1)
            ->and((array) DB::table('predictions')->find($prediction->id))->toBe($before)
            ->and((array) DB::table('predictions')->find($legacy->id))->toBe($legacyBefore)
            ->and((array) DB::table('calculation_runs')->find($run->id))->toBe($runBefore);
        expect(collect(Schema::getIndexes('prediction_publication_effects'))->contains(fn ($index) => $index['unique'] && $index['columns'] === ['canonical_prediction_id', 'effect']))->toBeTrue();
        expect(collect(Schema::getForeignKeys('prediction_publication_effects'))->contains(fn ($foreign) => $foreign['columns'] === ['canonical_prediction_id'] && $foreign['foreign_table'] === 'predictions' && strtolower($foreign['on_delete']) === 'restrict'))->toBeTrue();

        $this->artisan('migrate', ['--path' => database_path('migrations/'.$migration), '--realpath' => true, '--force' => true])->assertSuccessful();
        expect(DB::table('migrations')->count())->toBe($ledgerCount + 1)
            ->and(DB::table('prediction_publication_effects')->count())->toBe(0);
    } finally {
        File::deleteDirectory($directory);
    }
});
