<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Subscription {
    tier: string;
    status: string;
    current_period_end: string | null;
    scheduled_access_end?: string | null;
    cancel_at_period_end: boolean;
    billing_tier?: string | null;
    can_cancel?: boolean;
    can_resume?: boolean;
}

const props = defineProps<{
    subscription: Subscription;
}>();

const hasBillableSubscription = computed(() =>
    [
        'active',
        'trialing',
        'past_due',
        'unpaid',
        'incomplete',
        'incomplete_expired',
        'canceled',
        'paused',
    ].includes(props.subscription.status),
);

const canCancel = computed(
    () =>
        hasBillableSubscription.value &&
        (props.subscription.can_cancel ??
            !props.subscription.cancel_at_period_end),
);
const canResume = computed(
    () =>
        hasBillableSubscription.value &&
        (props.subscription.can_resume ??
            props.subscription.cancel_at_period_end),
);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Manage Subscription',
        href: '/subscription/manage',
    },
];

const cancelForm = useForm({});
const resumeForm = useForm({});
const isProcessingPortal = ref(false);
const portalError = ref('');
const billingError = computed(
    () =>
        portalError.value ||
        (cancelForm.errors as Record<string, string>).billing ||
        (resumeForm.errors as Record<string, string>).billing,
);

function cancelSubscription() {
    if (!canCancel.value) return;
    if (
        confirm(
            'Are you sure you want to cancel your subscription? You will retain access until the end of your billing period.',
        )
    ) {
        cancelForm.post('/subscription/cancel');
    }
}

function resumeSubscription() {
    if (!canResume.value) return;
    resumeForm.post('/subscription/resume');
}

function openBillingPortal() {
    if (!hasBillableSubscription.value || isProcessingPortal.value) return;

    isProcessingPortal.value = true;
    portalError.value = '';

    router.post(
        '/subscription/billing-portal',
        {},
        {
            onError: (errors) => {
                portalError.value =
                    errors.billing ||
                    'Payment management is unavailable. Local cancellation remains available; contact support.';
            },
            onFinish: () => {
                isProcessingPortal.value = false;
            },
        },
    );
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
    <Head title="Manage Subscription" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
        >
            <div>
                <h1 class="text-2xl font-bold">Manage Subscription</h1>
                <p class="mt-1 text-muted-foreground">
                    View and manage your subscription details
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div
                    class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
                >
                    <h2 class="mb-4 text-lg font-semibold">
                        Subscription Details
                    </h2>

                    <div class="space-y-4">
                        <div>
                            <div class="text-sm text-muted-foreground">
                                Effective account access
                            </div>
                            <div class="mt-1 text-xl font-bold capitalize">
                                {{ subscription.tier }}
                            </div>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Stored billing plan:
                                {{
                                    subscription.billing_tier ?? 'Unavailable'
                                }}. Billing and effective account access may
                                differ because of administrator overrides.
                            </p>
                            <p
                                v-if="subscription.status === 'billing_review'"
                                role="alert"
                                class="mt-2 text-sm text-destructive"
                            >
                                Billing records require support review. Resolve
                                the existing account billing before starting
                                another purchase.
                            </p>
                        </div>

                        <div>
                            <div class="text-sm text-muted-foreground">
                                Status
                            </div>
                            <div
                                :class="[
                                    'mt-1 text-lg font-semibold capitalize',
                                    getStatusColor(subscription.status),
                                ]"
                            >
                                {{ getStatusLabel(subscription.status) }}
                            </div>
                        </div>

                        <div v-if="subscription.current_period_end">
                            <div class="text-sm text-muted-foreground">
                                Observed Billing Period End
                            </div>
                            <div class="mt-1 font-medium">
                                {{
                                    formatDate(subscription.current_period_end)
                                }}
                            </div>
                        </div>

                        <div
                            v-if="subscription.cancel_at_period_end"
                            class="rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20"
                        >
                            <p
                                class="text-sm text-yellow-800 dark:text-yellow-200"
                            >
                                Your subscription has been cancelled and will
                                end on
                                {{
                                    formatDate(
                                        subscription.scheduled_access_end ??
                                            null,
                                    )
                                }}. You can resume your subscription anytime
                                before this date.
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
                >
                    <h2 class="mb-4 text-lg font-semibold">Actions</h2>

                    <p
                        v-if="billingError"
                        role="alert"
                        class="mb-3 text-sm text-destructive"
                    >
                        {{ billingError }}
                    </p>
                    <div class="space-y-3">
                        <button
                            v-if="hasBillableSubscription"
                            @click="openBillingPortal"
                            :disabled="isProcessingPortal"
                            class="w-full rounded-lg bg-primary px-4 py-3 font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{
                                isProcessingPortal
                                    ? 'Opening Portal...'
                                    : 'Update Payment Method'
                            }}
                        </button>

                        <a
                            href="/subscription/plans"
                            class="block w-full rounded-lg bg-sidebar-accent px-4 py-3 text-center font-medium text-foreground transition-colors hover:bg-sidebar-accent/80"
                        >
                            Change Plan
                        </a>

                        <button
                            v-if="canResume"
                            @click="resumeSubscription"
                            :disabled="resumeForm.processing"
                            class="w-full rounded-lg bg-green-600 px-4 py-3 font-medium text-white transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Resume Subscription
                        </button>

                        <button
                            v-else-if="canCancel"
                            @click="cancelSubscription"
                            :disabled="cancelForm.processing"
                            class="w-full rounded-lg border border-red-600 px-4 py-3 font-medium text-red-600 transition-colors hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-red-900/20"
                        >
                            Cancel Subscription
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
            >
                <h2 class="mb-4 text-lg font-semibold">Need Help?</h2>
                <p class="text-sm text-muted-foreground">
                    If you have questions about your subscription or billing,
                    please contact our support team.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
