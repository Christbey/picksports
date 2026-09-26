import assert from 'node:assert/strict';
import { test } from 'node:test';
import { predictionWeather } from '../../resources/js/lib/predictionWeather.ts';
test('missing weather is explicit and zero values remain visible', () => {
    assert.match(predictionWeather(null).summary, /unavailable/);
    const result = predictionWeather({
        available: true,
        source: 'model_snapshot',
        temperature_f: '0',
        wind_speed_mph: 0,
        wind_gust_mph: 8,
        precipitation_inches: 0,
    });
    assert.match(result.summary, /0°F/);
    assert.match(result.summary, /Wind 0 mph · gusts 8 mph/);
    assert.match(result.summary, /Precip 0 in/);
    assert.match(result.detail, /saved with pregame model/);
});
test('latest forecast is distinguished from immutable model weather', () => {
    const result = predictionWeather({
        available: true,
        source: 'latest_forecast',
        is_indoor: true,
        forecast_valid_at: '2026-09-26T16:00:00Z',
        received_at: '2026-09-26T15:00:00Z',
    });
    assert.equal(result.summary, 'Indoors');
    assert.match(result.detail, /not included in this model snapshot/);
    assert.match(result.detail, /11:00 AM CDT/);
});
