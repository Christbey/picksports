<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Permission {
    id: number;
    name: string;
}

interface Tier {
    id: number;
    name: string;
    slug: string;
    users_count: number;
    permissions: string[];
}

const props = defineProps<{
    roles: Array<{ id: number; name: string; users_count: number; permissions: string[] }>;
    tiers: Tier[];
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

const savingTierId = ref<number | null>(null);

const selectedPermissionsByTier = ref<Record<number, string[]>>(
    props.tiers.reduce<Record<number, string[]>>((acc, tier) => {
        acc[tier.id] = [...tier.permissions].sort();
        return acc;
    }, {}),
);

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
    if (permission.startsWith('view-prediction-')) return 'Prediction Data';
    if (permission.startsWith('view-')) return 'View Access';
    if (permission.startsWith('access-')) return 'Feature Access';
    if (permission.startsWith('receive-')) return 'Notifications';
    if (permission.startsWith('export-')) return 'Exports';
    return 'Other';
}

const groupedPermissions = computed<Record<string, Permission[]>>(() => {
    const grouped: Record<string, Permission[]> = {};

    props.permissions.forEach(permission => {
        const category = getPermissionCategory(permission.name);
        if (!grouped[category]) {
            grouped[category] = [];
        }
        grouped[category].push(permission);
    });

    return grouped;
});

function hasPermission(tierId: number, permissionName: string): boolean {
    return (selectedPermissionsByTier.value[tierId] ?? []).includes(permissionName);
}

function togglePermission(tierId: number, permissionName: string): void {
    const currentPermissions = selectedPermissionsByTier.value[tierId] ?? [];

    if (currentPermissions.includes(permissionName)) {
        selectedPermissionsByTier.value[tierId] = currentPermissions.filter(permission => permission !== permissionName);
        return;
    }

    selectedPermissionsByTier.value[tierId] = [...currentPermissions, permissionName].sort();
}

function saveTierPermissions(tier: Tier): void {
    savingTierId.value = tier.id;

    router.patch(`/admin/permissions/tiers/${tier.id}`, {
        permissions: selectedPermissionsByTier.value[tier.id] ?? [],
    }, {
        preserveScroll: true,
        onFinish: () => {
            savingTierId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Manage Permissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div>
                <h1 class="text-2xl font-bold">Manage Tier Permissions</h1>
                <p class="mt-1 text-muted-foreground">
                    Edit tier permissions directly. Saving a tier updates and syncs its matching role automatically.
                </p>
            </div>

            <div class="space-y-4">
                <div
                    v-for="tier in tiers"
                    :key="tier.id"
                    class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
                >
                    <div class="mb-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <span
                                :class="['inline-block rounded-full px-3 py-1 text-sm font-medium capitalize', getRoleBadgeColor(tier.slug)]"
                            >
                                {{ tier.name }}
                            </span>
                            <span class="text-sm text-muted-foreground">
                                {{ tier.users_count }} {{ tier.users_count === 1 ? 'user' : 'users' }}
                            </span>
                        </div>
                        <button
                            type="button"
                            :disabled="savingTierId === tier.id"
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="saveTierPermissions(tier)"
                        >
                            {{ savingTierId === tier.id ? 'Saving...' : 'Save Tier Permissions' }}
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div
                            v-for="(categoryPermissions, category) in groupedPermissions"
                            :key="`${tier.id}-${category}`"
                            class="rounded-lg border border-sidebar-border bg-sidebar-accent p-4"
                        >
                            <h3 class="mb-3 text-sm font-semibold">{{ category }}</h3>
                            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                <label
                                    v-for="permission in categoryPermissions"
                                    :key="permission.id"
                                    class="flex cursor-pointer items-center gap-2 rounded-md bg-white px-3 py-2 text-sm dark:bg-sidebar"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="hasPermission(tier.id, permission.name)"
                                        class="h-4 w-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                        @change="togglePermission(tier.id, permission.name)"
                                    >
                                    <span>{{ formatPermissionName(permission.name) }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
