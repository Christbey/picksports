<?php

use App\Models\Healthcheck;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Services\Validation\ValidationRunRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('database.connections.validation_recorder_test', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::purge('validation_recorder_test');
    foreach (['validation_runs', 'healthchecks', 'validation_findings'] as $table) {
        $definition = DB::connection()->selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);
        DB::connection('validation_recorder_test')->unprepared($definition->sql);
    }
    $attributes = ['id' => 4242, 'command_name' => 'healthcheck:validate-data', 'scope' => 'sport:nba',
        'status' => 'running', 'summary' => ['validation_scope' => 'full'], 'started_at' => now()];
    ValidationRun::query()->forceCreate($attributes);
    $this->selectedRun = (new ValidationRun)->setConnection('validation_recorder_test')->newQuery()->forceCreate($attributes);
});

afterEach(function () {
    DB::purge('validation_recorder_test');
});

test('validation recorder stores both finding views on the supplied run connection', function () {
    $recorder = app(ValidationRunRecorder::class);
    $recorder->record($this->selectedRun, 'nba', ['check_type' => 'connection_fixture', 'status' => 'warning', 'message' => 'Selected target finding']);
    $recorder->complete($this->selectedRun);

    expect($this->selectedRun->fresh()->status)->toBe('warning')
        ->and($this->selectedRun->findings()->count())->toBe(1)
        ->and(Healthcheck::on('validation_recorder_test')->count())->toBe(1)
        ->and(ValidationFinding::query()->count())->toBe(0)
        ->and(Healthcheck::query()->count())->toBe(0)
        ->and(ValidationRun::findOrFail(4242)->status)->toBe('running')
        ->and(DB::connection('validation_recorder_test')->transactionLevel())->toBe(0);
});

test('validation recorder rolls back the supplied target when its second view fails', function () {
    $event = 'eloquent.creating: '.ValidationFinding::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic finding refusal'));
    try {
        expect(fn () => app(ValidationRunRecorder::class)->record($this->selectedRun, 'nba', [
            'check_type' => 'connection_fixture', 'status' => 'warning', 'message' => 'Selected target finding',
        ]))->toThrow(RuntimeException::class, 'Synthetic finding refusal');
    } finally {
        Event::forget($event);
    }
    expect(Healthcheck::on('validation_recorder_test')->count())->toBe(0)
        ->and($this->selectedRun->findings()->count())->toBe(0)
        ->and(Healthcheck::query()->count())->toBe(0)
        ->and(ValidationFinding::query()->count())->toBe(0)
        ->and(DB::connection('validation_recorder_test')->transactionLevel())->toBe(0);
});
