<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type AppPageProps, type BreadcrumbItem } from '@/types';

interface Subscription {
    stripe_id: string;
    stripe_status: string;
    stripe_price: string | null;
    trial_ends_at: string | null;
    ends_at: string | null;
    created_at: string;
}

interface User {
    id: number;
    name: string;
    email: string;
    tier: string;
    subscription: Subscription | null;
}

interface PaginatedUsers {
    data: User[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Tier {
    id: number;
    name: string;
    slug: string;
}

const props = defineProps<{
    users: PaginatedUsers;
    tiers: Tier[];
    filters: {
        search: string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin/subscriptions',
    },
    {
        title: 'Subscriptions',
        href: '/admin/subscriptions',
    },
];

const search = ref(props.filters.search || '');
const isSyncing = ref(false);
const syncingUserId = ref<number | null>(null);
const assigningTierUserId = ref<number | null>(null);
const impersonatingUserId = ref<number | null>(null);

const page = usePage<AppPageProps>();
const currentUserId = computed(() => page.props.auth.user.id);

watch(search, (value) => {
    router.get('/admin/subscriptions', { search: value }, {
        preserveState: true,
        replace: true,
    });
});

function syncUser(userId: number) {
    syncingUserId.value = userId;
    router.post(`/admin/subscriptions/${userId}/sync`, {}, {
        onFinish: () => {
            syncingUserId.value = null;
        },
    });
}

function syncAll() {
    if (! confirm('Are you sure you want to sync all subscriptions with Stripe? This may take a while.')) {
        return;
    }

    isSyncing.value = true;
    router.post('/admin/subscriptions/sync-all', {}, {
        onFinish: () => {
            isSyncing.value = false;
        },
    });
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'active':
            return 'text-green-600 dark:text-green-400';
        case 'canceled':
        case 'cancelled':
            return 'text-yellow-600 dark:text-yellow-400';
        case 'past_due':
        case 'unpaid':
            return 'text-red-600 dark:text-red-400';
        case 'trialing':
            return 'text-blue-600 dark:text-blue-400';
        default:
            return 'text-muted-foreground';
    }
}

function formatDate(dateString: string | null): string {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function assignTier(userId: number, tierSlug: string) {
    if (!tierSlug) return;

    if (!confirm(`Are you sure you want to assign ${tierSlug} tier to this user?`)) {
        return;
    }

    assigningTierUserId.value = userId;
    router.post(`/admin/subscriptions/${userId}/assign-tier`, {
        tier_slug: tierSlug,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            console.log('Tier assigned successfully');
        },
        onError: (errors) => {
            console.error('Error assigning tier:', errors);
        },
        onFinish: () => {
            assigningTierUserId.value = null;
        },
    });
}

function impersonateUser(user: User) {
    if (user.id === currentUserId.value) {
        return;
    }

    if (!confirm(`Impersonate ${user.name} (${user.email})?`)) {
        return;
    }

    impersonatingUserId.value = user.id;
    router.post(`/admin/impersonation/${user.id}`, {}, {
        onFinish: () => {
            impersonatingUserId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Manage Subscriptions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :full-width="true">
            <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Manage Subscriptions</h1>
                    <p class="mt-1 text-muted-foreground">
                        View and manage all user subscriptions
                    </p>
                </div>
                <button
                    @click="syncAll"
                    :disabled="isSyncing"
                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ isSyncing ? 'Syncing...' : 'Sync All with Stripe' }}
                </button>
            </div>

            <div class="flex gap-4">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by name or email..."
                    class="flex-1 rounded-lg border border-sidebar-border bg-white px-4 py-2 dark:bg-sidebar focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border bg-white dark:bg-sidebar">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-sidebar-border bg-sidebar-accent text-left text-sm">
                            <th class="p-4 font-semibold">User</th>
                            <th class="p-4 font-semibold">Current Tier</th>
                            <th class="p-4 font-semibold">Assign Tier</th>
                            <th class="p-4 font-semibold">Status</th>
                            <th class="p-4 font-semibold">Stripe ID</th>
                            <th class="p-4 font-semibold">Created</th>
                            <th class="p-4 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users.data"
                            :key="user.id"
                            class="border-b border-sidebar-border last:border-0 hover:bg-sidebar-accent/50 transition-colors"
                        >
                            <td class="p-4">
                                <div class="font-medium">{{ user.name }}</div>
                                <div class="text-sm text-muted-foreground">{{ user.email }}</div>
                            </td>
                            <td class="p-4">
                                <span class="inline-block rounded-full bg-sidebar-accent px-3 py-1 text-sm font-medium capitalize">
                                    {{ user.tier }}
                                </span>
                            </td>
                            <td class="p-4">
                                <select
                                    :value="user.tier.toLowerCase()"
                                    @change="(e) => {
                                        const target = e.target as HTMLSelectElement;
                                        if (target.value && target.value !== user.tier.toLowerCase()) {
                                            assignTier(user.id, target.value);
                                        }
                                    }"
                                    :disabled="assigningTierUserId === user.id"
                                    class="rounded-lg border border-sidebar-border bg-white px-3 py-1 text-sm dark:bg-sidebar focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <option value="">Select tier...</option>
                                    <option
                                        v-for="tier in tiers"
                                        :key="tier.id"
                                        :value="tier.slug"
                                    >
                                        {{ tier.name }}
                                    </option>
                                </select>
                            </td>
                            <td class="p-4">
                                <span
                                    v-if="user.subscription"
                                    :class="['font-medium capitalize', getStatusColor(user.subscription.stripe_status)]"
                                >
                                    {{ user.subscription.stripe_status }}
                                </span>
                                <span v-else class="text-muted-foreground">No subscription</span>
                            </td>
                            <td class="p-4">
                                <span v-if="user.subscription" class="font-mono text-sm">
                                    {{ user.subscription.stripe_id }}
                                </span>
                                <span v-else class="text-muted-foreground">-</span>
                            </td>
                            <td class="p-4 text-sm">
                                <span v-if="user.subscription">
                                    {{ formatDate(user.subscription.created_at) }}
                                </span>
                                <span v-else class="text-muted-foreground">-</span>
                            </td>
                            <td class="p-4">
                                <div class="flex items-center gap-2">
                                    <button
                                        v-if="user.subscription"
                                        @click="syncUser(user.id)"
                                        :disabled="syncingUserId === user.id"
                                        class="rounded-lg bg-sidebar-accent px-3 py-1 text-sm font-medium transition-colors hover:bg-sidebar-accent/80 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ syncingUserId === user.id ? 'Syncing...' : 'Sync' }}
                                    </button>
                                    <button
                                        v-if="user.id !== currentUserId"
                                        @click="impersonateUser(user)"
                                        :disabled="impersonatingUserId === user.id"
                                        class="rounded-lg bg-amber-100 px-3 py-1 text-sm font-medium text-amber-900 transition-colors hover:bg-amber-200 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ impersonatingUserId === user.id ? 'Starting...' : 'Impersonate' }}
                                    </button>
                                    <span v-else class="text-muted-foreground text-sm">Current user</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="users.last_page > 1" class="flex items-center justify-between">
                <div class="text-sm text-muted-foreground">
                    Showing {{ users.data.length }} of {{ users.total }} users
                </div>
                <div class="flex gap-2">
                    <button
                        v-for="page in users.last_page"
                        :key="page"
                        @click="router.get(`/admin/subscriptions?page=${page}&search=${search}`)"
                        :class="[
                            'rounded-lg px-3 py-1 text-sm font-medium transition-colors',
                            page === users.current_page
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-sidebar-accent hover:bg-sidebar-accent/80',
                        ]"
                    >
                        {{ page }}
                    </button>
                </div>
            </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
