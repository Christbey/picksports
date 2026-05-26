<script setup lang="ts">
import type { GamePageTeam } from '@/types';
import { computed } from 'vue';

const props = defineProps<{
    awayTeam: GamePageTeam | null;
    homeTeam: GamePageTeam | null;
    awayStarterName?: string | null;
    homeStarterName?: string | null;
    awayStarterRating?: number | null;
    homeStarterRating?: number | null;
}>();

const awayLabel = computed(
    () => props.awayTeam?.abbreviation || props.awayTeam?.display_name || 'Away',
);

const homeLabel = computed(
    () => props.homeTeam?.abbreviation || props.homeTeam?.display_name || 'Home',
);

const ratingEdge = computed(() => {
    const away = Number(props.awayStarterRating);
    const home = Number(props.homeStarterRating);

    if (Number.isNaN(away) || Number.isNaN(home)) {
        return null;
    }

    return Math.round(away - home);
});

const edgeTeam = computed(() => {
    if (ratingEdge.value === null || ratingEdge.value === 0) {
        return null;
    }

    return ratingEdge.value > 0 ? awayLabel.value : homeLabel.value;
});

const edgeLabel = computed(() => {
    if (ratingEdge.value === null) {
        return 'Pitcher ratings unavailable';
    }

    if (ratingEdge.value === 0) {
        return 'Even starter rating';
    }

    return `${edgeTeam.value} +${Math.abs(ratingEdge.value)} rating edge`;
});

function formatRating(rating: number | null | undefined): string | null {
    if (rating === null || rating === undefined || Number.isNaN(Number(rating))) {
        return null;
    }

    return Math.round(Number(rating)).toString();
}
</script>

<template>
    <section class="ui-surface p-4 md:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="ui-kicker">Probable Starters</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    Starting pitcher fallback when full depth charts are unavailable.
                </p>
            </div>
            <span
                class="rounded-full bg-muted px-3 py-1 text-xs font-semibold text-foreground/80"
            >
                {{ edgeLabel }}
            </span>
        </div>

        <div class="mt-4 rounded-lg border border-border/60 bg-background/55 p-3">
            <div class="grid gap-3 md:grid-cols-[1fr_auto_1fr] md:items-center">
                <article class="rounded-md border border-border/50 bg-card/70 p-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <img
                                v-if="awayTeam?.logo"
                                :src="awayTeam.logo"
                                :alt="
                                    awayTeam.display_name ||
                                    awayTeam.abbreviation ||
                                    'Away team'
                                "
                                class="h-8 w-8 shrink-0 rounded-full object-contain"
                            />
                            <div class="min-w-0">
                                <div
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ awayStarterName || 'TBD' }}
                                </div>
                                <div
                                    class="text-xs uppercase tracking-[0.14em] text-muted-foreground"
                                >
                                    {{ awayLabel }} away starter
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] uppercase text-muted-foreground">
                                Rating
                            </div>
                            <div class="text-sm font-semibold text-foreground">
                                {{ formatRating(awayStarterRating) || '-' }}
                            </div>
                        </div>
                    </div>
                </article>

                <div
                    class="flex items-center justify-center rounded-md bg-muted px-3 py-2 text-xs font-semibold text-muted-foreground"
                >
                    vs
                </div>

                <article class="rounded-md border border-border/50 bg-card/70 p-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <img
                                v-if="homeTeam?.logo"
                                :src="homeTeam.logo"
                                :alt="
                                    homeTeam.display_name ||
                                    homeTeam.abbreviation ||
                                    'Home team'
                                "
                                class="h-8 w-8 shrink-0 rounded-full object-contain"
                            />
                            <div class="min-w-0">
                                <div
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ homeStarterName || 'TBD' }}
                                </div>
                                <div
                                    class="text-xs uppercase tracking-[0.14em] text-muted-foreground"
                                >
                                    {{ homeLabel }} home starter
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] uppercase text-muted-foreground">
                                Rating
                            </div>
                            <div class="text-sm font-semibold text-foreground">
                                {{ formatRating(homeStarterRating) || '-' }}
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <div
                v-if="ratingEdge !== null"
                class="mt-3 grid gap-2 text-xs text-muted-foreground sm:grid-cols-3"
            >
                <div class="rounded-md bg-muted/70 px-3 py-2">
                    <span class="font-semibold text-foreground">Away:</span>
                    {{ formatRating(awayStarterRating) || '-' }}
                </div>
                <div class="rounded-md bg-muted/70 px-3 py-2">
                    <span class="font-semibold text-foreground">Home:</span>
                    {{ formatRating(homeStarterRating) || '-' }}
                </div>
                <div class="rounded-md bg-muted/70 px-3 py-2">
                    <span class="font-semibold text-foreground">Diff:</span>
                    <div
                        class="inline"
                        :class="{
                            'text-emerald-600 dark:text-emerald-300':
                                ratingEdge !== 0,
                        }"
                    >
                        {{ ratingEdge > 0 ? '+' : '' }}{{ ratingEdge }}
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
