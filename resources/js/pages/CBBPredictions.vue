<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import CBBPredictionsComponent from '@/components/CBBPredictions.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { cbbPredictions, responsibleGambling } from '@/routes';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'CBB Predictions',
        href: cbbPredictions().url,
    },
];
</script>

<template>
    <Head title="CBB Predictions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
            <SubscriptionBanner variant="subtle" storage-key="cbb-predictions-banner-dismissed" />

            <Alert>
                <AlertDescription>
                    <strong>Entertainment Only:</strong> These predictions are for entertainment purposes only. Past performance does not guarantee future results. Please gamble responsibly. If you or someone you know has a gambling problem, call 1-800-522-4700 or visit our <Link :href="responsibleGambling()" class="underline">Responsible Gambling</Link> page.
                </AlertDescription>
            </Alert>

            <CBBPredictionsComponent />
        </div>
    </AppLayout>
</template>
