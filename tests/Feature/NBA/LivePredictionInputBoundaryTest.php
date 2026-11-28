<?php

use App\Actions\NBA\UpdateLivePrediction;
use App\Application\Sports\ReadModels\LivePregameForecast;
use App\Models\LivePredictionSnapshot;
use App\Models\NBA\Game;
use App\Services\Predictions\LivePredictionStore;

it('withholds NBA previews with an invalid clock period or score', function (int $period, ?string $clock, int $home, int $away) {
    $prediction = new LivePregameForecast(4.5, 220.0, 0.6, []);
    expect(app(UpdateLivePrediction::class)->previewState(new stdClass, $prediction, $period, $clock, $home, $away))->toBeNull();
})->with([
    'missing clock' => [1, null, 0, 0],
    'blank clock' => [1, '', 0, 0],
    'nonnumeric clock' => [1, 'bad', 0, 0],
    'invalid seconds' => [1, '11:60', 0, 0],
    'regulation clock overflow' => [1, '12:01', 0, 0],
    'malformed overflow' => [1, '99:99', 0, 0],
    'overtime overflow' => [5, '05:01', 100, 100],
    'zero period' => [0, '12:00', 0, 0],
    'negative period' => [-1, '12:00', 0, 0],
    'negative home score' => [1, '12:00', -1, 0],
    'negative away score' => [1, '12:00', 0, -1],
]);

it('does not publish NBA live output by coercing unavailable stored scores or periods', function (array $changes) {
    $game = new Game(['status' => 'STATUS_IN_PROGRESS', 'period' => 1, 'game_clock' => '12:00', 'home_score' => 0, 'away_score' => 0]);
    $game->forceFill($changes);
    $store = Mockery::mock(LivePredictionStore::class);
    $store->shouldNotReceive('baseline');
    $published = [];
    $store->shouldReceive('record')->andReturnUsing(function ($game, $baseline, $projection) use (&$published) {
        $published[] = $projection;

        return new LivePredictionSnapshot;
    });
    $store->shouldReceive('clear')->once()->with($game, UpdateLivePrediction::class);
    app()->instance(LivePredictionStore::class, $store);
    expect(app(UpdateLivePrediction::class)->execute($game))->toBeNull()
        ->and($published)->toBeEmpty();
})->with([
    'missing home score' => [['home_score' => null]],
    'missing away score' => [['away_score' => null]],
    'missing clock' => [['game_clock' => null]],
    'fractional period' => [['period' => 1.5]],
    'garbage score' => [['home_score' => 'invalid']],
]);

it('preserves real zero regulation and overtime NBA states', function (int $period, string $clock, int $home, int $away, int $remaining) {
    $prediction = new LivePregameForecast(4.5, 220.0, 0.6, []);
    $state = app(UpdateLivePrediction::class)->previewState(new stdClass, $prediction, $period, $clock, $home, $away);
    expect($state)->toBeArray()->and($state['live_seconds_remaining'])->toBe($remaining);
    foreach (['live_predicted_spread', 'live_win_probability', 'live_predicted_total'] as $key) {
        expect(is_finite($state[$key]))->toBeTrue();
    }
})->with([
    'start zero' => [1, '12:00', 0, 0, 2880],
    'period end zero clock' => [1, '00:00', 24, 20, 2160],
    'fourth period' => [4, '01:30', 100, 98, 90],
    'overtime start' => [5, '05:00', 100, 100, 300],
    'second overtime zero clock' => [6, '00:00', 120, 120, 0],
]);

it('keeps valid NBA execute baseline and publication behavior with current integer attributes', function (bool $strings) {
    $game = new Game(['status' => 'STATUS_IN_PROGRESS', 'period' => $strings ? '1' : 1,
        'game_clock' => '12:00', 'home_score' => $strings ? '0' : 0, 'away_score' => $strings ? '0' : 0]);
    $game->syncOriginal();
    $game->period = $strings ? '5' : 5;
    $game->game_clock = '05:00';
    $game->home_score = $strings ? '100' : 100;
    $game->away_score = $strings ? '100' : 100;
    $baseline = new LivePregameForecast(4.5, 220.0, 0.6, []);
    $expected = app(UpdateLivePrediction::class)->previewState($game, $baseline, 5, '05:00', 100, 100);
    $store = Mockery::mock(LivePredictionStore::class);
    $store->shouldReceive('baseline')->once()->with($game)->andReturn($baseline);
    $store->shouldReceive('record')->once()->with($game, $baseline, $expected, 'live', UpdateLivePrediction::class)->andReturn(new LivePredictionSnapshot);
    $store->shouldNotReceive('clear');
    app()->instance(LivePredictionStore::class, $store);
    expect(app(UpdateLivePrediction::class)->execute($game))->toBe($expected);
})->with([false, true]);
