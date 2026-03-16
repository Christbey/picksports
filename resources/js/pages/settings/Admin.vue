<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin settings',
        href: '/settings/admin',
    },
];

interface FoundingUser {
    id: number;
    name: string;
    email: string;
    created_at: string | null;
}

interface FoundingUsersPanel {
    enabled: boolean;
    limit: number;
    used: number;
    remaining: number;
    role: string;
    tier_slug: string;
    tier_name: string;
    users: FoundingUser[];
}

interface GroupInvitation {
    id: number;
    email: string;
    token: string;
    invite_url: string;
    accepted_at: string | null;
    expires_at: string | null;
    created_at: string | null;
}

interface GroupJoinLink {
    token: string;
    join_url: string;
    expires_at: string | null;
    created_at: string | null;
}

interface AdminGroup {
    id: number;
    public_id: string;
    name: string;
    season: number | null;
    members_count: number;
    brackets_count: number;
    join_link: GroupJoinLink | null;
    invitations: GroupInvitation[];
}

const props = defineProps<{
    foundingUsers: FoundingUsersPanel;
    groups: AdminGroup[];
}>();

interface UserLookupResult {
    id: number;
    name: string;
    email: string;
}

const grantForm = useForm({
    email: '',
});
const limitForm = useForm({
    limit: props.foundingUsers.limit,
});
const groupForm = useForm({
    name: '',
    season: new Date().getFullYear(),
});
const inviteForm = useForm({
    group_id: (props.groups[0]?.id ?? null) as number | null,
    email: '',
});

const userSearch = ref('');
const userSuggestions = ref<UserLookupResult[]>([]);
const isSearchingUsers = ref(false);
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;
let searchAbortController: AbortController | null = null;

