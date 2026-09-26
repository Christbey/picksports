// Run with the synthetic preview server listening on 127.0.0.1:5193.
import assert from 'node:assert/strict';
import units from '../../resources/js/lib/nflUnitMetrics.json' with { type: 'json' };

const endpoint =
    'http://127.0.0.1:5193/api/v2/sports/nfl/games/1722/market-history';
let checked = 0;
for (const unit of units) {
    for (const metric of unit.metrics) {
        for (const band of [
            'all',
            'top_5',
            'top_10',
            'bottom_10',
            'bottom_5',
        ]) {
            const params = new URLSearchParams({
                opponent_unit: unit.id,
                opponent_metric: metric.id,
                strength_band: band,
            });
            const response = await fetch(`${endpoint}?${params}`);
            assert.equal(response.status, 200);
            const { data } = await response.json();
            assert.equal(data.unit_filter.unit, unit.id);
            assert.equal(data.unit_filter.metric, metric.id);
            assert.equal(data.unit_filter.band, band);
            const supported = [
                'points_per_game',
                'points_allowed_per_game',
            ].includes(metric.id);
            assert.equal(
                data.unit_filter.status,
                supported ? 'available' : 'unavailable',
            );
            for (const side of Object.values(data.sides)) {
                for (const row of side.rows) {
                    assert.equal(
                        row.sample_size,
                        row.ats.wins + row.ats.losses + row.ats.pushes,
                    );
                    assert.equal(
                        row.sample_size,
                        row.outright.wins +
                            row.outright.losses +
                            row.outright.ties,
                    );
                    if (!supported) {
                        assert.equal(row.status, 'unavailable');
                        assert.equal(row.sample_size, 0);
                        assert.equal(row.ats_win_pct, null);
                        assert.match(row.reason, /No records inferred/);
                    }
                }
            }
            checked++;
        }
    }
}
for (const query of [
    'opponent_unit=unknown',
    'opponent_unit=offense&opponent_metric=points_allowed_per_game',
    'strength_band=unknown',
]) {
    assert.equal((await fetch(`${endpoint}?${query}`)).status, 422);
}
console.log(
    `PASS: ${checked} unit/metric/strength combinations; unavailable metrics stay empty; invalid combinations rejected.`,
);
