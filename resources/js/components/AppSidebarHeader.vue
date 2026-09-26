<script setup lang="ts">
import { ref } from 'vue';
import { Activity } from 'lucide-vue-next';
import AppLiveScoreRail from '@/components/AppLiveScoreRail.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
const showScores = ref(false);
</script>

<template>
    <div class="sticky top-0 z-30 overflow-hidden">
        <header
            class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 bg-background px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
        >
            <div class="flex items-center gap-2">
                <SidebarTrigger class="-ml-1" />
                <template v-if="breadcrumbs && breadcrumbs.length > 0">
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </template>
            </div>
            <button
                type="button"
                :aria-expanded="showScores"
                aria-controls="header-scores"
                class="ml-auto flex items-center gap-2 rounded-md px-3 py-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                @click="showScores = !showScores"
            >
                <Activity class="size-4" />Scores
            </button>
        </header>
        <div v-if="showScores" id="header-scores"><AppLiveScoreRail /></div>
    </div>
</template>
