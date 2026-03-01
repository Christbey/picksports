<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    title: string;
    breadcrumbs: BreadcrumbItem[];
    loading: boolean;
    error?: string | null;
    gameStatusCode?: string | null;
    startDate?: string | null;
    homeTeamName?: string | null;
    awayTeamName?: string | null;
    venueName?: string | null;
}>();

const page = usePage();
const pageDescription = computed(
    () => `${props.title} game analysis, trends, and prediction insights from PickSports.`,
);
const canonicalUrl = computed(() => {
    const path = (page.url ?? '/').split('?')[0] || '/';
    if (typeof window !== 'undefined') {
        return `${window.location.origin}${path}`;
    }
    return `https://picksports.app${path}`;
});
const imageUrl = 'https://picksports.app/icon-512.png?v=ps-gradient-2';
const eventStatusUrl = computed(() => {
    const code = (props.gameStatusCode || '').toUpperCase();
    const map: Record<string, string> = {
        STATUS_FINAL: 'https://schema.org/EventCompleted',
        STATUS_IN_PROGRESS: 'https://schema.org/EventInProgress',
        STATUS_HALFTIME: 'https://schema.org/EventInProgress',
        STATUS_POSTPONED: 'https://schema.org/EventPostponed',
        STATUS_DELAYED: 'https://schema.org/EventPostponed',
        STATUS_CANCELED: 'https://schema.org/EventCancelled',
        STATUS_CANCELLED: 'https://schema.org/EventCancelled',
        STATUS_SCHEDULED: 'https://schema.org/EventScheduled',
        STATUS_PRE_GAME: 'https://schema.org/EventScheduled',
    };

    return map[code] || 'https://schema.org/EventScheduled';
});
const webPageSchema = computed(() =>
    JSON.stringify(
        {
            '@context': 'https://schema.org',
            '@type': 'WebPage',
            name: props.title,
            description: pageDescription.value,
            url: canonicalUrl.value,
        },
        null,
        0,
    ),
);
const sportsEventSchema = computed(() =>
    JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'SportsEvent',
        name: props.title,
        url: canonicalUrl.value,
        sport: props.breadcrumbs?.[0]?.title || 'Sports',
        eventStatus: eventStatusUrl.value,
        ...(props.startDate ? { startDate: props.startDate } : {}),
        ...(props.homeTeamName
            ? { homeTeam: { '@type': 'SportsTeam', name: props.homeTeamName } }
            : {}),
        ...(props.awayTeamName
            ? { awayTeam: { '@type': 'SportsTeam', name: props.awayTeamName } }
            : {}),
        ...(props.homeTeamName || props.awayTeamName
            ? {
                competitor: [
                    ...(props.homeTeamName
                        ? [{ '@type': 'SportsTeam', name: props.homeTeamName }]
                        : []),
                    ...(props.awayTeamName
                        ? [{ '@type': 'SportsTeam', name: props.awayTeamName }]
                        : []),
                ],
            }
            : {}),
        ...(props.venueName
            ? { location: { '@type': 'Place', name: props.venueName } }
            : {}),
    }),
);
</script>

<template>
    <Head :title="title">
        <meta head-key="description" name="description" :content="pageDescription" />
        <link head-key="canonical" rel="canonical" :href="canonicalUrl" />
        <meta head-key="og:title" property="og:title" :content="title" />
        <meta head-key="og:description" property="og:description" :content="pageDescription" />
        <meta head-key="og:url" property="og:url" :content="canonicalUrl" />
        <meta head-key="og:image" property="og:image" :content="imageUrl" />
        <meta head-key="twitter:title" name="twitter:title" :content="title" />
        <meta head-key="twitter:description" name="twitter:description" :content="pageDescription" />
        <meta head-key="twitter:image" name="twitter:image" :content="imageUrl" />
        <component :is="'script'" head-key="schema-webpage-game" type="application/ld+json" v-html="webPageSchema" />
        <component :is="'script'" head-key="schema-sportsevent-game" type="application/ld+json" v-html="sportsEventSchema" />
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <template v-if="loading">
                <slot name="loading">
                    <div class="space-y-4">
                        <Skeleton class="h-32 w-full" />
                        <Skeleton class="h-64 w-full" />
                        <Skeleton class="h-64 w-full" />
                    </div>
                </slot>
            </template>

            <slot v-else />
        </div>
    </AppLayout>
</template>
