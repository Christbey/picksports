<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import AppContent from '@/components/AppContent.vue';
import FeedbackSubmissionModal from '@/components/FeedbackSubmissionModal.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import type { AppPageProps, BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage<AppPageProps>();
const impersonation = computed(() => page.props.impersonation);
const currentUser = computed(() => page.props.auth?.user ?? null);
let heartbeatTimer: number | null = null;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const sendHeartbeat = async () => {
    if (!currentUser.value || document.visibilityState !== 'visible') {
        return;
    }

    try {
        await fetch('/app/heartbeat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '{}',
            keepalive: true,
        });
    } catch {
        // Ignore transient heartbeat failures.
    }
};

const startHeartbeat = () => {
    if (!currentUser.value || heartbeatTimer !== null) {
        return;
    }

    heartbeatTimer = window.setInterval(() => {
        void sendHeartbeat();
    }, 60_000);
};

const stopHeartbeat = () => {
    if (heartbeatTimer === null) {
        return;
    }

    window.clearInterval(heartbeatTimer);
    heartbeatTimer = null;
};

const handleVisibilityChange = () => {
    if (document.visibilityState === 'visible') {
        void sendHeartbeat();
        startHeartbeat();

        return;
    }

    stopHeartbeat();
};

const stopImpersonation = () => {
    router.post('/impersonation/stop');
};

onMounted(() => {
    if (!currentUser.value) {
        return;
    }

    void sendHeartbeat();
    startHeartbeat();

    window.addEventListener('focus', sendHeartbeat);
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
    stopHeartbeat();
    window.removeEventListener('focus', sendHeartbeat);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div
                v-if="impersonation.active"
                class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                <p>
                    You are impersonating <span class="font-semibold">{{ page.props.auth.user.name }}</span>.
                </p>
                <button
                    type="button"
                    class="rounded-md bg-amber-900 px-3 py-1.5 text-xs font-semibold text-amber-50 transition-colors hover:bg-amber-800"
                    @click="stopImpersonation"
                >
                    Stop impersonating
                </button>
            </div>
            <slot />
        </AppContent>
        <FeedbackSubmissionModal />
    </AppShell>
</template>
