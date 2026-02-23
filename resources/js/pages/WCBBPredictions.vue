<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { wcbbPredictions, responsibleGambling } from '@/routes';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'WCBB Predictions',
        href: wcbbPredictions().url,
    },
];
</script>

<template>
    <Head title="WCBB Predictions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
            <SubscriptionBanner variant="subtle" storage-key="wcbb-predictions-banner-dismissed" />

            <Alert>
                <AlertDescription>
                    <strong>Entertainment Only:</strong> These predictions are for entertainment purposes only. Past performance does not guarantee future results. Please gamble responsibly. If you or someone you know has a gambling problem, call 1-800-522-4700 or visit our <Link :href="responsibleGambling()" class="underline">Responsible Gambling</Link> page.
                </AlertDescription>
            </Alert>

            <SportPredictions :config="{
                sport: 'wcbb',
                title: 'WCBB Predictions',
                subtitle: 'Predictions based on Elo ratings and advanced metrics',
                useEasternTime: false,
                showGameTime: true,
                confidenceIsDecimal: false,
                confidenceDecimals: 0,
            }" />
        </div>
    </AppLayout>
</template>
