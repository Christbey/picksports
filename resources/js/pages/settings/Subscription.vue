<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Subscription {
    tier: string;
    status: string;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
}

const props = defineProps<{
    subscription: Subscription;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Subscription settings',
        href: '/settings/subscription',
    },
];

const cancelForm = useForm({});
const resumeForm = useForm({});
const isProcessingPortal = ref(false);

function cancelSubscription() {
    if (confirm('Are you sure you want to cancel your subscription? You will retain access until the end of your billing period.')) {
        cancelForm.post('/subscription/cancel', {
            onSuccess: () => {
                router.reload();
            },
        });
    }
}

function resumeSubscription() {
    resumeForm.post('/subscription/resume', {
        onSuccess: () => {
            router.reload();
        },
    });
}

function openBillingPortal() {
    if (isProcessingPortal.value) return;

    isProcessingPortal.value = true;

    router.post('/subscription/billing-portal', {}, {
        onFinish: () => {
            isProcessingPortal.value = false;
        },
    });
}

function formatDate(dateString: string | null): string {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'active':
            return 'text-green-600 dark:text-green-400';
        case 'canceled':
            return 'text-yellow-600 dark:text-yellow-400';
        case 'past_due':
            return 'text-red-600 dark:text-red-400';
        default:
            return 'text-muted-foreground';
    }
}

function getStatusLabel(status: string): string {
    return status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ');
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Subscription settings" />

        <h1 class="sr-only">Subscription Settings</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Subscription"
                    description="Manage your subscription and billing"
                />

                <div class="space-y-6">
                    <!-- Subscription Details Card -->
                    <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <h3 class="mb-4 text-sm font-semibold">Current Plan</h3>

                        <div class="space-y-4">
                            <div>
                                <div class="text-sm text-muted-foreground">Plan</div>
                                <div class="mt-1 text-xl font-bold capitalize">
                                    {{ subscription.tier }}
                                </div>
                            </div>

                            <div>
                                <div class="text-sm text-muted-foreground">Status</div>
                                <div
                                    :class="['mt-1 text-lg font-semibold capitalize', getStatusColor(subscription.status)]"
                                >
                                    {{ getStatusLabel(subscription.status) }}
                                </div>
                            </div>

                            <div v-if="subscription.current_period_end">
                                <div class="text-sm text-muted-foreground">
                                    {{ subscription.cancel_at_period_end ? 'Access Until' : 'Next Billing Date' }}
                                </div>
                                <div class="mt-1 font-medium">
                                    {{ formatDate(subscription.current_period_end) }}
                                </div>
                            </div>

                            <div
                                v-if="subscription.cancel_at_period_end"
                                class="rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20"
                            >
                                <p class="text-sm text-yellow-800 dark:text-yellow-200">
                                    Your subscription has been cancelled and will end on
                                    {{ formatDate(subscription.current_period_end) }}.
                                    You can resume your subscription anytime before this date.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions Card -->
                    <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <h3 class="mb-4 text-sm font-semibold">Manage Subscription</h3>

                        <div class="space-y-3">
                            <Button
                                @click="openBillingPortal"
                                :disabled="isProcessingPortal"
                                class="w-full"
                                variant="default"
                            >
                                {{ isProcessingPortal ? 'Opening Portal...' : 'Update Payment Method' }}
                            </Button>

                            <Button
                                as-child
                                class="w-full"
                                variant="outline"
                            >
                                <a href="/subscription/plans">
                                    Change Plan
                                </a>
                            </Button>

                            <Button
                                v-if="subscription.cancel_at_period_end"
                                @click="resumeSubscription"
                                :disabled="resumeForm.processing"
                                class="w-full"
                                variant="default"
                            >
                                Resume Subscription
                            </Button>

                            <Button
                                v-else
                                @click="cancelSubscription"
                                :disabled="cancelForm.processing"
                                class="w-full"
                                variant="destructive"
                            >
                                Cancel Subscription
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
