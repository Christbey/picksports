import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createServer } from 'vite';
import { fileURLToPath } from 'node:url';
import { reactive, createRenderer, h } from 'vue';
import { mappingsProps } from './fixtures/settingsPageSplit.mjs';
let server, useTeamMappings;
before(async () => {
    const root = fileURLToPath(new URL('../../', import.meta.url));
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'settings-routes',
                enforce: 'pre',
                resolveId(id) {
                    if (id.endsWith('/routes/web-push'))
                        return 'virtual:settings-push';
                },
                load(id) {
                    if (id === 'virtual:settings-push')
                        return `export default {subscriptions:{store:{url:()=>'/settings/web-push/subscriptions'},destroy:{url:()=>'/settings/web-push/subscriptions'}},test:{url:()=>'/settings/web-push/test'}};`;
                },
            },
        ],
        resolve: {
            alias: [
                {
                    find: '@inertiajs/vue3',
                    replacement:
                        root +
                        'tests/frontend/fixtures/settingsPageSplitInertia.mjs',
                },
                { find: '@', replacement: root + 'resources/js' },
            ],
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    ({ useTeamMappings } = await server.ssrLoadModule(
        '/resources/js/composables/useTeamMappings.ts',
    ));
});
after(async () => server?.close());
const renderer = createRenderer({
    createElement: () => ({}),
    createText: () => ({}),
    createComment: () => ({}),
    insert() {},
    remove() {},
    setText() {},
    setElementText() {},
    parentNode: () => null,
    nextSibling: () => null,
    patchProp() {},
});
function mount(factory, props, t) {
    let state;
    const app = renderer.createApp({
        setup() {
            state = factory(reactive(props));
            return () => h('div');
        },
    });
    app.mount({});
    t.after(() => app.unmount());
    return state;
}
test('mapping navigation preserves provider overrides, query values and page/filter state', (t) => {
    globalThis.__settingsSplitCalls = [];
    const s = mount(useTeamMappings, mappingsProps(), t);
    s.changeSport('americanfootball_nfl');
    s.changeFilter('unmapped');
    s.changePage(2);
    s.changeProvider('cfbd');
    const urls = __settingsSplitCalls.map(
        (c) => new URL(c.url, 'https://fixture.invalid'),
    );
    assert.equal(urls[0].searchParams.get('custom'), 'a & b');
    assert.equal(urls[0].searchParams.get('sport'), 'americanfootball_nfl');
    assert.equal(urls[1].searchParams.get('filter'), 'unmapped');
    assert.equal(urls[2].searchParams.get('page'), '2');
    assert.equal(urls[3].searchParams.get('sport'), 'americanfootball_ncaaf');
    assert.equal(urls[3].searchParams.has('custom'), false);
});
for (const player of [false, true])
    test(`${player ? 'player' : 'team'} mapping edits, suggestions and removal retain their exact payloads`, (t) => {
        globalThis.__settingsSplitCalls = [];
        const props = mappingsProps(player),
            s = mount(useTeamMappings, props, t);
        s.startEdit(props.mappings.data[0]);
        s.selectedEspnName.value = 'Changed';
        s.saveMapping(1);
        const saved = __settingsSplitCalls.at(-1);
        assert.equal(saved.method, 'patch');
        assert.deepEqual(
            saved.data,
            player
                ? { espn_player_name: 'Changed', espn_player_id: null }
                : { espn_team_name: 'Changed' },
        );
        assert.equal(saved.options.preserveScroll, true);
        assert.equal(s.editingMappingId.value, 1);
        saved.options.onSuccess();
        assert.equal(s.editingMappingId.value, null);
        assert.equal(s.selectedEspnName.value, '');
        s.acceptSuggestedMapping(props.mappings.data[1]);
        assert.deepEqual(
            __settingsSplitCalls.at(-1).data,
            player
                ? { espn_player_name: 'Away Player', espn_player_id: 0 }
                : { espn_team_name: 'Away Team' },
        );
        s.removeMapping(2);
        assert.equal(__settingsSplitCalls.at(-1).method, 'delete');
        assert.equal(
            new URL(
                __settingsSplitCalls.at(-1).url,
                'https://fixture.invalid',
            ).searchParams.get('custom'),
            'a & b',
        );
        s.startEdit(props.mappings.data[0]);
        s.cancelEdit();
        assert.equal(s.editingMappingId.value, null);
    });
test('mapping search stays local, props stay reactive, and sync ends only on completion', (t) => {
    globalThis.__settingsSplitCalls = [];
    const props = reactive(mappingsProps()),
        s = mount(useTeamMappings, props, t);
    s.searchQuery.value = 'suggested';
    assert.equal(s.filteredMappings.value.length, 1);
    assert.equal(__settingsSplitCalls.length, 0);
    props.stats.mapped = 0;
    assert.equal(s.mappingPercentage.value, 0);
    s.syncOddsApiMappings();
    assert.equal(s.isSyncing.value, true);
    __settingsSplitCalls.at(-1).options.onFinish();
    assert.equal(s.isSyncing.value, false);
});
