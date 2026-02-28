<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();

const fallbackBreadcrumbs = computed<BreadcrumbItem[]>(() => {
    const rawPath = (page.url ?? '/').split('?')[0];
    const segments = rawPath.split('/').filter(Boolean);

    if (segments.length === 0) {
        return [{ title: 'Home', href: '/' }];
    }

    const items: BreadcrumbItem[] = [{ title: 'Home', href: '/' }];
    let runningPath = '';

    segments.forEach((segment, index) => {
        runningPath += `/${segment}`;
        items.push({
            title: segment.replace(/-/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase()),
            href: index === segments.length - 1 ? undefined : runningPath,
        });
    });

    return items;
});

const resolvedBreadcrumbs = computed(() =>
    props.breadcrumbs.length > 0 ? props.breadcrumbs : fallbackBreadcrumbs.value,
);
</script>

<template>
    <AppLayout :breadcrumbs="resolvedBreadcrumbs">
        <slot />
    </AppLayout>
</template>
