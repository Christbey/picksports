import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { effectScope, reactive, nextTick } from 'vue';
import { createServer } from 'vite';
import { fileURLToPath } from 'node:url';
import { adminSettingsProps } from './fixtures/adminSettings.mjs';
let server, useAdminSettings;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'admin-settings-route-fixtures',
                resolveId(id) {
                    if (id.endsWith('/routes/admin/settings/founding-users'))
                        return 'virtual:founding-user-routes';
                    if (id.endsWith('/routes/admin/settings/groups'))
                        return 'virtual:group-routes';
                },
                load(id) {
                    if (id === 'virtual:founding-user-routes')
                        return `
                    const route = name => ({ url: () => '/settings/admin/founding-users/' + name });
                    export default { search: route('search'), grant: route('grant'), limit: route('limit'), revoke: route('revoke') };
                `;
                    if (id === 'virtual:group-routes')
                        return `
                    const route = path => ({ url: () => '/settings/admin/groups' + path });
                    export default { store: route(''), invite: route('/invite'), joinLink: route('/join-link'), users: { search: route('/users/search'), store: route('/users'), destroy: route('/users') } };
                `;
                },
            },
        ],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
                {
                    find: '@inertiajs/vue3',
                    replacement: fileURLToPath(
                        new URL(
                            './fixtures/adminSettingsInertia.mjs',
                            import.meta.url,
                        ),
                    ),
                },
                {
                    find: '@',
                    replacement: fileURLToPath(
                        new URL('../../resources/js', import.meta.url),
                    ),
                },
            ],
        },
    });
    ({ useAdminSettings } = await server.ssrLoadModule(
        '/resources/js/composables/useAdminSettings.ts',
    ));
});
after(async () => server?.close());
function setup(t) {
    globalThis.__adminSettingsCalls = [];
    globalThis.__adminSettingsForms = [];
    const scope = effectScope();
    const props = reactive(adminSettingsProps());
    const state = scope.run(() => useAdminSettings(props));
    t.after(() => scope.stop());
    return {
        state,
        props,
        calls: globalThis.__adminSettingsCalls,
        success: () => globalThis.__adminSettingsSuccess(),
    };
}
test('forms keep endpoint, payload, preserveScroll, and success reset behavior', (t) => {
    const { state: s, calls, success } = setup(t);
    s.groupForm.name = 'New Pool';
    s.groupForm.season = 2025;
    s.createGroup();
    assert.deepEqual(calls.at(-1), {
        method: 'post',
        url: '/settings/admin/groups',
        data: { name: 'New Pool', season: 2025 },
        preserveScroll: true,
    });
    success();
    assert.equal(s.groupForm.name, '');
    assert.equal(s.groupForm.season, 2025);
    s.inviteForm.group_id = 2;
    s.inviteForm.email = 'new@example.com';
    s.inviteToGroup();
    assert.deepEqual(calls.at(-1).data, {
        group_id: 2,
        email: 'new@example.com',
    });
    assert.equal(calls.at(-1).url, '/settings/admin/groups/invite');
    success();
    assert.equal(s.inviteForm.email, '');
    assert.equal(s.inviteForm.group_id, 2);
    s.limitForm.limit = 25;
    s.updateFoundingLimit();
    assert.equal(calls.at(-1).url, '/settings/admin/founding-users/limit');
    assert.deepEqual(calls.at(-1).data, { limit: 25 });
    s.selectUserSuggestion({
        id: 8,
        name: 'Picked',
        email: 'picked@example.com',
    });
    assert.equal(s.userSearch.value, 'Picked (picked@example.com)');
    assert.deepEqual(s.userSuggestions.value, []);
    s.grantFoundingAccess();
    assert.equal(calls.at(-1).url, '/settings/admin/founding-users/grant');
    assert.deepEqual(calls.at(-1).data, { email: 'picked@example.com' });
    success();
    assert.equal(s.grantForm.email, '');
    assert.equal(s.userSearch.value, '');
});
test('group selection keeps its group identity and resets only the completed search', (t) => {
    const { state: s, calls, success } = setup(t);
    s.groupUserSearch.value[2] = 'other';
    s.addUserToGroup(1);
    assert.equal(calls.length, 0);
    s.selectAssignableUser(1, {
        id: 42,
        name: 'Selected',
        email: 'selected@example.com',
    });
    s.addUserToGroup(2);
    assert.equal(calls.length, 0);
    s.addUserToGroup(1);
    assert.equal(calls.at(-1).url, '/settings/admin/groups/users');
    assert.deepEqual(calls.at(-1).data, { group_id: 1, user_id: 42 });
    assert.equal(calls.at(-1).preserveScroll, true);
    success();
    assert.equal(s.assignMemberForm.user_id, null);
    assert.equal(s.groupUserSearch.value[1], '');
    assert.equal(s.groupUserSearch.value[2], 'other');
});
test('destructive actions retain confirmation and correct IDs; join link rotation retains route', (t) => {
    const { state: s, calls } = setup(t);
    const old = globalThis.confirm;
    const prompts = [];
    t.after(() => {
        globalThis.confirm = old;
    });
    globalThis.confirm = (message) => {
        prompts.push(message);
        return false;
    };
    s.revokeFoundingAccess(7);
    s.removeUserFromGroup(1, 11, 'Member');
    assert.equal(calls.length, 0);
    globalThis.confirm = () => true;
    s.revokeFoundingAccess(7);
    s.removeUserFromGroup(1, 11, 'Member');
    s.rotateJoinLink(2);
    assert.deepEqual(calls, [
        {
            method: 'post',
            url: '/settings/admin/founding-users/revoke',
            data: { user_id: 7 },
            preserveScroll: true,
        },
        {
            method: 'delete',
            url: '/settings/admin/groups/users',
            data: { group_id: 1, user_id: 11 },
            preserveScroll: true,
        },
        {
            method: 'post',
            url: '/settings/admin/groups/join-link',
            data: { group_id: 2 },
            preserveScroll: true,
        },
    ]);
    assert.deepEqual(prompts, [
        'Revoke founding access for this user?',
        'Remove Member from this group?',
    ]);
});
test('server prop updates remain reactive without resetting local form edits', async (t) => {
    const { state: s, props } = setup(t);
    s.limitForm.limit = 50;
    props.foundingUsers = { ...props.foundingUsers, remaining: 0 };
    props.groups = [];
    await nextTick();
    assert.equal(s.foundingUsers.value.remaining, 0);
    assert.deepEqual(s.groups.value, []);
    assert.equal(s.limitForm.limit, 50);
});
