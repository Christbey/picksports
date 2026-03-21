<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface EffectiveFieldAccess {
    permission: string | null;
    from_tier_data_permissions: boolean;
    from_spatie_permissions: boolean;
    effective: boolean;
}

interface DebugPayload {
    sport: string | null;
    user_id: number;
    tier: {
        slug: string | null;
        name: string | null;
        data_permissions: string[];
        role_synced: boolean;
    };
    role_names: string[];
    permission_names: string[];
    effective_access: Record<string, EffectiveFieldAccess>;
}

const props = defineProps<{
    sports: string[];
    selectedSport: string;
    debug: DebugPayload;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Prediction Access Debug',
        href: '/debug/prediction-access',
    },
];

function sportHref(sport: string): string {
    return `/debug/prediction-access?sport=${encodeURIComponent(sport)}`;
}

function formatField(field: string): string {
    return field
        .split('_')
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' ');
}
</script>

<template>
    <Head title="Prediction Access Debug" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
            <div
                class="rounded-xl border border-sidebar-border bg-white p-5 dark:bg-sidebar"
            >
                <h1 class="text-xl font-semibold">Prediction Access Debug</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Inspect effective prediction field access for your
                    authenticated account.
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    Effective access is enforced by Spatie permissions. Tier
                    data permissions are shown for sync diagnostics.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Link
                    v-for="sport in props.sports"
                    :key="sport"
                    :href="sportHref(sport)"
                    class="rounded-md border px-3 py-1.5 text-sm capitalize"
                    :class="
                        sport === props.selectedSport
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-sidebar-border hover:bg-sidebar-accent'
                    "
                >
                    {{ sport }}
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div
                    class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                >
                    <h2 class="text-sm font-semibold">User</h2>
                    <p class="mt-2 text-sm">ID: {{ props.debug.user_id }}</p>
                    <p class="mt-1 text-sm">
                        Sport: {{ props.debug.sport ?? props.selectedSport }}
                    </p>
                </div>

                <div
                    class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                >
                    <h2 class="text-sm font-semibold">Tier</h2>
                    <p class="mt-2 text-sm">
                        Slug: {{ props.debug.tier.slug ?? 'none' }}
                    </p>
                    <p class="mt-1 text-sm">
                        Name: {{ props.debug.tier.name ?? 'none' }}
                    </p>
                    <p class="mt-1 text-sm">
                        Role synced:
                        <span
                            :class="
                                props.debug.tier.role_synced
                                    ? 'text-emerald-600'
                                    : 'text-rose-600'
                            "
                        >
                            {{ props.debug.tier.role_synced ? 'yes' : 'no' }}
                        </span>
                    </p>
                    <p class="mt-1 text-sm">
                        Data permissions:
                        <span
                            v-if="
                                props.debug.tier.data_permissions.length === 0
                            "
                            >none</span
                        >
                        <span v-else>{{
                            props.debug.tier.data_permissions.join(', ')
                        }}</span>
                    </p>
                </div>

                <div
                    class="rounded-xl border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                >
                    <h2 class="text-sm font-semibold">Roles</h2>
                    <p class="mt-2 text-sm">
                        <span v-if="props.debug.role_names.length === 0"
                            >none</span
                        >
                        <span v-else>{{
                            props.debug.role_names.join(', ')
                        }}</span>
                    </p>
                </div>
            </div>

            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border bg-white dark:bg-sidebar"
            >
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-sidebar-border bg-sidebar-accent"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Field</th>
                            <th class="px-4 py-3 font-medium">
                                Mapped Permission
                            </th>
                            <th class="px-4 py-3 font-medium">From Tier</th>
                            <th class="px-4 py-3 font-medium">From Spatie</th>
                            <th class="px-4 py-3 font-medium">Effective</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(access, field) in props.debug
                                .effective_access"
                            :key="field"
                            class="border-b border-sidebar-border"
                        >
                            <td class="px-4 py-3">{{ formatField(field) }}</td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ access.permission ?? '-' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    :class="
                                        access.from_tier_data_permissions
                                            ? 'text-emerald-600'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{
                                        access.from_tier_data_permissions
                                            ? 'yes'
                                            : 'no'
                                    }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    :class="
                                        access.from_spatie_permissions
                                            ? 'text-emerald-600'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{
                                        access.from_spatie_permissions
                                            ? 'yes'
                                            : 'no'
                                    }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    :class="
                                        access.effective
                                            ? 'font-medium text-emerald-600'
                                            : 'text-rose-600'
                                    "
                                >
                                    {{
                                        access.effective ? 'allowed' : 'blocked'
                                    }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
