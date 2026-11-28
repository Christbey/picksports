<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

type Operation = {
    id: number;
    kind: string;
    status: string;
    message: string;
    url: string | null;
    result_expired: boolean;
    result_unavailable?: boolean;
};
const props = defineProps<{ operation: Operation }>();
const current = ref(props.operation);
const failed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let abort: AbortController | undefined;
let disposed = false;
let attempts = 0;
let generation = 0;
const pollingStopped = ref(false);
const safeContinuation = computed(() => {
    if (!current.value.url) return null;
    try {
        const url = new URL(current.value.url);
        return url.protocol === 'https:' &&
            [
                'checkout.stripe.com',
                'billing.stripe.com',
                'invoice.stripe.com',
            ].includes(url.hostname) &&
            !url.username &&
            !url.password &&
            !url.port &&
            !url.hash
            ? url.href
            : null;
    } catch {
        return null;
    }
});
async function refresh() {
    if (disposed) return;
    if (attempts++ >= 30) {
        pollingStopped.value = true;
        return;
    }
    const requestGeneration = generation;
    const intentId = props.operation.id;
    const controller = new AbortController();
    abort = controller;
    const deadline = setTimeout(() => controller.abort(), 10000);
    try {
        const response = await fetch(`/subscription/operations/${intentId}`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal: controller.signal,
        });
        if (!response.ok) throw new Error('Unavailable');
        const value = await response.json();
        if (disposed || requestGeneration !== generation) return;
        if (
            value.id !== intentId ||
            typeof value.status !== 'string' ||
            typeof value.message !== 'string'
        )
            throw new Error('Invalid response');
        current.value = value;
        failed.value = false;
    } catch {
        if (!disposed && requestGeneration === generation) failed.value = true;
    } finally {
        clearTimeout(deadline);
        if (
            !disposed &&
            requestGeneration === generation &&
            ![
                'completed',
                'expired',
                'attention',
                'uncertain',
                'session_open',
                'superseded',
            ].includes(current.value.status)
        ) {
            timer = setTimeout(refresh, 4000);
        }
    }
}
watch(
    () => props.operation,
    (operation) => {
        generation++;
        abort?.abort();
        if (timer) clearTimeout(timer);
        current.value = operation;
        attempts = 0;
        failed.value = false;
        pollingStopped.value = false;
        refresh();
    },
    { flush: 'sync' },
);
onMounted(refresh);
onBeforeUnmount(() => {
    disposed = true;
    generation++;
    if (timer) clearTimeout(timer);
    abort?.abort();
});
</script>

<template>
    <Head title="Billing request" />
    <AppLayout
        :breadcrumbs="[
            {
                title: 'Billing request',
                href: `/subscription/operations/${operation.id}`,
            },
        ]"
    >
        <main class="mx-auto w-full max-w-xl space-y-5 p-6">
            <h1 class="text-2xl font-semibold">Billing request</h1>
            <p role="status" aria-live="polite">{{ current.message }}</p>
            <p v-if="failed" role="alert">
                The status could not be refreshed. Reload this page to check; do
                not submit a second purchase.
            </p>
            <p v-if="current.result_unavailable" role="alert">
                This continuation is unavailable because the current billing
                ownership or provider context needs review. Contact support; do
                not start another purchase.
            </p>
            <p v-if="current.result_expired">
                This local continuation link has expired. Contact support to
                recover the existing operation rather than starting another
                purchase.
            </p>
            <p v-if="pollingStopped" role="status">
                Automatic checks have paused. Reload this page to check the
                existing request without starting another purchase.
            </p>
            <a
                v-if="safeContinuation"
                :href="safeContinuation"
                class="inline-block rounded bg-primary px-4 py-2 text-primary-foreground"
                rel="noreferrer"
                >Continue securely with Stripe</a
            >
            <p class="text-sm text-muted-foreground">
                Billing and effective account access are separate. Administrator
                access overrides may remain in effect.
            </p>
            <Link href="/settings/subscription" class="block underline"
                >Back to subscription settings</Link
            >
        </main>
    </AppLayout>
</template>
