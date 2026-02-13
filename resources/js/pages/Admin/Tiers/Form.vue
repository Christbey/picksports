<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { ref, computed } from 'vue';

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
    team_metrics_limit: number | null;
    is_default: boolean;
    is_active: boolean;
    sort_order: number;
}

const props = defineProps<{
    tier: Tier | null;
}>();

const isEditing = computed(() => props.tier !== null);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin/subscriptions',
    },
    {
        title: 'Subscription Tiers',
        href: '/admin/tiers',
    },
    {
        title: isEditing.value ? 'Edit Tier' : 'Create Tier',
        href: isEditing.value ? `/admin/tiers/${props.tier?.id}/edit` : '/admin/tiers/create',
    },
];

const availableSports = ['NBA', 'NFL', 'CBB', 'WCBB', 'MLB', 'CFB', 'WNBA'];

const form = useForm({
    name: props.tier?.name || '',
    slug: props.tier?.slug || '',
    description: props.tier?.description || '',
    price_monthly: props.tier?.price_monthly || '',
    price_yearly: props.tier?.price_yearly || '',
    stripe_price_id_monthly: props.tier?.stripe_price_id_monthly || '',
    stripe_price_id_yearly: props.tier?.stripe_price_id_yearly || '',
    features: {
        predictions_per_day: props.tier?.features?.predictions_per_day ?? null,
        historical_data_days: props.tier?.features?.historical_data_days ?? null,
        sports_access: props.tier?.features?.sports_access || [],
        export_predictions: props.tier?.features?.export_predictions ?? false,
        api_access: props.tier?.features?.api_access ?? false,
        advanced_analytics: props.tier?.features?.advanced_analytics ?? false,
        email_alerts: props.tier?.features?.email_alerts ?? false,
        priority_support: props.tier?.features?.priority_support ?? false,
    },
    permissions: props.tier?.permissions || [],
    team_metrics_limit: props.tier?.team_metrics_limit ?? null,
    is_default: props.tier?.is_default || false,
    is_active: props.tier?.is_active ?? true,
    sort_order: props.tier?.sort_order || 0,
});

const newPermission = ref('');

const unlimitedPredictions = ref(form.features.predictions_per_day === null);
const unlimitedHistory = ref(form.features.historical_data_days === null);
const unlimitedTeamMetrics = ref(form.team_metrics_limit === null);

function toggleUnlimitedPredictions() {
    if (unlimitedPredictions.value) {
        form.features.predictions_per_day = null;
    } else if (form.features.predictions_per_day === null) {
        form.features.predictions_per_day = 5;
    }
}

function toggleUnlimitedHistory() {
    if (unlimitedHistory.value) {
        form.features.historical_data_days = null;
    } else if (form.features.historical_data_days === null) {
        form.features.historical_data_days = 7;
    }
}

function toggleUnlimitedTeamMetrics() {
    if (unlimitedTeamMetrics.value) {
        form.team_metrics_limit = null;
    } else if (form.team_metrics_limit === null) {
        form.team_metrics_limit = 10;
    }
}

function toggleSport(sport: string) {
    const index = form.features.sports_access.indexOf(sport);
    if (index > -1) {
        form.features.sports_access.splice(index, 1);
    } else {
        form.features.sports_access.push(sport);
    }
}

function addPermission() {
    if (newPermission.value.trim()) {
        form.permissions.push(newPermission.value.trim());
        newPermission.value = '';
    }
}

function removePermission(index: number) {
    form.permissions.splice(index, 1);
}

