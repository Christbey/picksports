<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { ref } from 'vue';

interface Permission {
    id: number;
    name: string;
}

interface Role {
    id: number;
    name: string;
    users_count: number;
    permissions: string[];
}

const props = defineProps<{
    roles: Role[];
    permissions: Permission[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin/permissions',
    },
    {
        title: 'Permissions',
        href: '/admin/permissions',
    },
];

const expandedRoles = ref<Set<number>>(new Set());

function toggleRole(roleId: number) {
    if (expandedRoles.value.has(roleId)) {
        expandedRoles.value.delete(roleId);
    } else {
        expandedRoles.value.add(roleId);
    }
}

function getRoleBadgeColor(roleName: string): string {
    switch (roleName) {
        case 'free':
            return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
        case 'basic':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300';
        case 'pro':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300';
        case 'premium':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300';
        default:
            return 'bg-sidebar-accent text-foreground';
    }
}

function formatPermissionName(permission: string): string {
    return permission
        .split('-')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function getPermissionCategory(permission: string): string {
    if (permission.startsWith('view-')) return 'View Access';
    if (permission.startsWith('access-')) return 'Feature Access';
    if (permission.startsWith('receive-')) return 'Notifications';
    return 'Other';
}

function groupPermissionsByCategory(permissions: Permission[]): Record<string, Permission[]> {
    const grouped: Record<string, Permission[]> = {};

    permissions.forEach(permission => {
        const category = getPermissionCategory(permission.name);
        if (!grouped[category]) {
            grouped[category] = [];
        }
        grouped[category].push(permission);
    });

    return grouped;
}

const groupedPermissions = groupPermissionsByCategory(props.permissions);
</script>

<template>
    <Head title="Manage Permissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Manage Permissions</h1>
                    <p class="mt-1 text-muted-foreground">
                        View roles and permissions across the application
                    </p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Roles Section -->
                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6">
                    <h2 class="mb-4 text-lg font-semibold">Roles</h2>
                    <p class="mb-4 text-sm text-muted-foreground">
                        Roles are automatically synced from subscription tiers. Each role is assigned specific permissions.
                    </p>

                    <div class="space-y-3">
                        <div
                            v-for="role in roles"
                            :key="role.id"
                            class="rounded-lg border border-sidebar-border bg-sidebar-accent p-4"
                        >
                            <button
                                @click="toggleRole(role.id)"
                                class="flex w-full items-center justify-between text-left"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        :class="['inline-block rounded-full px-3 py-1 text-sm font-medium capitalize', getRoleBadgeColor(role.name)]"
                                    >
                                        {{ role.name }}
                                    </span>
                                    <span class="text-sm text-muted-foreground">
                                        {{ role.users_count }} {{ role.users_count === 1 ? 'user' : 'users' }}
                                    </span>
                                </div>
                                <svg
                                    :class="['h-5 w-5 transition-transform', expandedRoles.has(role.id) ? 'rotate-180' : '']"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <div
                                v-if="expandedRoles.has(role.id)"
                                class="mt-3 space-y-2 border-t border-sidebar-border pt-3"
                            >
                                <p class="text-sm font-medium">Permissions ({{ role.permissions.length }}):</p>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="permission in role.permissions"
                                        :key="permission"
                                        class="inline-block rounded-md bg-white dark:bg-sidebar px-2 py-1 text-xs"
                                    >
                                        {{ formatPermissionName(permission) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Permissions Section -->
                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6">
                    <h2 class="mb-4 text-lg font-semibold">All Permissions</h2>
                    <p class="mb-4 text-sm text-muted-foreground">
                        All available permissions in the system, grouped by category.
                    </p>

                    <div class="space-y-4">
                        <div
                            v-for="(categoryPermissions, category) in groupedPermissions"
                            :key="category"
                            class="rounded-lg border border-sidebar-border bg-sidebar-accent p-4"
                        >
                            <h3 class="mb-3 text-sm font-semibold">{{ category }}</h3>
                            <div class="space-y-1">
                                <div
                                    v-for="permission in categoryPermissions"
                                    :key="permission.id"
                                    class="flex items-center justify-between rounded-md bg-white dark:bg-sidebar px-3 py-2 text-sm"
                                >
                                    <span>{{ formatPermissionName(permission.name) }}</span>
                                    <span class="font-mono text-xs text-muted-foreground">{{ permission.name }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Info Box -->
            <div class="rounded-xl border border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950 p-4">
                <div class="flex gap-3">
                    <svg class="h-5 w-5 flex-shrink-0 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="text-sm text-blue-900 dark:text-blue-100">
                        <p class="font-medium">How Permissions Work</p>
                        <p class="mt-1">
                            Roles are automatically created from subscription tiers. When users subscribe or change their subscription,
                            their role is automatically updated via Stripe webhooks. Permissions are assigned to roles based on the tier's features.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
