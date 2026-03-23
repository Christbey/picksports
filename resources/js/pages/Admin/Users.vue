<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Stats {
    total_users: number;
    new_users_today: number;
    active_users_now: number;
    total_submissions: number;
    submissions_today: number;
}

interface UserRow {
    id: number;
    name: string;
    email: string;
    created_at: string | null;
    last_active_at: string | null;
    is_active: boolean;
}

interface SubmissionRow {
    id: number;
    name: string | null;
    email: string | null;
    subject: string | null;
    message: string;
    page_url: string | null;
    status: string;
    created_at: string | null;
}

interface PaginationMeta<T> {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
}

interface Filters {
    status: 'all' | 'active' | 'offline';
    sort:
        | 'created_desc'
        | 'created_asc'
        | 'last_active_desc'
        | 'last_active_asc'
        | 'name_asc'
        | 'name_desc';
}

interface Meta {
    active_window_minutes: number;
    server_time: string;
}

const props = defineProps<{
    stats: Stats;
    filters: Filters;
    meta: Meta;
    users: PaginationMeta<UserRow>;
    submissions: PaginationMeta<SubmissionRow>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin/users',
    },
    {
        title: 'Users',
        href: '/admin/users',
    },
];

const statusFilter = ref<Filters['status']>(props.filters.status);
const sortBy = ref<Filters['sort']>(props.filters.sort);
const nowMs = ref(Date.parse(props.meta.server_time));
let relativeTimeTimer: number | null = null;
let activityReloadTimer: number | null = null;

function formatDate(date: string | null): string {
    if (!date) {
        return 'N/A';
    }

    return new Date(date).toLocaleString();
}

function formatRelativeTime(date: string | null): string {
    if (!date) {
        return 'Never';
    }

    const diffMs = nowMs.value - Date.parse(date);

    if (diffMs < 60_000) {
        return 'Just now';
    }

    const minutes = Math.floor(diffMs / 60_000);

    if (minutes < 60) {
        return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    }

    const days = Math.floor(hours / 24);

    return `${days} day${days === 1 ? '' : 's'} ago`;
}

const activeStatusLabel = computed(
    () => `Within ${props.meta.active_window_minutes} minutes`,
);

