import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import vm from 'node:vm';
const root = fileURLToPath(new URL('../../', import.meta.url));
const req = createRequire(
    join(process.env.REVIEW_DEPENDENCY_ROOT || root, 'package.json'),
);
const { parse, compileScript } = req('@vue/compiler-sfc');
const ts = req('typescript');
const { createSSRApp } = req('vue');
const { renderToString } = req('vue/server-renderer');
const filename = join(
    root,
    'resources/js/components/game-page/ProbableStartersCard.vue',
);
const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename });
const compiled = compileScript(descriptor, {
    id: 'actual-probable-card',
    inlineTemplate: true,
});
const js = ts.transpileModule(compiled.content, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const mod = { exports: {} };
vm.runInNewContext(
    js,
    { require: req, exports: mod.exports, module: mod, console },
    { filename },
);
const Card = mod.exports.default;
const base = {
    awayTeam: { id: 11, abbreviation: 'AWY' },
    homeTeam: { id: 12, abbreviation: 'HME' },
    awayStarterName: 'Synthetic Pitcher',
    awayStarterSource: 'rotation_projection',
    awayStarterConfidence: null,
};
const forecast = (confidence, candidates = []) => ({
    id: 1,
    forecast_hash: 'synthetic-observation',
    model_version: 'rotation-v2',
    prediction_source: 'rotation_projection',
    predicted_pitcher: {
        id: 2,
        espn_id: 'synthetic-pitcher',
        full_name: 'Synthetic Pitcher',
    },
    predicted_pitcher_rating: null,
    predicted_rating_source: null,
    confidence,
    evidence: { status: 'uncertain_rotation', candidates },
    forecasted_at: null,
    game_start_at: null,
    known_before_game_start: false,
    actual_pitcher: null,
    actual_pitcher_rating: null,
    confirmation_source: null,
    confirmed_at: null,
    is_correct: null,
    starter_changed: null,
    confidence_error: null,
    brier_score: null,
    log_loss: null,
    rating_difference: null,
    grade: null,
    graded_at: null,
});
async function render(name, props) {
    const html = await renderToString(
        createSSRApp(Card, { ...base, ...props }),
    );
    return html
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}
for (const value of [null, undefined])
    test(`rotation status absent ${value} confidence has no invented percent`, async () => {
        const s = await render(`status-${value}`, {
            awayStarterConfidence: value,
        });
        assert.ok(s.includes('Rotation projection'));
        assert.ok(!s.includes('Rotation projection 0%'), s);
    });
test('nullable forecast confidence remains unavailable', async () => {
    const s = await render('forecast-null', {
        awayStarterForecast: forecast(null),
    });
    assert.ok(s.includes('Leading rotation candidate: Synthetic Pitcher'));
    assert.ok(!s.includes('Synthetic Pitcher (0%)'), s);
});
test('nullable candidate probability remains unavailable', async () => {
    const s = await render('candidate-null', {
        awayStarterForecast: forecast(null, [
            {
                pitcher_espn_id: 'candidate-A',
                pitcher_name: 'Candidate Alpha',
                probability: null,
                rating: null,
            },
            {
                pitcher_espn_id: 'candidate-B',
                pitcher_name: 'Candidate Beta',
                probability: 0.7,
                rating: null,
            },
        ]),
    });
    assert.ok(s.includes('Candidate Alpha'));
    assert.ok(!s.includes('Candidate Alpha · 0%'), s);
});
for (const [value, label] of [
    [0, '0%'],
    [0.75, '75%'],
])
    test(`observed numeric ${value} is preserved in all three labels`, async () => {
        const s = await render(`positive-${value}`, {
            awayStarterConfidence: value,
            awayStarterForecast: forecast(value, [
                {
                    pitcher_espn_id: 'candidate-A',
                    pitcher_name: 'Candidate Alpha',
                    probability: value,
                    rating: 0,
                },
                {
                    pitcher_espn_id: 'candidate-B',
                    pitcher_name: 'Candidate Beta',
                    probability: 0.7,
                    rating: null,
                },
            ]),
        });
        assert.ok(s.includes(`Rotation projection ${label}`), s);
        assert.ok(s.includes(`Synthetic Pitcher (${label})`), s);
        assert.ok(s.includes(`Candidate Alpha · ${label} · rating 0`), s);
    });
