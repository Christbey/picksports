<script setup lang="ts">
import { useBracketBuilderContext } from '@/composables/useBracketBuilderContext';
const bracket = useBracketBuilderContext();
</script>

<template>
    <article
        v-if="bracket.isAuthenticated && bracket.leaderboard.length"
        class="relative overflow-hidden rounded-[2rem] border border-border/70 bg-card/80 p-4 shadow-[0_20px_60px_-32px_rgba(15,23,42,0.18)] backdrop-blur sm:p-6"
    >
        <div
            class="absolute inset-0 bg-gradient-to-br from-foreground/[0.04] via-transparent to-transparent dark:from-foreground/[0.06]"
        />
        <div class="relative">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <p
                        class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                    >
                        Leaderboard
                    </p>
                    <h3
                        class="mt-2 text-xl font-semibold text-foreground sm:text-2xl"
                    >
                        Bracket standings
                    </h3>
                </div>
                <p
                    class="text-xs tracking-[0.18em] text-muted-foreground uppercase"
                >
                    Season {{ bracket.currentSeason }}
                </p>
            </div>

            <div
                class="mt-5 overflow-hidden rounded-[1.5rem] border border-border/70 bg-background/35"
            >
                <div
                    v-for="entry in bracket.leaderboard"
                    :key="entry.bracket_public_id"
                    class="grid grid-cols-[56px_minmax(0,1fr)_84px] items-center gap-3 border-b border-border/60 px-4 py-3 last:border-b-0"
                >
                    <div class="text-sm font-semibold text-foreground/80">
                        #{{ entry.rank }}
                    </div>
                    <div class="min-w-0">
                        <p
                            class="truncate text-sm font-semibold text-foreground"
                        >
                            {{ entry.bracket_name }}
                        </p>
                        <p
                            class="truncate text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                        >
                            {{ bracket.maskedLeaderboardUser(entry.user_name) }}
                            · {{ entry.correct_picks }} correct ·
                            {{ entry.incorrect_picks }} missed
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-semibold text-foreground">
                            {{ entry.points_earned }}
                        </p>
                        <p
                            class="text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                        >
                            pts
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </article>
</template>
