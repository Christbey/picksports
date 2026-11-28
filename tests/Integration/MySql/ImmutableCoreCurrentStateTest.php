<?php

use App\Models\CalculationRelease;
use App\Models\CalculationReleaseComponent;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.immutable_writer' => config('database.connections.mysql')]);
});

afterEach(function () {
    foreach ([DB::connection(), DB::connection('immutable_writer')] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('immutable_writer');
});

function mysqlImmutableNativePrediction(string $state = 'draft'): CanonicalPrediction
{
    $event = SportEvent::factory()->create(['sport' => 'nba']);
    $input = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nba']);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nba']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id, 'calculation_release_id' => $release->id]);

    return CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nba', 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'revision' => 1, 'publication_state' => $state, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
}

it('uses current approved published and successful state behind an inherited old snapshot', function () {
    $release = CalculationRelease::factory()->create();
    $prediction = mysqlImmutableNativePrediction();
    $run = $prediction->calculationRun;
    DB::beginTransaction();
    CalculationRelease::findOrFail($release->id);
    CanonicalPrediction::findOrFail($prediction->id);
    CalculationRun::findOrFail($run->id);
    $writer = DB::connection('immutable_writer');
    $writer->transaction(function () use ($writer, $release, $prediction, $run): void {
        $writer->table('calculation_releases')->where('id', $release->id)->update(['status' => 'approved', 'approved_at' => now(), 'effective_at' => now()]);
        $writer->table('calculation_runs')->where('id', $run->id)->update(['status' => 'succeeded', 'completed_at' => now(), 'output_hash' => str_repeat('a', 64)]);
        $writer->table('predictions')->where('id', $prediction->id)->update(['publication_state' => 'published', 'published_at' => now()]);
    });
    expect(fn () => $release->updateQuietly(['configuration' => ['tampered' => true]]))->toThrow(LogicException::class)
        ->and(fn () => $run->update(['diagnostics' => ['tampered' => true]]))->toThrow(LogicException::class)
        ->and(fn () => $prediction->update(['output_metadata' => ['tampered' => true]]))->toThrow(LogicException::class);
    DB::rollBack();
    expect($release->fresh()->status)->toBe('approved')->and($release->fresh()->configuration)->not->toHaveKey('tampered')
        ->and($run->fresh()->status)->toBe('succeeded')->and($run->fresh()->diagnostics)->toBeNull()
        ->and($prediction->fresh()->publication_state)->toBe('published')->and($prediction->fresh()->output_metadata)->toBeNull();
});

it('locks current parent status rather than stale draft relations for component and market writes', function () {
    $release = CalculationRelease::factory()->create();
    $component = CalculationReleaseComponent::factory()->create(['calculation_release_id' => $release->id]);
    $prediction = mysqlImmutableNativePrediction();
    $market = PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'confidence_score' => 50]);
    DB::beginTransaction();
    CalculationRelease::findOrFail($release->id);
    CanonicalPrediction::findOrFail($prediction->id);
    $writer = DB::connection('immutable_writer');
    $writer->table('calculation_releases')->where('id', $release->id)->update(['status' => 'approved', 'approved_at' => now()]);
    $writer->table('predictions')->where('id', $prediction->id)->update(['publication_state' => 'published', 'published_at' => now()]);
    expect(fn () => $component->updateQuietly(['configuration' => ['tampered' => true]]))->toThrow(LogicException::class)
        ->and(fn () => $market->incrementQuietly('confidence_score', 1))->toThrow(LogicException::class);
    DB::rollBack();
    expect($component->fresh()->configuration)->toBe([])->and((float) $market->fresh()->confidence_score)->toBe(50.0);
});

it('refuses child ownership changed behind a pinned snapshot before acquiring another owner', function () {
    $oldRelease = CalculationRelease::factory()->create();
    $newRelease = CalculationRelease::factory()->approved()->create();
    $component = CalculationReleaseComponent::factory()->create(['calculation_release_id' => $oldRelease->id]);
    $oldPrediction = CanonicalPrediction::factory()->create(['sport' => 'nba']);
    $newPrediction = mysqlImmutableNativePrediction('published');
    $market = PredictionMarket::factory()->create(['prediction_id' => $oldPrediction->id, 'confidence_score' => 50]);
    DB::beginTransaction();
    CalculationReleaseComponent::findOrFail($component->id);
    PredictionMarket::findOrFail($market->id);
    $writer = DB::connection('immutable_writer');
    $writer->table('calculation_release_components')->where('id', $component->id)->update(['calculation_release_id' => $newRelease->id]);
    $writer->table('prediction_markets')->where('id', $market->id)->update(['prediction_id' => $newPrediction->id]);
    expect(fn () => $component->updateQuietly(['configuration' => ['tampered' => true]]))->toThrow(LogicException::class, 'parent changed')
        ->and(fn () => $market->updateQuietly(['confidence_score' => 99]))->toThrow(LogicException::class, 'parent changed');
    DB::rollBack();
    expect($component->fresh()->calculation_release_id)->toBe($newRelease->id)
        ->and($component->fresh()->configuration)->toBe([])
        ->and($market->fresh()->prediction_id)->toBe($newPrediction->id)
        ->and((float) $market->fresh()->confidence_score)->toBe(50.0);
});

it('serializes a component mutation behind its parent approval lock and refuses the committed result', function () {
    $release = CalculationRelease::factory()->create();
    $component = CalculationReleaseComponent::factory()->create(['calculation_release_id' => $release->id]);
    $writer = DB::connection('immutable_writer');
    $timeout = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        $writer->beginTransaction();
        $writer->table('calculation_releases')->where('id', $release->id)->lockForUpdate()->first();
        $writer->table('calculation_releases')->where('id', $release->id)->update(['status' => 'approved', 'approved_at' => now()]);
        try {
            $component->updateQuietly(['configuration' => ['tampered' => true]]);
            test()->fail('Component mutation must wait for its uncommitted owner lock.');
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        }
        $writer->commit();
        expect(fn () => $component->updateQuietly(['configuration' => ['tampered' => true]]))->toThrow(LogicException::class);
        expect($component->fresh()->configuration)->toBe([]);
    } finally {
        if ($writer->transactionLevel() > 0) {
            $writer->rollBack();
        }
        DB::statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
    }
});
