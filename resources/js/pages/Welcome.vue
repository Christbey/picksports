<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import HomeNavigation from '@/components/home/HomeNavigation.vue';
import HomeHero from '@/components/home/HomeHero.vue';
import HomeSports from '@/components/home/HomeSports.vue';
import HomeMethod from '@/components/home/HomeMethod.vue';
import HomeRecord from '@/components/home/HomeRecord.vue';
import HomeFooter from '@/components/home/HomeFooter.vue';
import HomeCallToAction from '@/components/home/HomeCallToAction.vue';
import type { PerformanceData } from '@/types/home';
defineProps<{ canRegister: boolean; performance: PerformanceData }>();
</script>
<template>
    <div class="ps-theme min-h-screen bg-background text-foreground">
        <Head title="PickSports - Model-Based Sports Decisions">
            <meta
                head-key="description"
                name="description"
                content="PickSports turns pregame models, market prices, and risk checks into transparent sports betting decisions."
            />
            <meta
                head-key="og:title"
                property="og:title"
                content="PickSports - Model-Based Sports Decisions"
            />
            <meta
                head-key="og:description"
                property="og:description"
                content="Pregame probabilities, market-aware decisions, and a fully graded public record across seven sports."
            />
            <meta
                head-key="twitter:title"
                name="twitter:title"
                content="PickSports - Model-Based Sports Decisions"
            />
            <meta
                head-key="twitter:description"
                name="twitter:description"
                content="Pregame probabilities, market-aware decisions, and a fully graded public record across seven sports."
            />
        </Head>
        <a
            href="#main-content"
            class="sr-only z-50 rounded-md bg-primary px-4 py-3 text-primary-foreground focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >Skip to content</a
        >
        <HomeNavigation
            :authenticated="Boolean($page.props.auth.user)"
            :can-register="canRegister"
        />
        <main id="main-content" tabindex="-1" class="focus:outline-none">
            <HomeHero
                :authenticated="Boolean($page.props.auth.user)"
                :can-register="canRegister"
                :performance="performance"
            />
            <HomeSports />
            <HomeMethod />
            <HomeRecord :performance="performance" />
            <HomeCallToAction
                :authenticated="Boolean($page.props.auth.user)"
                :can-register="canRegister"
            />
        </main>
        <HomeFooter />
    </div>
</template>