function submit() {
    if (isEditing.value) {
        form.put(`/admin/tiers/${props.tier!.id}`);
    } else {
        form.post('/admin/tiers');
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Edit Tier' : 'Create Tier'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div>
                <h1 class="text-2xl font-bold">{{ isEditing ? 'Edit' : 'Create' }} Subscription Tier</h1>
                <p class="mt-1 text-muted-foreground">
                    {{ isEditing ? 'Update tier information and pricing' : 'Create a new subscription tier' }}
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium mb-2">
                                Tier Name <span class="text-red-600">*</span>
                            </label>
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                required
                                class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                :class="{ 'border-red-500': form.errors.name }"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label for="slug" class="block text-sm font-medium mb-2">
                                Slug <span class="text-red-600">*</span>
                            </label>
                            <input
                                id="slug"
                                v-model="form.slug"
                                type="text"
                                required
                                class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                :class="{ 'border-red-500': form.errors.slug }"
                            />
                            <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
                        </div>
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium mb-2">
                            Description
                        </label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                            :class="{ 'border-red-500': form.errors.description }"
                        ></textarea>
                        <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="price_monthly" class="block text-sm font-medium mb-2">
                                Monthly Price ($)
                            </label>
                            <input
                                id="price_monthly"
                                v-model="form.price_monthly"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                :class="{ 'border-red-500': form.errors.price_monthly }"
                            />
                            <p v-if="form.errors.price_monthly" class="mt-1 text-sm text-red-600">{{ form.errors.price_monthly }}</p>
                        </div>

                        <div>
                            <label for="price_yearly" class="block text-sm font-medium mb-2">
                                Yearly Price ($)
                            </label>
                            <input
                                id="price_yearly"
                                v-model="form.price_yearly"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                :class="{ 'border-red-500': form.errors.price_yearly }"
                            />
                            <p v-if="form.errors.price_yearly" class="mt-1 text-sm text-red-600">{{ form.errors.price_yearly }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="stripe_price_id_monthly" class="block text-sm font-medium mb-2">
                                Stripe Price ID (Monthly)
                            </label>
                            <input
                                id="stripe_price_id_monthly"
                                v-model="form.stripe_price_id_monthly"
                                type="text"
                                class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                :class="{ 'border-red-500': form.errors.stripe_price_id_monthly }"
                            />
                            <p v-if="form.errors.stripe_price_id_monthly" class="mt-1 text-sm text-red-600">{{ form.errors.stripe_price_id_monthly }}</p>
                        </div>

                        <div>
                            <label for="stripe_price_id_yearly" class="block text-sm font-medium mb-2">
                                Stripe Price ID (Yearly)
                            </label>
                            <input
                                id="stripe_price_id_yearly"
                                v-model="form.stripe_price_id_yearly"
                                type="text"
                                class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                :class="{ 'border-red-500': form.errors.stripe_price_id_yearly }"
                            />
                            <p v-if="form.errors.stripe_price_id_yearly" class="mt-1 text-sm text-red-600">{{ form.errors.stripe_price_id_yearly }}</p>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <h3 class="text-lg font-semibold">Features</h3>

                        <!-- Predictions Per Day -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Predictions Per Day</label>
                            <div class="flex gap-4 items-center">
                                <input
                                    v-model.number="form.features.predictions_per_day"
                                    type="number"
                                    min="0"
                                    :disabled="unlimitedPredictions"
                                    class="w-32 rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50"
                                />
                                <label class="flex items-center cursor-pointer">
                                    <input
                                        v-model="unlimitedPredictions"
                                        type="checkbox"
                                        @change="toggleUnlimitedPredictions"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm">Unlimited</span>
                                </label>
                            </div>
                        </div>

                        <!-- Historical Data Days -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Historical Data Days</label>
                            <div class="flex gap-4 items-center">
                                <input
                                    v-model.number="form.features.historical_data_days"
                                    type="number"
                                    min="0"
                                    :disabled="unlimitedHistory"
                                    class="w-32 rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50"
                                />
                                <label class="flex items-center cursor-pointer">
                                    <input
                                        v-model="unlimitedHistory"
                                        type="checkbox"
                                        @change="toggleUnlimitedHistory"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm">Unlimited</span>
                                </label>
                            </div>
                        </div>

                        <!-- Team Metrics Limit -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Team Metrics Limit</label>
                            <div class="flex gap-4 items-center">
                                <input
                                    v-model.number="form.team_metrics_limit"
                                    type="number"
                                    min="1"
                                    :disabled="unlimitedTeamMetrics"
                                    class="w-32 rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50"
                                />
                                <label class="flex items-center cursor-pointer">
                                    <input
                                        v-model="unlimitedTeamMetrics"
                                        type="checkbox"
                                        @change="toggleUnlimitedTeamMetrics"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm">Unlimited</span>
                                </label>
                            </div>
                        </div>

                        <!-- Sports Access -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Sports Access</label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <label
                                    v-for="sport in availableSports"
                                    :key="sport"
                                    class="flex items-center cursor-pointer rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 hover:bg-sidebar-accent transition-colors"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="form.features.sports_access.includes(sport)"
                                        @change="toggleSport(sport)"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm font-medium">{{ sport }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Boolean Features -->
                        <div>
                            <label class="block text-sm font-medium mb-3">Additional Features</label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <label class="flex items-center cursor-pointer rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-3 hover:bg-sidebar-accent transition-colors">
                                    <input
                                        v-model="form.features.export_predictions"
                                        type="checkbox"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm font-medium">Export Predictions</span>
                                </label>

                                <label class="flex items-center cursor-pointer rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-3 hover:bg-sidebar-accent transition-colors">
                                    <input
                                        v-model="form.features.api_access"
                                        type="checkbox"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm font-medium">API Access</span>
                                </label>

                                <label class="flex items-center cursor-pointer rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-3 hover:bg-sidebar-accent transition-colors">
                                    <input
                                        v-model="form.features.advanced_analytics"
                                        type="checkbox"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm font-medium">Advanced Analytics</span>
                                </label>

                                <label class="flex items-center cursor-pointer rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-3 hover:bg-sidebar-accent transition-colors">
                                    <input
                                        v-model="form.features.email_alerts"
                                        type="checkbox"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm font-medium">Email Alerts</span>
                                </label>

                                <label class="flex items-center cursor-pointer rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-3 hover:bg-sidebar-accent transition-colors">
                                    <input
                                        v-model="form.features.priority_support"
                                        type="checkbox"
                                        class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                    />
                                    <span class="ml-2 text-sm font-medium">Priority Support</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2">Permissions</label>
                        <div class="space-y-2">
                            <div v-for="(permission, index) in form.permissions" :key="index" class="flex gap-2">
                                <input
                                    v-model="form.permissions[index]"
                                    type="text"
                                    class="flex-1 rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                />
                                <button
                                    type="button"
                                    @click="removePermission(index)"
                                    class="rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700 transition-colors"
                                >
                                    Remove
                                </button>
                            </div>
                            <div class="flex gap-2">
                                <input
                                    v-model="newPermission"
                                    type="text"
                                    placeholder="Add a new permission..."
                                    @keyup.enter="addPermission"
                                    class="flex-1 rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                />
                                <button
                                    type="button"
                                    @click="addPermission"
                                    class="rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
                                >
                                    Add
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="sort_order" class="block text-sm font-medium mb-2">
                                Sort Order
                            </label>
                            <input
                                id="sort_order"
                                v-model.number="form.sort_order"
                                type="number"
                                min="0"
                                class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar px-4 py-2 focus:outline-none focus:ring-2 focus:ring-primary"
                                :class="{ 'border-red-500': form.errors.sort_order }"
                            />
                            <p v-if="form.errors.sort_order" class="mt-1 text-sm text-red-600">{{ form.errors.sort_order }}</p>
                        </div>

                        <div class="flex items-center">
                            <label class="flex items-center cursor-pointer">
                                <input
                                    v-model="form.is_active"
                                    type="checkbox"
                                    class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                />
                                <span class="ml-2 text-sm font-medium">Active</span>
                            </label>
                        </div>

                        <div class="flex items-center">
                            <label class="flex items-center cursor-pointer">
                                <input
                                    v-model="form.is_default"
                                    type="checkbox"
                                    class="w-4 h-4 rounded border-sidebar-border text-primary focus:ring-2 focus:ring-primary"
                                />
                                <span class="ml-2 text-sm font-medium">Default Tier</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ form.processing ? 'Saving...' : (isEditing ? 'Update Tier' : 'Create Tier') }}
                    </button>
                    <button
                        type="button"
                        @click="router.get('/admin/tiers')"
                        class="rounded-lg bg-sidebar-accent px-4 py-2 font-medium hover:bg-sidebar-accent/80 transition-colors"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
