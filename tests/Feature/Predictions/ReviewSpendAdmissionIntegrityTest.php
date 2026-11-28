<?php

use App\Models\AiGeneration;
use App\Models\AnalysisWorkItem;
use App\Services\Predictions\Review\ReviewDeferred;
use App\Services\Predictions\Review\ReviewSpendAdmission;
use Illuminate\Support\Facades\DB;

function syntheticSpendWork(): AnalysisWorkItem
{
    return (new AnalysisWorkItem)->forceFill([
        'id' => 42, 'sport' => 'nba', 'game_table' => 'nba_games', 'game_id' => 17,
        'desired_fingerprint' => str_repeat('a', 64), 'input_payload' => [], 'evidence' => [],
        'execution_spec' => ['provider' => 'synthetic_provider', 'model' => 'synthetic_model', 'prompt_version' => 'synthetic-v1'],
    ]);
}

it('refuses invalid historical cost rather than reducing current admission usage', function () {
    AiGeneration::factory()->create(['cost_usd' => '-1.000000']);
    $count = AiGeneration::query()->count();
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'invalid_usage_telemetry');
    expect(AiGeneration::query()->count())->toBe($count);
});

it('bounds metadata before hydrating the prior-day usage ledger', function () {
    AiGeneration::factory()->create(['cost_usd' => '0', 'metadata' => ['synthetic' => str_repeat('x', 32769)]]);
    $queries = [];
    DB::listen(function ($event) use (&$queries) {
        $queries[] = $event->sql;
    });
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'usage_inventory_limit');
    expect(collect($queries)->contains(fn ($sql) => preg_match('/select\s+\*\s+from\s+["`]?ai_generations/i', $sql) === 1))->toBeFalse()
        ->and(AiGeneration::query()->count())->toBe(1);
});

it('bounds row count before hydrating generation documents', function () {
    config(['prediction_review.budget.application_daily_usd' => 1000]);
    $row = AiGeneration::factory()->make(['cost_usd' => '0', 'metadata' => null])->getAttributes();
    $rows = [];
    for ($i = 0; $i < 10001; $i++) {
        $rows[] = [...$row, 'public_id' => str_pad((string) $i, 26, '0', STR_PAD_LEFT)];
        if (count($rows) === 250 || $i === 10000) {
            DB::table('ai_generations')->insert($rows);
            $rows = [];
        }
    }
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'usage_inventory_limit');
    expect(AiGeneration::query()->count())->toBe(10001);
});

it('admits an exact decimal budget boundary and preserves measured zero', function () {
    config(['prediction_review.budget.application_daily_usd' => 0.3, 'prediction_review.budget.reservation_usd' => 0.2]);
    AiGeneration::factory()->create(['cost_usd' => '0.100000', 'metadata' => null]);
    $generation = app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork());
    expect($generation->status)->toBe('running')
        ->and(data_get($generation->metadata, 'reserved_cost_usd'))->toBe(0.2)
        ->and(AiGeneration::query()->count())->toBe(2);
});

it('rejects dense or cumulatively excessive metadata within ordinary byte lengths', function (string $scope) {
    $count = $scope === 'row' ? 1 : 16;
    $entries = $scope === 'row' ? 2001 : 1000;
    AiGeneration::factory()->count($count)->create(['cost_usd' => '0', 'metadata' => ['dense' => array_fill(0, $entries, 0)]]);
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'usage_inventory_limit');
    expect(AiGeneration::query()->count())->toBe($count);
})->with(['row', 'aggregate']);

it('rejects cumulative metadata bytes before transferring documents', function () {
    AiGeneration::factory()->count(130)->create(['cost_usd' => '0', 'metadata' => ['synthetic' => str_repeat('x', 32500)]]);
    $bodyRead = false;
    DB::listen(function ($event) use (&$bodyRead) {
        $bodyRead = $bodyRead || preg_match('/select\s+\*\s+from\s+["`]?ai_generations/i', $event->sql) === 1;
    });
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'usage_inventory_limit');
    expect($bodyRead)->toBeFalse();
});

it('does not treat invalid historical metadata as an empty valid record', function () {
    $generation = AiGeneration::factory()->create(['cost_usd' => '0']);
    DB::table('ai_generations')->where('id', $generation->id)->update(['metadata' => '{']);
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'invalid_usage_telemetry');
})->skip(fn () => config('database.default') !== 'sqlite', 'SQLite-only malformed JSON storage; native JSON rejects the fixture.');

it('refuses a metadata row that grows beyond its limit after scalar preflight', function () {
    $generation = AiGeneration::factory()->create(['cost_usd' => '0', 'metadata' => []]);
    $grown = false;
    DB::listen(function ($event) use (&$grown, $generation) {
        if (! $grown && str_contains($event->sql, 'as metadata_bytes')) {
            $grown = true;
            DB::table('ai_generations')->where('id', $generation->id)->update(['metadata' => json_encode(['synthetic' => str_repeat('x', 40000)], JSON_THROW_ON_ERROR)]);
        }
    });
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'usage_inventory_changed');
    expect($grown)->toBeTrue();
});

it('preserves quoted JSON characters and explicit zero-cost observations', function () {
    config(['prediction_review.budget.application_daily_usd' => 0.05]);
    AiGeneration::factory()->create(['cost_usd' => '0', 'metadata' => ['synthetic' => str_repeat('\\"[]{}:,', 500)]]);
    expect(app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork())->status)->toBe('running');
});

it('bounds an invalid stored cost scalar before transferring a usage document', function () {
    $generation = AiGeneration::factory()->create(['cost_usd' => '0']);
    DB::table('ai_generations')->where('id', $generation->id)->update(['cost_usd' => str_repeat('x', 100000)]);
    $bodyRead = false;
    DB::listen(function ($event) use (&$bodyRead) {
        $bodyRead = $bodyRead || preg_match('/select\s+\*\s+from\s+["`]?ai_generations/i', $event->sql) === 1;
    });
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'invalid_usage_telemetry');
    expect($bodyRead)->toBeFalse();
})->skip(fn () => config('database.default') !== 'sqlite', 'SQLite-only invalid decimal storage; native DECIMAL rejects the fixture.');

it('rejects exponent notation before expanding a stored reservation amount', function () {
    AiGeneration::factory()->create(['cost_usd' => null, 'metadata' => ['reserved_cost_usd' => '1e2']]);
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'invalid_usage_telemetry');
});

it('bounds huge positive and negative exponent metadata without decimal expansion', function (string $amount) {
    AiGeneration::factory()->create(['cost_usd' => null, 'metadata' => ['reserved_cost_usd' => $amount]]);
    expect(fn () => app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork()))
        ->toThrow(ReviewDeferred::class, 'invalid_usage_telemetry');
    expect(AiGeneration::query()->count())->toBe(1);
})->with(['1e100000000', '1e-100000000']);

it('retains finite native decimal values returned by SQLite in exponent form', function () {
    config(['prediction_review.budget.application_daily_usd' => 0.050001]);
    AiGeneration::factory()->create(['cost_usd' => '0.000001', 'metadata' => null]);
    expect(app(ReviewSpendAdmission::class)->reserve(syntheticSpendWork())->status)->toBe('running');
});
