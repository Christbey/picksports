<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { ref } from 'vue';

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

interface TierPrice {
    monthly: number | string | null;
    yearly: number | string | null;
}

interface Tier {
    id: string;
    name: string;
    description: string;
    price: TierPrice;
    features: TierFeatures;
    is_current: boolean;
}

const props = defineProps<{
    tiers: Tier[];
    currentTier: string;
}>();

const selectedBillingPeriod = ref<'monthly' | 'yearly'>('monthly');
const isProcessing = ref(false);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Subscription Plans',
        href: '/subscription/plans',
    },
];

function subscribe(tierId: string) {
    if (isProcessing.value) return;

    isProcessing.value = true;

    router.post('/subscription/checkout', {
        tier: tierId,
        billing_period: selectedBillingPeriod.value,
    }, {
        onFinish: () => {
            isProcessing.value = false;
        },
    });
}

function formatPrice(price: number | string | null): string {
    if (price === null) return 'Free';
    const numPrice = typeof price === 'string' ? parseFloat(price) : price;
    if (isNaN(numPrice)) return 'Free';
    return `$${numPrice.toFixed(2)}`;
}

function formatFeatureValue(value: any): string {
    if (value === null) return 'Unlimited';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) return value.join(', ');
    return value.toString();
}
</script>

<template>
    <Head title="Subscription Plans" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div class="text-center">
                <h1 class="text-3xl font-bold">Choose Your Plan</h1>
                <p class="mt-2 text-muted-foreground">
                    Select the perfect plan for your sports prediction needs
                </p>
            </div>

            <div class="flex justify-center gap-2">
                <button
                    @click="selectedBillingPeriod = 'monthly'"
                    :class="[
                        'rounded-lg px-4 py-2 font-medium transition-colors',
                        selectedBillingPeriod === 'monthly'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-sidebar-accent text-foreground hover:bg-sidebar-accent/80',
                    ]"
                >
                    Monthly
                </button>
                <button
                    @click="selectedBillingPeriod = 'yearly'"
                    :class="[
                        'rounded-lg px-4 py-2 font-medium transition-colors',
                        selectedBillingPeriod === 'yearly'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-sidebar-accent text-foreground hover:bg-sidebar-accent/80',
                    ]"
                >
                    Yearly (Save up to 17%)
                </button>
            </div>

            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="tier in tiers"
                    :key="tier.id"
                    :class="[
                        'relative flex flex-col rounded-xl border p-6',
                        tier.is_current
                            ? 'border-primary bg-primary/5'
                            : 'border-sidebar-border bg-white dark:bg-sidebar',
                    ]"
                >
                    <div
                        v-if="tier.is_current"
                        class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-primary px-3 py-1 text-xs font-medium text-primary-foreground"
                    >
                        Current Plan
                    </div>

                    <div class="mb-4">
                        <h3 class="text-xl font-bold">{{ tier.name }}</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ tier.description }}
                        </p>
                    </div>

                    <div class="mb-6">
                        <div class="text-3xl font-bold">
                            {{ formatPrice(tier.price[selectedBillingPeriod]) }}
                        </div>
                        <div class="text-sm text-muted-foreground">
                            {{ tier.price[selectedBillingPeriod] !== null ? `per ${selectedBillingPeriod === 'monthly' ? 'month' : 'year'}` : '' }}
                        </div>
                    </div>

                    <ul class="mb-6 flex-1 space-y-2 text-sm">
                        <li class="flex items-start gap-2">
                            <span class="text-primary">✓</span>
                            <span>
                                {{ formatFeatureValue(tier.features.predictions_per_day) }}
                                {{ tier.features.predictions_per_day === null ? 'predictions' : 'predictions/day' }}
                            </span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-primary">✓</span>
                            <span>
                                {{ formatFeatureValue(tier.features.historical_data_days) }}
                                {{ tier.features.historical_data_days === null ? 'historical data' : 'days of data' }}
                            </span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-primary">✓</span>
                            <span>{{ tier.features.sports_access.join(', ') }} access</span>
                        </li>
                        <li
                            v-if="tier.features.export_predictions"
                            class="flex items-start gap-2"
                        >
                            <span class="text-primary">✓</span>
                            <span>Export predictions</span>
                        </li>
                        <li
                            v-if="tier.features.api_access"
                            class="flex items-start gap-2"
                        >
                            <span class="text-primary">✓</span>
                            <span>API access</span>
                        </li>
                        <li
                            v-if="tier.features.advanced_analytics"
                            class="flex items-start gap-2"
                        >
                            <span class="text-primary">✓</span>
                            <span>Advanced analytics</span>
                        </li>
                        <li
                            v-if="tier.features.email_alerts"
                            class="flex items-start gap-2"
                        >
                            <span class="text-primary">✓</span>
                            <span>Email alerts</span>
                        </li>
                        <li
                            v-if="tier.features.priority_support"
                            class="flex items-start gap-2"
                        >
                            <span class="text-primary">✓</span>
                            <span>Priority support</span>
                        </li>
                    </ul>

                    <button
                        v-if="tier.id !== 'free' && !tier.is_current"
                        @click="subscribe(tier.id)"
                        :disabled="isProcessing"
                        class="w-full rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ isProcessing ? 'Processing...' : 'Subscribe' }}
                    </button>
                    <button
                        v-else-if="tier.is_current"
                        disabled
                        class="w-full rounded-lg bg-sidebar-accent px-4 py-2 font-medium text-muted-foreground cursor-not-allowed"
                    >
                        Current Plan
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
