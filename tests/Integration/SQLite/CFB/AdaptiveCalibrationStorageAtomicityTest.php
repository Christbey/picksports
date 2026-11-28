<?php

use App\Console\Commands\CFB\UpdateAdaptiveCalibrationCommand;
use App\Models\CFB\PredictionCalibration;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo('2026-11-28 12:00:00');
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    Queue::fake();
});
afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
    DB::disconnect();
});

function calibrationStorageReport(): array
{
    return ['season' => 2026, 'training_from_week' => 0, 'training_through_week' => 4,
        'games_count' => 12, 'min_games' => 8, 'learning_rate' => .25,
        'parameters' => ['week_buckets' => [], 'preseason_component_multipliers' => []],
        'metrics' => ['fixture' => 'storage-only-no-learning']];
}

function calibrationStorageRows(): array
{
    return DB::table('cfb_prediction_calibrations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

it('retains the previously active CFB profile when replacement creation fails', function (string $failure) {
    $report = calibrationStorageReport();
    PredictionCalibration::query()->create([...$report, 'is_active' => true, 'generated_at' => now()->subDay()]);
    $before = calibrationStorageRows();
    expect(DB::connection()->transactionLevel())->toBe(0);
    $dispatcher = PredictionCalibration::getEventDispatcher();
    $isolated = clone $dispatcher;
    PredictionCalibration::setEventDispatcher($isolated);
    $observed = false;
    $error = null;
    $returned = null;
    try {
        PredictionCalibration::creating(function () use ($failure, &$observed) {
            $observed = true;
            if ($failure === 'throw') {
                throw new RuntimeException('Synthetic replacement insert failure.');
            }

            return false;
        });
        // Explicit private-boundary probe: handle()/trainingRows()/learner are never invoked.
        $method = new ReflectionMethod(UpdateAdaptiveCalibrationCommand::class, 'storeCalibration');
        try {
            $returned = $method->invoke(new UpdateAdaptiveCalibrationCommand, $report, true);
        } catch (Throwable $caught) {
            $error = $caught::class;
        }
    } finally {
        PredictionCalibration::setEventDispatcher($dispatcher);
    }
    $after = calibrationStorageRows();
    $trace = getenv('OPS_CALIBRATION_STORAGE_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace, json_encode(['case' => $failure, 'before' => $before, 'after' => $after,
            'observer_reached' => $observed, 'error_class' => $error,
            'returned_exists' => $returned?->exists, 'returned_id' => $returned?->getKey(),
            'transaction_level_after' => DB::connection()->transactionLevel()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
    expect($observed)->toBeTrue()->and($error)->not->toBeNull()
        ->and($after)->toBe($before)->and(DB::connection()->transactionLevel())->toBe(0);
    Http::assertNothingSent();
})->with(['throw', 'cancel']);

it('persists a valid CFB calibration replacement or inactive candidate without learning', function (bool $activate) {
    $report = calibrationStorageReport();
    $old = PredictionCalibration::query()->create([...$report, 'is_active' => true, 'generated_at' => now()->subDay()]);
    expect(DB::connection()->transactionLevel())->toBe(0);
    $method = new ReflectionMethod(UpdateAdaptiveCalibrationCommand::class, 'storeCalibration');
    $new = $method->invoke(new UpdateAdaptiveCalibrationCommand, $report, $activate);
    $rows = calibrationStorageRows();
    $trace = getenv('OPS_CALIBRATION_STORAGE_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace, json_encode(['case' => $activate ? 'active_positive' : 'inactive_positive', 'after' => $rows,
            'returned_exists' => $new->exists, 'returned_id' => $new->getKey()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
    expect($new->exists)->toBeTrue()->and($new->fresh()->is_active)->toBe($activate)
        ->and($old->fresh()->is_active)->toBe(! $activate)->and($rows)->toHaveCount(2)
        ->and($new->fresh()->parameters)->toBe($report['parameters'])
        ->and(DB::connection()->transactionLevel())->toBe(0);
    Http::assertNothingSent();
})->with([true, false]);