watch([statusFilter, sortBy], ([status, sort]) => {
    router.get(
        '/admin/users',
        {
            status,
            sort,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
});

watch(
    () => props.filters.status,
    (value) => {
        statusFilter.value = value;
    },
);

watch(
    () => props.filters.sort,
    (value) => {
        sortBy.value = value;
    },
);

watch(
    () => props.meta.server_time,
    (value) => {
        nowMs.value = Date.parse(value);
    },
);

onMounted(() => {
    relativeTimeTimer = window.setInterval(() => {
        nowMs.value = Date.now();
    }, 60_000);

    activityReloadTimer = window.setInterval(() => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        router.reload({
            only: ['stats', 'users', 'filters', 'meta'],
        });
    }, 60_000);
});

onBeforeUnmount(() => {
    if (relativeTimeTimer !== null) {
        window.clearInterval(relativeTimeTimer);
    }

    if (activityReloadTimer !== null) {
        window.clearInterval(activityReloadTimer);
    }
});

function previewMessage(value: string): string {
    if (value.length <= 140) {
        return value;
    }

    return `${value.slice(0, 140)}...`;
}
</script>

<template>
    <Head title="Users & Submissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :full-width="true">
            <div
                class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
            >
                <div>
                    <h1 class="text-2xl font-bold">Users & Submissions</h1>
                    <p class="mt-1 text-muted-foreground">
                        Monitor user growth, current activity, and incoming
                        feedback submissions.
                    </p>
                </div>

                <div
                    class="flex flex-col gap-3 rounded-xl border border-sidebar-border bg-white p-4 md:flex-row md:items-end dark:bg-sidebar"
                >
                    <div class="flex-1">
                        <label
                            for="status-filter"
                            class="mb-2 block text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Status Filter
                        </label>
                        <select
                            id="status-filter"
                            v-model="statusFilter"
                            class="w-full rounded-lg border border-sidebar-border bg-background px-3 py-2 text-sm"
                        >
                            <option value="all">All users</option>
                            <option value="active">Active now</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label
                            for="sort-by"
                            class="mb-2 block text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Sort By
                        </label>
                        <select
                            id="sort-by"
                            v-model="sortBy"
                            class="w-full rounded-lg border border-sidebar-border bg-background px-3 py-2 text-sm"
                        >
                            <option value="created_desc">Newest users</option>
                            <option value="created_asc">Oldest users</option>
                            <option value="last_active_desc">
                                Most recently active
                            </option>
                            <option value="last_active_asc">
                                Least recently active
                            </option>
                            <option value="name_asc">Name A-Z</option>
                            <option value="name_desc">Name Z-A</option>
                        </select>
                    </div>
                    <div class="text-sm text-muted-foreground">
                        Active now:
                        <span class="font-medium text-foreground">{{
                            activeStatusLabel
                        }}</span>
                    </div>
                </div>

                <div
                    class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5"
                >
                    <div
                        class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Total Users
                        </p>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ props.stats.total_users }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            New Users Today
                        </p>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ props.stats.new_users_today }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Active Now
                        </p>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ props.stats.active_users_now }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Total Submissions
                        </p>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ props.stats.total_submissions }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Submissions Today
                        </p>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ props.stats.submissions_today }}
                        </p>
                    </div>
                </div>

                <div
                    id="submissions"
                    class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar"
                >
                    <div class="border-b border-sidebar-border px-4 py-3">
                        <h2 class="font-semibold">Users</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-sidebar-accent">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">
                                        Name
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Email
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Status
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Last Active
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Created
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="user in props.users.data"
                                    :key="user.id"
                                    class="border-t border-sidebar-border"
                                >
                                    <td class="px-4 py-3 font-medium">
                                        {{ user.name }}
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">
                                        {{ user.email }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold tracking-wide uppercase"
                                            :class="
                                                user.is_active
                                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                                    : 'bg-sidebar-accent text-foreground'
                                            "
                                        >
                                            {{
                                                user.is_active
                                                    ? 'Active now'
                                                    : 'Offline'
                                            }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div>
                                            {{
                                                formatRelativeTime(
                                                    user.last_active_at,
                                                )
                                            }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                formatDate(user.last_active_at)
                                            }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ formatDate(user.created_at) }}
                                    </td>
                                </tr>
                                <tr v-if="props.users.data.length === 0">
                                    <td
                                        colspan="5"
                                        class="px-4 py-4 text-center text-muted-foreground"
                                    >
                                        No users found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-sidebar-border px-4 py-3 text-sm"
                    >
                        <span class="text-muted-foreground"
                            >Total: {{ props.users.total }}</span
                        >
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="props.users.prev_page_url"
                                :href="props.users.prev_page_url"
                                class="rounded-md border border-sidebar-border px-3 py-1.5 hover:bg-sidebar-accent"
                                preserve-scroll
                            >
                                Previous
                            </Link>
                            <span
                                >Page {{ props.users.current_page }} of
                                {{ props.users.last_page }}</span
                            >
                            <Link
                                v-if="props.users.next_page_url"
                                :href="props.users.next_page_url"
                                class="rounded-md border border-sidebar-border px-3 py-1.5 hover:bg-sidebar-accent"
                                preserve-scroll
                            >
                                Next
                            </Link>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar"
                >
                    <div class="border-b border-sidebar-border px-4 py-3">
                        <h2 class="font-semibold">Form Submissions</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-sidebar-accent">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">
                                        From
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Subject
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Message
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Page
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Status
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        Created
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="submission in props.submissions.data"
                                    :key="submission.id"
                                    class="border-t border-sidebar-border"
                                >
                                    <td class="px-4 py-3">
                                        <div class="font-medium">
                                            {{ submission.name ?? 'Unknown' }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ submission.email ?? 'N/A' }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ submission.subject ?? 'N/A' }}
                                    </td>
                                    <td class="max-w-md px-4 py-3">
                                        {{ previewMessage(submission.message) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <a
                                            v-if="submission.page_url"
                                            :href="submission.page_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="underline underline-offset-2"
                                        >
                                            Open
                                        </a>
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >N/A</span
                                        >
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full bg-sidebar-accent px-2 py-0.5 text-xs font-semibold tracking-wide uppercase"
                                        >
                                            {{ submission.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ formatDate(submission.created_at) }}
                                    </td>
                                </tr>
                                <tr v-if="props.submissions.data.length === 0">
                                    <td
                                        colspan="6"
                                        class="px-4 py-4 text-center text-muted-foreground"
                                    >
                                        No submissions found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-sidebar-border px-4 py-3 text-sm"
                    >
                        <span class="text-muted-foreground"
                            >Total: {{ props.submissions.total }}</span
                        >
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="props.submissions.prev_page_url"
                                :href="props.submissions.prev_page_url"
                                class="rounded-md border border-sidebar-border px-3 py-1.5 hover:bg-sidebar-accent"
                                preserve-scroll
                            >
                                Previous
                            </Link>
                            <span
                                >Page {{ props.submissions.current_page }} of
                                {{ props.submissions.last_page }}</span
                            >
                            <Link
                                v-if="props.submissions.next_page_url"
                                :href="props.submissions.next_page_url"
                                class="rounded-md border border-sidebar-border px-3 py-1.5 hover:bg-sidebar-accent"
                                preserve-scroll
                            >
                                Next
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