watch(userSearch, (value) => {
    const query = value.trim();

    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    if (query.length < 2) {
        userSuggestions.value = [];
        isSearchingUsers.value = false;
        return;
    }

    searchDebounceTimer = setTimeout(async () => {
        if (searchAbortController) {
            searchAbortController.abort();
        }

        searchAbortController = new AbortController();
        isSearchingUsers.value = true;

        try {
            const response = await fetch(`/settings/admin/founding-users/search?query=${encodeURIComponent(query)}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
                signal: searchAbortController.signal,
            });

            if (!response.ok) {
                throw new Error('Failed user lookup.');
            }

            const payload = await response.json() as { users?: UserLookupResult[] };
            userSuggestions.value = Array.isArray(payload.users) ? payload.users : [];
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            userSuggestions.value = [];
        } finally {
            isSearchingUsers.value = false;
        }
    }, 250);
});

onBeforeUnmount(() => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    if (searchAbortController) {
        searchAbortController.abort();
    }
});

function selectUserSuggestion(user: UserLookupResult): void {
    grantForm.email = user.email;
    userSearch.value = `${user.name} (${user.email})`;
    userSuggestions.value = [];
}

function grantFoundingAccess(): void {
    grantForm.post('/settings/admin/founding-users/grant', {
        preserveScroll: true,
        onSuccess: () => {
            grantForm.reset();
            userSearch.value = '';
            userSuggestions.value = [];
        },
    });
}

function updateFoundingLimit(): void {
    limitForm.post('/settings/admin/founding-users/limit', {
        preserveScroll: true,
    });
}

function revokeFoundingAccess(userId: number): void {
    if (!confirm('Revoke founding access for this user?')) {
        return;
    }

    router.post('/settings/admin/founding-users/revoke', { user_id: userId }, {
        preserveScroll: true,
    });
}

function createGroup(): void {
    groupForm.post('/settings/admin/groups', {
        preserveScroll: true,
        onSuccess: () => groupForm.reset('name'),
    });
}

function inviteToGroup(): void {
    inviteForm.post('/settings/admin/groups/invite', {
        preserveScroll: true,
        onSuccess: () => inviteForm.reset('email'),
    });
}

function rotateJoinLink(groupId: number): void {
    router.post('/settings/admin/groups/join-link', { group_id: groupId }, {
        preserveScroll: true,
    });
}

function formatDate(date: string | null): string {
    if (!date) {
        return 'N/A';
    }

    return new Date(date).toLocaleString();
}

const adminAreas = [
    {
        title: 'Users',
        description: 'View total users, new users today, and submission inbox.',
        href: '/admin/users',
    },
    {
        title: 'Submissions',
        description: 'Jump directly to form submissions in the admin users view.',
        href: '/admin/users#submissions',
    },
    {
        title: 'Prop Exports',
        description: 'Export player prop cards optimized for Instagram and Facebook.',
        href: '/settings/prop-exports',
    },
    {
        title: 'Subscriptions',
        description: 'Manage user plans and billing access.',
        href: '/admin/subscriptions',
    },
    {
        title: 'Tiers',
        description: 'Configure subscription tiers and feature flags.',
        href: '/admin/tiers',
    },
    {
        title: 'Permissions',
        description: 'Control role capabilities and overrides.',
        href: '/admin/permissions',
    },
    {
        title: 'Healthchecks',
        description: 'Monitor heartbeat and data validation status.',
        href: '/admin/healthchecks',
    },
    {
        title: 'Prediction Access Debug',
        description: 'Inspect tier and permission-based prediction field access.',
        href: '/debug/prediction-access',
    },
    {
        title: 'Team Mappings',
        description: 'Resolve odds provider and internal team mapping gaps.',
        href: '/settings/team-mappings?provider=odds',
    },
    {
        title: 'CFBD Team Mappings',
        description: 'Resolve CollegeFootballData team names to internal CFB teams.',
        href: '/settings/team-mappings?provider=cfbd&sport=americanfootball_ncaaf',
    },
    {
        title: 'Player Mappings',
        description: 'Resolve odds provider player names when fuzzy matching fails.',
        href: '/settings/player-mappings',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Admin settings" />

        <h1 class="sr-only">Admin Settings</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Admin Settings"
                    description="Use this panel to access all admin configuration areas"
                />

                <div class="rounded-xl border border-sidebar-border bg-white p-5 dark:bg-sidebar">
                    <div class="flex flex-col gap-4">
                        <div>
                            <h3 class="text-sm font-semibold">Bracket Groups</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Create March Madness groups, generate invite links, and onboard users directly into bracket pools.
                            </p>
                        </div>

                        <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_160px_auto]" @submit.prevent="createGroup">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground" for="group-name">
                                    Group Name
                                </label>
                                <input
                                    id="group-name"
                                    v-model="groupForm.name"
                                    type="text"
                                    required
                                    class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                                >
                                <p v-if="groupForm.errors.name" class="mt-1 text-xs text-red-600">{{ groupForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground" for="group-season">
                                    Season
                                </label>
                                <input
                                    id="group-season"
                                    v-model.number="groupForm.season"
                                    type="number"
                                    required
                                    class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                                >
                                <p v-if="groupForm.errors.season" class="mt-1 text-xs text-red-600">{{ groupForm.errors.season }}</p>
                            </div>
                            <div class="flex items-end">
                                <Button type="submit" :disabled="groupForm.processing">
                                    {{ groupForm.processing ? 'Creating...' : 'Create Group' }}
                                </Button>
                            </div>
                        </form>

                        <form class="grid gap-3 sm:grid-cols-[220px_minmax(0,1fr)_auto]" @submit.prevent="inviteToGroup">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground" for="invite-group">
                                    Group
                                </label>
                                <select
                                    id="invite-group"
                                    v-model="inviteForm.group_id"
                                    class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                                >
                                    <option :value="null">Select group</option>
                                    <option v-for="group in groups" :key="group.id" :value="group.id">
                                        {{ group.name }}
                                    </option>
                                </select>
                                <p v-if="inviteForm.errors.group_id" class="mt-1 text-xs text-red-600">{{ inviteForm.errors.group_id }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground" for="invite-email">
                                    Invite Email
                                </label>
                                <input
                                    id="invite-email"
                                    v-model="inviteForm.email"
                                    type="email"
                                    required
                                    placeholder="user@example.com"
                                    class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                                >
                                <p v-if="inviteForm.errors.email" class="mt-1 text-xs text-red-600">{{ inviteForm.errors.email }}</p>
                            </div>
                            <div class="flex items-end">
                                <Button type="submit" :disabled="inviteForm.processing || !inviteForm.group_id">
                                    {{ inviteForm.processing ? 'Inviting...' : 'Create Invite' }}
                                </Button>
                            </div>
                        </form>

                        <div class="space-y-4">
                            <div
                                v-for="group in groups"
                                :key="group.id"
                                class="rounded-lg border border-sidebar-border bg-sidebar-accent p-4"
                            >
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h4 class="text-sm font-semibold">{{ group.name }}</h4>
                                        <p class="text-xs text-muted-foreground">
                                            Season {{ group.season ?? 'N/A' }} · {{ group.members_count }} members · {{ group.brackets_count }} brackets
                                        </p>
                                    </div>
                                    <div class="flex gap-2">
                                        <Button type="button" variant="outline" @click="rotateJoinLink(group.id)">
                                            {{ group.join_link ? 'Rotate Join Link' : 'Create Join Link' }}
                                        </Button>
                                    </div>
                                </div>

                                <div class="mt-3 rounded-lg border border-sidebar-border bg-white p-3 dark:bg-sidebar">
                                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Shared Join Link</p>
                                    <div v-if="group.join_link" class="mt-2">
                                        <a :href="group.join_link.join_url" class="text-sm text-primary hover:underline" target="_blank">{{ group.join_link.join_url }}</a>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Anyone with this link can join {{ group.name }} after login or registration.
                                        </p>
                                    </div>
                                    <p v-else class="mt-2 text-sm text-muted-foreground">No shared join link yet.</p>
                                </div>

                                <div class="mt-3 overflow-x-auto rounded-lg border border-sidebar-border bg-white dark:bg-sidebar">
                                    <table class="w-full text-left text-sm">
                                        <thead class="bg-sidebar-accent">
                                            <tr>
                                                <th class="px-3 py-2 font-medium">Invite Email</th>
                                                <th class="px-3 py-2 font-medium">Link</th>
                                                <th class="px-3 py-2 font-medium">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-if="group.invitations.length === 0">
                                                <td colspan="3" class="px-3 py-3 text-muted-foreground">No invites yet.</td>
                                            </tr>
                                            <tr v-for="invite in group.invitations" :key="invite.id" class="border-t border-sidebar-border">
                                                <td class="px-3 py-2">{{ invite.email }}</td>
                                                <td class="px-3 py-2">
                                                    <a :href="invite.invite_url" class="text-primary hover:underline" target="_blank">{{ invite.invite_url }}</a>
                                                </td>
                                                <td class="px-3 py-2">{{ invite.accepted_at ? `Accepted ${formatDate(invite.accepted_at)}` : 'Pending' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border bg-white p-5 dark:bg-sidebar">
                    <div class="flex flex-col gap-4">
                        <div>
                            <h3 class="text-sm font-semibold">Founding Users</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Grant or revoke the <code>{{ foundingUsers.role }}</code> role with access level <code>{{ foundingUsers.tier_name }}</code>.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                            <div class="rounded-lg border border-sidebar-border bg-sidebar-accent p-3">
                                <p class="text-xs text-muted-foreground">Program</p>
                                <p class="text-sm font-semibold">{{ foundingUsers.enabled ? 'Enabled' : 'Disabled' }}</p>
                            </div>
                            <div class="rounded-lg border border-sidebar-border bg-sidebar-accent p-3">
                                <p class="text-xs text-muted-foreground">Limit</p>
                                <p class="text-sm font-semibold">{{ foundingUsers.limit }}</p>
                            </div>
                            <div class="rounded-lg border border-sidebar-border bg-sidebar-accent p-3">
                                <p class="text-xs text-muted-foreground">Used</p>
                                <p class="text-sm font-semibold">{{ foundingUsers.used }}</p>
                            </div>
                            <div class="rounded-lg border border-sidebar-border bg-sidebar-accent p-3">
                                <p class="text-xs text-muted-foreground">Remaining</p>
                                <p class="text-sm font-semibold">{{ foundingUsers.remaining }}</p>
                            </div>
                        </div>

                        <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="updateFoundingLimit">
                            <div class="w-full sm:max-w-xs">
                                <label class="mb-1 block text-xs font-medium text-muted-foreground" for="founding-user-limit">
                                    Founding User Limit (X)
                                </label>
                                <input
                                    id="founding-user-limit"
                                    v-model.number="limitForm.limit"
                                    type="number"
                                    min="0"
                                    step="1"
                                    required
                                    class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                                >
                                <p v-if="limitForm.errors.limit" class="mt-1 text-xs text-red-600">{{ limitForm.errors.limit }}</p>
                            </div>
                            <Button type="submit" :disabled="limitForm.processing">
                                {{ limitForm.processing ? 'Saving...' : 'Save Limit' }}
                            </Button>
                        </form>

                        <form class="flex flex-col gap-3 sm:flex-row sm:items-start" @submit.prevent="grantFoundingAccess">
                            <div class="relative flex-1">
                                <label class="mb-1 block text-xs font-medium text-muted-foreground" for="founding-user-search">
                                    User Search
                                </label>
                                <input
                                    id="founding-user-search"
                                    v-model="userSearch"
                                    type="text"
                                    required
                                    placeholder="Search by name or email (min 2 chars)"
                                    autocomplete="off"
                                    class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                                >
                                <div
                                    v-if="isSearchingUsers || userSuggestions.length > 0"
                                    class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-sidebar-border bg-white shadow-lg dark:bg-sidebar"
                                >
                                    <div v-if="isSearchingUsers" class="px-3 py-2 text-xs text-muted-foreground">
                                        Searching...
                                    </div>
                                    <button
                                        v-for="user in userSuggestions"
                                        :key="user.id"
                                        type="button"
                                        class="block w-full border-t border-sidebar-border px-3 py-2 text-left text-sm hover:bg-sidebar-accent first:border-t-0"
                                        @click="selectUserSuggestion(user)"
                                    >
                                        <span class="font-medium">{{ user.name }}</span>
                                        <span class="ml-1 text-muted-foreground">{{ user.email }}</span>
                                    </button>
                                </div>
                            </div>
                            <div class="flex-1">
                                <label class="mb-1 block text-xs font-medium text-muted-foreground" for="founding-user-email">
                                    Selected Email
                                </label>
                                <input
                                    id="founding-user-email"
                                    v-model="grantForm.email"
                                    type="email"
                                    required
                                    placeholder="user@example.com"
                                    class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                                >
                                <p v-if="grantForm.errors.email" class="mt-1 text-xs text-red-600">{{ grantForm.errors.email }}</p>
                            </div>
                            <Button type="submit" :disabled="grantForm.processing || !foundingUsers.enabled || foundingUsers.remaining < 1">
                                {{ grantForm.processing ? 'Granting...' : 'Grant Founding Access' }}
                            </Button>
                        </form>

                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Current Founding Users</h4>
                            <div class="mt-2 overflow-x-auto rounded-lg border border-sidebar-border">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-sidebar-accent">
                                        <tr>
                                            <th class="px-3 py-2 font-medium">Name</th>
                                            <th class="px-3 py-2 font-medium">Email</th>
                                            <th class="px-3 py-2 font-medium">Created</th>
                                            <th class="px-3 py-2 font-medium">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="foundingUsers.users.length === 0">
                                            <td colspan="4" class="px-3 py-3 text-muted-foreground">No founding users assigned yet.</td>
                                        </tr>
                                        <tr v-for="user in foundingUsers.users" :key="user.id" class="border-t border-sidebar-border">
                                            <td class="px-3 py-2">{{ user.name }}</td>
                                            <td class="px-3 py-2">{{ user.email }}</td>
                                            <td class="px-3 py-2">{{ formatDate(user.created_at) }}</td>
                                            <td class="px-3 py-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    class="h-8"
                                                    @click="revokeFoundingAccess(user.id)"
                                                >
                                                    Revoke
                                                </Button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div
                        v-for="area in adminAreas"
                        :key="area.href"
                        class="rounded-xl border border-sidebar-border bg-white p-5 dark:bg-sidebar"
                    >
                        <h3 class="text-sm font-semibold">{{ area.title }}</h3>
                        <p class="mt-2 text-sm text-muted-foreground">{{ area.description }}</p>
                        <Button as-child variant="outline" class="mt-4">
                            <Link :href="area.href">Open</Link>
                        </Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
