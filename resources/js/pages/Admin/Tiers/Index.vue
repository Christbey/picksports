<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
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

interface NotificationTemplate {
    id: number;
    name: string;
    description: string | null;
    subject: string | null;
    email_body: string | null;
    sms_body: string | null;
    push_title: string | null;
    push_body: string | null;
    variables: string[] | null;
    active: boolean;
}

defineProps<{
    tiers: Tier[];
    notificationTemplates: NotificationTemplate[];
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

function editTemplate(template: NotificationTemplate) {
    router.get(`/admin/notification-templates/${template.id}/edit`);
}

function deleteTemplate(template: NotificationTemplate) {
    if (! confirm(`Are you sure you want to delete the "${template.name}" template?`)) {
        return;
    }

    router.delete(`/admin/notification-templates/${template.id}`);
}
</script>

<template>
    <Head title="Subscription Tiers" />

    <AppLayout :breadcrumbs="breadcrumbs">
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

            <div class="mt-8 flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold">Notification Templates</h2>
                    <p class="mt-1 text-muted-foreground">
                        Manage email, SMS, and push notification templates
                    </p>
                </div>
                <button
                    @click="router.get('/admin/notification-templates/create')"
                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
                >
                    Create New Template
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border bg-white dark:bg-sidebar">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-sidebar-border bg-sidebar-accent text-left text-sm">
                            <th class="p-4 font-semibold">Name</th>
                            <th class="p-4 font-semibold">Description</th>
                            <th class="p-4 font-semibold">Channels</th>
                            <th class="p-4 font-semibold">Status</th>
                            <th class="p-4 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-if="notificationTemplates.length === 0"
                            class="border-b border-sidebar-border"
                        >
                            <td colspan="5" class="p-4 text-center text-muted-foreground">
                                No notification templates found. Create one to get started.
                            </td>
                        </tr>
                        <tr
                            v-for="template in notificationTemplates"
                            :key="template.id"
                            class="border-b border-sidebar-border last:border-0 hover:bg-sidebar-accent/50 transition-colors"
                        >
                            <td class="p-4">
                                <div class="font-medium">{{ template.name }}</div>
                            </td>
                            <td class="p-4">
                                <div class="text-sm text-muted-foreground">
                                    {{ template.description || 'No description' }}
                                </div>
                            </td>
                            <td class="p-4">
                                <div class="flex gap-1">
                                    <span
                                        v-if="template.email_body"
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
                                    >
                                        Email
                                    </span>
                                    <span
                                        v-if="template.sms_body"
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                    >
                                        SMS
                                    </span>
                                    <span
                                        v-if="template.push_body"
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400"
                                    >
                                        Push
                                    </span>
                                </div>
                            </td>
                            <td class="p-4">
                                <span
                                    :class="[
                                        'inline-block rounded-full px-3 py-1 text-sm font-medium',
                                        template.active
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'
                                    ]"
                                >
                                    {{ template.active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="p-4">
                                <div class="flex gap-2">
                                    <button
                                        @click="editTemplate(template)"
                                        class="rounded-lg bg-sidebar-accent px-3 py-1 text-sm font-medium hover:bg-sidebar-accent/80 transition-colors"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        @click="deleteTemplate(template)"
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
    </AppLayout>
</template>
