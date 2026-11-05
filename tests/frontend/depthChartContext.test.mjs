import assert from 'node:assert/strict';
import { test } from 'node:test';
import { normalizeDepthChartContext } from '../../resources/js/lib/depthChartContext.ts';

const optionalNumber = (value) => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

test('missing, partial and unknown depth-chart contexts degrade safely', () => {
    for (const value of [
        null,
        undefined,
        false,
        '',
        [],
        {},
        { type: 'unknown' },
    ]) {
        assert.equal(normalizeDepthChartContext(value, optionalNumber), null);
    }
    const context = normalizeDepthChartContext(
        { type: 'injury_weighting' },
        optionalNumber,
    );
    assert.equal(context.type, 'injury_weighting');
    assert.equal(context.applied, undefined);
    assert.equal(context.spread_adjustment, null);
    assert.equal(context.injury_model_source, null);
});

test('injury adjustments and pitcher fallbacks retain their numeric and optional fields', () => {
    for (const type of ['injury_weighting', 'starter_fallback']) {
        const context = normalizeDepthChartContext(
            {
                type,
                applied: false,
                home_out_weighted: '2.5',
                away_out_weighted: 0,
                home_questionable_weighted: '',
                away_questionable_weighted: Infinity,
                spread_adjustment: '-1.5',
                total_adjustment: 'invalid',
                win_probability_adjustment: '0.02',
                injury_model_source: 'model',
                injury_spread_model_source: 7,
                injury_total_model_source: 'total',
                home_pitcher_source: 'probable',
                away_pitcher_source: 'depth_chart',
                home_depth_chart_fallback_used: false,
                away_depth_chart_fallback_used: true,
                probable_pitcher_injury_applied: 'true',
            },
            optionalNumber,
        );
        assert.deepEqual(context, {
            type,
            applied: false,
            home_out_weighted: 2.5,
            away_out_weighted: 0,
            home_questionable_weighted: null,
            away_questionable_weighted: null,
            spread_adjustment: -1.5,
            total_adjustment: null,
            win_probability_adjustment: 0.02,
            injury_model_source: 'model',
            injury_spread_model_source: null,
            injury_total_model_source: 'total',
            home_pitcher_source: 'probable',
            away_pitcher_source: 'depth_chart',
            home_depth_chart_fallback_used: false,
            away_depth_chart_fallback_used: true,
            probable_pitcher_injury_applied: undefined,
        });
    }
});

test('normalization delegates numeric coercion so sport-specific rules stay intact', () => {
    const seen = [];
    const context = normalizeDepthChartContext(
        { type: 'injury_weighting', spread_adjustment: ' ' },
        (value) => {
            seen.push(value);
            return value === ' ' ? null : optionalNumber(value);
        },
    );
    assert.equal(context.spread_adjustment, null);
    assert.equal(seen.length, 7);
    assert.ok(seen.includes(' '));
});
