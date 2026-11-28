<?php

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Services\Predictions\CalculationReleaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

it('refuses cancelled calculation release lifecycle persistence without acknowledging a transition', function (string $operation, string $mode) {
    $manager = app(CalculationReleaseManager::class);
    $release = CalculationRelease::factory()->create();
    if ($operation !== 'approve') {
        $release = $manager->approve($release, 'synthetic-owner', 'Synthetic boundary control');
    }
    $original = $release->fresh()->getAttributes();
    $dispatcher = CalculationRelease::getEventDispatcher();
    CalculationRelease::setEventDispatcher(clone $dispatcher);
    CalculationRelease::saving(function ($row) use ($mode, $original) {
        if ($mode === 'cancel') {
            return false;
        }
        $row->status = $original['status'];
    });
    $caught = false;
    try {
        match ($operation) {
            'approve' => $manager->approve($release, 'synthetic-owner', 'Synthetic cancelled boundary'),
            'retire' => $manager->retire($release),
            'invalidate' => $manager->invalidate($release, 'Synthetic cancelled boundary'),
        };
    } catch (Throwable) {
        $caught = true;
    } finally {
        CalculationRelease::setEventDispatcher($dispatcher);
    }
    expect($release->fresh()->getAttributes())->toBe($original);
    expect($caught)->toBeTrue();
})->with([['approve', 'cancel'], ['retire', 'cancel'], ['invalidate', 'cancel'], ['approve', 'rewrite'], ['retire', 'rewrite'], ['invalidate', 'rewrite']]);

it('keeps colliding calculation release ids on the supplied connection', function () {
    $default = CalculationRelease::factory()->create();
    $original = $default->fresh()->getAttributes();
    config(['database.connections.release_boundary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $other = DB::connection('release_boundary');
    try {
        // Independent, synthetic SQLite store, even when the default store is MySQL.
        // Only the release and its empty rules-component relation are needed here.
        $other->getSchemaBuilder()->create('calculation_releases', function (Blueprint $table) use ($original): void {
            $table->integer('id')->primary();
            foreach (array_keys($original) as $column) {
                if ($column !== 'id') {
                    $table->text($column)->nullable();
                }
            }
        });
        $other->getSchemaBuilder()->create('calculation_release_components', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('calculation_release_id');
        });
        $other->table('calculation_releases')->insert($original);
        $input = (new CalculationRelease)->setConnection('release_boundary')->newQuery()->findOrFail($default->id);
        $result = app(CalculationReleaseManager::class)->approve($input, 'synthetic-other-owner', 'Synthetic connection boundary');
        expect($default->fresh()->getAttributes())->toBe($original);
        expect($other->table('calculation_releases')->where('id', $default->id)->value('status'))->toBe('approved');
        expect($result->getConnection())->toBe($other);
    } finally {
        DB::purge('release_boundary');
    }
});

it('refuses transplanted calculation release table context before changing the literal store', function () {
    $release = CalculationRelease::factory()->create();
    $original = $release->fresh()->getAttributes();
    $input = clone $release;
    $input->setTable('synthetic_other_releases');
    expect(fn () => app(CalculationReleaseManager::class)->approve($input, 'synthetic-owner', 'Synthetic table boundary'))->toThrow(PredictionLifecycleException::class);
    expect($release->fresh()->getAttributes())->toBe($original);
});
