<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Stats {
    total_users: number;
    new_users_today: number;
    total_submissions: number;
    submissions_today: number;
}

interface UserRow {
    id: number;
    name: string;
    email: string;
    created_at: string | null;
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

const props = defineProps<{
    stats: Stats;
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

function formatDate(date: string | null): string {
    if (!date) {
        return 'N/A';
    }

    return new Date(date).toLocaleString();
}

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
            <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                <div>
                    <h1 class="text-2xl font-bold">Users & Submissions</h1>
                    <p class="mt-1 text-muted-foreground">
                        Monitor user growth and incoming feedback submissions.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Total Users</p>
                        <p class="mt-2 text-2xl font-semibold">{{ props.stats.total_users }}</p>
                    </div>
                    <div class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">New Users Today</p>
                        <p class="mt-2 text-2xl font-semibold">{{ props.stats.new_users_today }}</p>
                    </div>
                    <div class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Total Submissions</p>
                        <p class="mt-2 text-2xl font-semibold">{{ props.stats.total_submissions }}</p>
                    </div>
                    <div class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Submissions Today</p>
                        <p class="mt-2 text-2xl font-semibold">{{ props.stats.submissions_today }}</p>
                    </div>
                </div>

                <div id="submissions" class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar">
                    <div class="border-b border-sidebar-border px-4 py-3">
                        <h2 class="font-semibold">Users</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-sidebar-accent">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Name</th>
                                    <th class="px-4 py-3 font-semibold">Email</th>
                                    <th class="px-4 py-3 font-semibold">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="user in props.users.data"
                                    :key="user.id"
                                    class="border-t border-sidebar-border"
                                >
                                    <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ user.email }}</td>
                                    <td class="px-4 py-3">{{ formatDate(user.created_at) }}</td>
                                </tr>
                                <tr v-if="props.users.data.length === 0">
                                    <td colspan="3" class="px-4 py-4 text-center text-muted-foreground">
                                        No users found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center justify-between border-t border-sidebar-border px-4 py-3 text-sm">
                        <span class="text-muted-foreground">Total: {{ props.users.total }}</span>
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="props.users.prev_page_url"
                                :href="props.users.prev_page_url"
                                class="rounded-md border border-sidebar-border px-3 py-1.5 hover:bg-sidebar-accent"
                                preserve-scroll
                            >
                                Previous
                            </Link>
                            <span>Page {{ props.users.current_page }} of {{ props.users.last_page }}</span>
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

                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar">
                    <div class="border-b border-sidebar-border px-4 py-3">
                        <h2 class="font-semibold">Form Submissions</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-sidebar-accent">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">From</th>
                                    <th class="px-4 py-3 font-semibold">Subject</th>
                                    <th class="px-4 py-3 font-semibold">Message</th>
                                    <th class="px-4 py-3 font-semibold">Page</th>
                                    <th class="px-4 py-3 font-semibold">Status</th>
                                    <th class="px-4 py-3 font-semibold">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="submission in props.submissions.data"
                                    :key="submission.id"
                                    class="border-t border-sidebar-border"
                                >
                                    <td class="px-4 py-3">
                                        <div class="font-medium">{{ submission.name ?? 'Unknown' }}</div>
                                        <div class="text-xs text-muted-foreground">{{ submission.email ?? 'N/A' }}</div>
                                    </td>
                                    <td class="px-4 py-3">{{ submission.subject ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 max-w-md">{{ previewMessage(submission.message) }}</td>
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
                                        <span v-else class="text-muted-foreground">N/A</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full bg-sidebar-accent px-2 py-0.5 text-xs font-semibold uppercase tracking-wide">
                                            {{ submission.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">{{ formatDate(submission.created_at) }}</td>
                                </tr>
                                <tr v-if="props.submissions.data.length === 0">
                                    <td colspan="6" class="px-4 py-4 text-center text-muted-foreground">
                                        No submissions found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center justify-between border-t border-sidebar-border px-4 py-3 text-sm">
                        <span class="text-muted-foreground">Total: {{ props.submissions.total }}</span>
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="props.submissions.prev_page_url"
                                :href="props.submissions.prev_page_url"
                                class="rounded-md border border-sidebar-border px-3 py-1.5 hover:bg-sidebar-accent"
                                preserve-scroll
                            >
                                Previous
                            </Link>
                            <span>Page {{ props.submissions.current_page }} of {{ props.submissions.last_page }}</span>
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
