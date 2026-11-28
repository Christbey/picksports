import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createSSRApp, h, provide } from 'vue';
import { renderToString } from 'vue/server-renderer';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';
import { fileURLToPath } from 'node:url';
let server, health, Summary, Trend, Channels, alertKey;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
    });
    health = await server.ssrLoadModule(
        '/resources/js/composables/useHealthchecks.ts',
    );
    Summary = (
        await server.ssrLoadModule(
            '/resources/js/components/admin/HealthcheckSummary.vue',
        )
    ).default;
    Trend = (
        await server.ssrLoadModule(
            '/resources/js/components/admin/HealthcheckPublishingTrend.vue',
        )
    ).default;
    Channels = (
        await server.ssrLoadModule(
            '/resources/js/components/settings/AlertPreferenceChannels.vue',
        )
    ).default;
    alertKey = (
        await server.ssrLoadModule(
            '/resources/js/composables/useAlertPreferences.ts',
        )
    ).alertPreferencesKey;
});
after(async () => server?.close());
async function summary(counts) {
    return renderToString(
        createSSRApp({
            setup() {
                provide(
                    health.healthchecksKey,
                    health.useHealthchecks({
                        filters: {},
                        status_counts: counts,
                    }),
                );
                return () => h(Summary);
            },
        }),
    );
}
test('no observed checks cannot render a green passing operational status', async () => {
    const html = await summary({});
    assert.match(html, /Unknown/);
    assert.doesNotMatch(html, />\s*passing\s*</);
});
test('actual passing warning and failing observations retain severity ordering', async () => {
    assert.match(await summary({ passing: 2 }), />\s*passing\s*</);
    assert.match(await summary({ passing: 2, warning: 1 }), />\s*warning\s*</);
    assert.match(
        await summary({ passing: 2, warning: 1, failing: 1 }),
        />\s*failing\s*</,
    );
});
test('zero review samples cannot present a measured zero change rate', async () => {
    const html = await renderToString(
        createSSRApp({
            setup() {
                provide(
                    health.healthchecksKey,
                    health.useHealthchecks({
                        filters: {},
                        status_counts: {},
                        ai_publishing_trend: {
                            total: 0,
                            changed_rate: 0,
                            changed_count: 0,
                            days: 7,
                            decisions: {},
                            changed_rows: [],
                        },
                    }),
                );
                return () => h(Trend);
            },
        }),
    );
    assert.match(html, /No observed review sample/);
    assert.doesNotMatch(html, /0\.0%\s*would change/);
});
test('alert channel controls submit one authoritative list instead of mixed named checkbox keys', async () => {
    const html = await renderToString(
        createSSRApp({
            setup() {
                provide(alertKey, {
                    availableNotificationTypes: [
                        { value: 'email', label: 'Email' },
                    ],
                    selectedNotificationTypes: ['email'],
                    isNotificationTypeSelected: () => true,
                    toggleNotificationType() {},
                });
                return () => h('form', [h(Channels, { errors: {} })]);
            },
        }),
    );
    assert.doesNotMatch(html, /name="notification_types\[email\]"/);
    assert.equal(
        (html.match(/name="notification_types\[\]"/g) ?? []).length,
        1,
    );
});

test('unsupported recommendations remain escaped information while reviewed actions expose their effects', async () => {
    const Checks = (
        await server.ssrLoadModule(
            '/resources/js/components/admin/HealthcheckChecks.vue',
        )
    ).default;
    const renderCheck = (remediation) =>
        renderToString(
            createSSRApp({
                setup() {
                    provide(
                        health.healthchecksKey,
                        health.useHealthchecks({
                            filters: {},
                            status_counts: { warning: 1 },
                            checks_by_sport: {
                                nba: [
                                    {
                                        id: 1,
                                        sport: 'nba',
                                        check_type: 'validation_unknown',
                                        status: 'warning',
                                        message: 'Synthetic observation',
                                        checked_at: '2026-11-26T12:00:00Z',
                                        metadata: {
                                            recommended_action:
                                                '<img src=x onerror=alert(1)>',
                                        },
                                        remediation,
                                    },
                                ],
                            },
                        }),
                    );
                    return () => h(Checks);
                },
            }),
        );
    const unsupported = await renderCheck({
        supported: false,
        reason: 'Unsupported action: use explicit operator review.',
        effects: null,
    });
    assert.match(unsupported, /&lt;img/);
    assert.doesNotMatch(unsupported, /<img|<button/);
    assert.match(unsupported, /Unsupported action/);
    const supported = await renderCheck({
        supported: true,
        reason: 'Current readiness gates apply.',
        effects: 'May call paid providers and write data.',
    });
    assert.match(supported, /<button/);
    assert.match(supported, /May call paid providers and write data/);
});

test('health timestamp presentation distinguishes offset instants from unknown or future observations', async () => {
    const presentation = await server.ssrLoadModule(
        '/resources/js/lib/healthcheckPresentation.ts',
    );
    assert.equal(
        presentation.formatDate('2099-01-01T12:00:00Z'),
        'Future timestamp',
    );
    assert.equal(
        presentation.formatDate('2026-11-26 12:00:00'),
        'Time zone unverified',
    );
    assert.equal(
        presentation.formatDate('not-an-instant'),
        'Observation time unavailable',
    );
    assert.equal(
        presentation.formatDate('2026-02-30T12:00:00Z'),
        'Observation time unavailable',
    );
    assert.equal(
        presentation.formatDate('2020-01-01T12:00:00+14:30'),
        'Observation time unavailable',
    );
    assert.equal(
        presentation.formatAbsoluteDate('2026-11-26 12:00:00'),
        'Time zone unverified',
    );
    assert.doesNotMatch(
        presentation.formatDate('2020-01-01T12:00:00-06:00'),
        /unavailable|unverified|Future/,
    );
});
