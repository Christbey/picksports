<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
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

const stopImpersonation = () => {
    router.post('/impersonation/stop');
};
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
    </AppShell>
</template>
