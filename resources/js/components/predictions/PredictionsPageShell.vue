<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AppLayout from '@/layouts/AppLayout.vue';
import { responsibleGambling } from '@/routes';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    title: string;
    breadcrumbTitle: string;
    breadcrumbHref: string;
    bannerStorageKey: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: props.breadcrumbTitle,
        href: props.breadcrumbHref,
    },
];
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
            <SubscriptionBanner variant="subtle" :storage-key="bannerStorageKey" />

            <Alert>
                <AlertDescription>
                    <strong>Entertainment Only:</strong> These predictions are for entertainment purposes only. Past performance does not guarantee future results. Please gamble responsibly. If you or someone you know has a gambling problem, call 1-800-522-4700 or visit our <Link :href="responsibleGambling()" class="underline">Responsible Gambling</Link> page.
                </AlertDescription>
            </Alert>

            <slot />
        </div>
    </AppLayout>
</template>
