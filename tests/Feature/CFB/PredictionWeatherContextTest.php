<?php

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\GameWeather;
use App\Services\Api\V2\PredictionWeatherContext;
use Illuminate\Database\Eloquent\Model;

it('prefers frozen pregame weather over the latest weather record', function () {
    $snapshot = new class extends Model {};
    $snapshot->inputs = ['signal_context' => ['weather_evidence' => ['provider' => 'open_meteo', 'forecast_valid_at' => '2026-09-26T11:00:00-05:00'], 'temperature_f' => 55, 'wind_speed_mph' => 20]];
    $run = new class extends Model {};
    $run->setRelation('inputSnapshot', $snapshot);
    $prediction = (new CanonicalPrediction)->setRelation('calculationRun', $run);
    $game = (new Game)->setRelation('weather', new GameWeather(['temperature_f' => 75]));
    $result = app(PredictionWeatherContext::class)->forPrediction($prediction, $game);
    expect($result['source'])->toBe('model_snapshot')->and($result['temperature_f'])->toBe(55)->and($result['wind_speed_mph'])->toBe(20);
});

it('labels missing and subsequently stored weather without inventing model evidence', function () {
    $prediction = (new CanonicalPrediction)->setRelation('calculationRun', null);
    $game = (new Game)->setRelation('weather', null);
    expect(app(PredictionWeatherContext::class)->forPrediction($prediction, $game))->toBe(['available' => false, 'source' => 'missing']);
    $game->setRelation('weather', new GameWeather(['temperature_f' => 55, 'wind_speed_mph' => 0]));
    $result = app(PredictionWeatherContext::class)->forPrediction($prediction, $game);
    expect($result['source'])->toBe('latest_forecast')->and($result['wind_speed_mph'])->toBe('0.00');
});
