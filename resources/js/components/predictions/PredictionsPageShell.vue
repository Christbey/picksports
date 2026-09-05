<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { responsibleGambling } from '@/routes';

const props = defineProps<{
    title: string;
    sportTitle: string;
    sportHref: string;
    pageTitle: string;
    bannerStorageKey: string;
    seoDescription?: string;
}>();

const page = usePage();
const description = computed(
    () =>
        props.seoDescription ??
        `${props.title} with data-driven picks, model confidence, and line analysis from PickSports.`,
);
const canonicalUrl = computed(() => {
    const path = (page.url ?? '/').split('?')[0] || '/';
    if (typeof window !== 'undefined') {
        return `${window.location.origin}${path}`;
    }
    return `https://picksports.app${path}`;
});
const imageUrl = 'https://picksports.app/icon-512.png?v=ps-gradient-2';
const webPageSchema = computed(() =>
    JSON.stringify(
        {
            '@context': 'https://schema.org',
            '@type': 'WebPage',
            name: props.title,
            description: description.value,
            url: canonicalUrl.value,
        },
        null,
        0,
    ),
);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: props.sportTitle, href: props.sportHref },
    { title: props.pageTitle },
]);
</script>

<template>
    <Head :title="title">
        <meta
            head-key="description"
            name="description"
            :content="description"
        />
        <meta head-key="og:title" property="og:title" :content="title" />
        <meta
            head-key="og:description"
            property="og:description"
            :content="description"
        />
        <meta head-key="og:url" property="og:url" :content="canonicalUrl" />
        <meta head-key="og:image" property="og:image" :content="imageUrl" />
        <meta head-key="twitter:title" name="twitter:title" :content="title" />
        <meta
            head-key="twitter:description"
            name="twitter:description"
            :content="description"
        />
        <meta
            head-key="twitter:image"
            name="twitter:image"
            :content="imageUrl"
        />
        <component
            :is="'script'"
            head-key="schema-webpage"
            type="application/ld+json"
        >
            {{ webPageSchema }}
        </component>
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-5 overflow-x-auto rounded-[1.25rem] p-3 md:p-4"
        >
            <SubscriptionBanner
                variant="subtle"
                :storage-key="bannerStorageKey"
            />

            <slot />

            <Alert class="ui-surface-subtle">
                <AlertDescription>
                    <strong>Entertainment Only:</strong> These predictions are
                    for entertainment purposes only. Past performance does not
                    guarantee future results. Please gamble responsibly. If you
                    or someone you know has a gambling problem, call
                    1-800-522-4700 or visit our
                    <Link :href="responsibleGambling()" class="underline"
                        >Responsible Gambling</Link
                    >
                    page.
                </AlertDescription>
            </Alert>
        </div>
    </AppLayout>
</template>
