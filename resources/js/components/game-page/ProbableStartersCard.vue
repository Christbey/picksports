<script setup lang="ts">
import type { GamePageTeam } from '@/types';

defineProps<{
    awayTeam: GamePageTeam | null;
    homeTeam: GamePageTeam | null;
    awayStarterName?: string | null;
    homeStarterName?: string | null;
    awayStarterRating?: number | null;
    homeStarterRating?: number | null;
}>();

function formatRating(rating: number | null | undefined): string | null {
    if (rating === null || rating === undefined || Number.isNaN(Number(rating))) {
        return null;
    }

    return Math.round(Number(rating)).toString();
}
</script>

<template>
    <section class="ui-surface p-5 md:p-6">
        <h3 class="ui-kicker">Probable Starters</h3>
        <p class="mt-2 text-sm text-muted-foreground">
            Probable starters for this matchup when full team depth charts are unavailable.
        </p>

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            <article class="ui-surface-subtle p-4">
                <div class="flex items-center gap-3">
                    <img
                        v-if="awayTeam?.logo"
                        :src="awayTeam.logo"
                        :alt="awayTeam.display_name || awayTeam.abbreviation || 'Away team'"
                        class="h-10 w-10 rounded-full object-contain"
                    />
                    <div>
                        <div class="text-sm font-semibold text-foreground">
                            {{ awayTeam?.display_name || awayTeam?.abbreviation || 'Away Team' }}
                        </div>
                        <div class="text-xs uppercase tracking-[0.18em] text-muted-foreground">
                            Away
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="text-xs uppercase tracking-[0.18em] text-muted-foreground">
                        Starting Pitcher
                    </div>
                    <div class="mt-1 text-base font-semibold text-foreground">
                        {{ awayStarterName || 'TBD' }}
                    </div>
                    <div
                        v-if="formatRating(awayStarterRating)"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Rating: {{ formatRating(awayStarterRating) }}
                    </div>
                </div>
            </article>

            <article class="ui-surface-subtle p-4">
                <div class="flex items-center gap-3">
                    <img
                        v-if="homeTeam?.logo"
                        :src="homeTeam.logo"
                        :alt="homeTeam.display_name || homeTeam.abbreviation || 'Home team'"
                        class="h-10 w-10 rounded-full object-contain"
                    />
                    <div>
                        <div class="text-sm font-semibold text-foreground">
                            {{ homeTeam?.display_name || homeTeam?.abbreviation || 'Home Team' }}
                        </div>
                        <div class="text-xs uppercase tracking-[0.18em] text-muted-foreground">
                            Home
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="text-xs uppercase tracking-[0.18em] text-muted-foreground">
                        Starting Pitcher
                    </div>
                    <div class="mt-1 text-base font-semibold text-foreground">
                        {{ homeStarterName || 'TBD' }}
                    </div>
                    <div
                        v-if="formatRating(homeStarterRating)"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Rating: {{ formatRating(homeStarterRating) }}
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
