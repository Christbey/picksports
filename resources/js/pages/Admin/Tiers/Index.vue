<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface TierFeatures {
    predictions_per_day: number | null;
    historical_data_days: number | null;
    sports_access: string[];
    export_predictions: boolean;
    api_access: boolean;
    advanced_analytics: boolean;
    email_alerts: boolean;
    priority_support: boolean;
}

interface Tier {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    price_monthly: string | null;
    price_yearly: string | null;
    stripe_price_id_monthly: string | null;
    stripe_price_id_yearly: string | null;
    features: TierFeatures | null;
    permissions: string[] | null;
    is_default: boolean;
    is_active: boolean;
    sort_order: number;
}

defineProps<{
    tiers: Tier[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin/subscriptions',
    },
    {
        title: 'Subscription Tiers',
        href: '/admin/tiers',
    },
];

function editTier(tier: Tier) {
    router.get(`/admin/tiers/${tier.id}/edit`);
}

function deleteTier(tier: Tier) {
    if (! confirm(`Are you sure you want to delete the "${tier.name}" tier?`)) {
        return;
    }

    router.delete(`/admin/tiers/${tier.id}`);
}

function formatPrice(price: string | null): string {
    if (!price) return 'N/A';
    return `$${parseFloat(price).toFixed(2)}`;
}
</script>

<template>
    <Head title="Subscription Tiers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :full-width="true">
            <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Subscription Tiers</h1>
                    <p class="mt-1 text-muted-foreground">
                        Manage subscription plans and pricing
                    </p>
                </div>
                <button
                    @click="router.get('/admin/tiers/create')"
                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
                >
                    Create New Tier
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border bg-white dark:bg-sidebar">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-sidebar-border bg-sidebar-accent text-left text-sm">
                            <th class="p-4 font-semibold">Name</th>
                            <th class="p-4 font-semibold">Slug</th>
                            <th class="p-4 font-semibold">Monthly Price</th>
                            <th class="p-4 font-semibold">Yearly Price</th>
                            <th class="p-4 font-semibold">Status</th>
                            <th class="p-4 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-if="tiers.length === 0"
                            class="border-b border-sidebar-border"
                        >
                            <td colspan="6" class="p-4 text-center text-muted-foreground">
                                No subscription tiers found. Create one to get started.
                            </td>
                        </tr>
                        <tr
                            v-for="tier in tiers"
                            :key="tier.id"
                            class="border-b border-sidebar-border last:border-0 hover:bg-sidebar-accent/50 transition-colors"
                        >
                            <td class="p-4">
                                <div class="font-medium">{{ tier.name }}</div>
                                <div v-if="tier.is_default" class="text-xs text-muted-foreground">
                                    (Default)
                                </div>
                            </td>
                            <td class="p-4">
                                <span class="font-mono text-sm">{{ tier.slug }}</span>
                            </td>
                            <td class="p-4">
                                {{ formatPrice(tier.price_monthly) }}
                            </td>
                            <td class="p-4">
                                {{ formatPrice(tier.price_yearly) }}
                            </td>
                            <td class="p-4">
                                <span
                                    :class="[
                                        'inline-block rounded-full px-3 py-1 text-sm font-medium',
                                        tier.is_active
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'
                                    ]"
                                >
                                    {{ tier.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="p-4">
                                <div class="flex gap-2">
                                    <button
                                        @click="editTier(tier)"
                                        class="rounded-lg bg-sidebar-accent px-3 py-1 text-sm font-medium hover:bg-sidebar-accent/80 transition-colors"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        @click="deleteTier(tier)"
                                        class="rounded-lg bg-red-600 px-3 py-1 text-sm font-medium text-white hover:bg-red-700 transition-colors"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
