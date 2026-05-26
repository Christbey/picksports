<script setup lang="ts">
import type { MatchupContextData, MatchupContextRow } from '@/types';

defineProps<{
    title?: string;
    awayLabel?: string | null;
    homeLabel?: string | null;
    matchupContext: MatchupContextData | null;
}>();

const winRate = (record: MatchupContextRow['away']): number | null => {
    if (!record.games) return null;

    return (record.wins + record.ties * 0.5) / record.games;
};

const formatWinRate = (record: MatchupContextRow['away']): string => {
    const rate = winRate(record);

    return rate === null ? 'No sample' : `${Math.round(rate * 100)}%`;
};

const sampleLabel = (row: MatchupContextRow): string => {
    const games = Math.max(row.away.games || 0, row.home.games || 0);

    if (games === 0) return 'No sample';

    return `${games} ${games === 1 ? 'game' : 'games'}`;
};

const edgeLabel = (
    row: MatchupContextRow,
    awayLabel?: string | null,
    homeLabel?: string | null,
): string => {
    const awayRate = winRate(row.away);
    const homeRate = winRate(row.home);

    if (awayRate === null && homeRate === null) return 'No sample';
    if (awayRate === homeRate) return 'Even';
    if ((awayRate ?? 0) > (homeRate ?? 0)) {
        return `${awayLabel || 'Away'} edge`;
    }

    return `${homeLabel || 'Home'} edge`;
};

const edgeClass = (
    row: MatchupContextRow,
    side: 'away' | 'home',
): string => {
    const awayRate = winRate(row.away);
    const homeRate = winRate(row.home);

    if (awayRate === null || homeRate === null || awayRate === homeRate) {
        return 'text-foreground';
    }

    const sideHasEdge =
        (side === 'away' && awayRate > homeRate) ||
        (side === 'home' && homeRate > awayRate);

    return sideHasEdge
        ? 'text-emerald-600 dark:text-emerald-300'
        : 'text-muted-foreground';
};
</script>

<template>
    <section v-if="matchupContext?.rows?.length" class="ui-surface p-4 md:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="ui-kicker">{{ title || 'Matchup Records' }}</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    Historical record context entering this matchup.
                </p>
            </div>
            <div class="flex items-center gap-2 text-[11px] font-semibold">
                <span class="rounded-full bg-muted px-2.5 py-1 text-foreground/80">
                    {{ awayLabel || 'Away' }}
                </span>
                <span class="text-muted-foreground">vs</span>
                <span class="rounded-full bg-muted px-2.5 py-1 text-foreground/80">
                    {{ homeLabel || 'Home' }}
                </span>
            </div>
        </div>

        <div class="mt-4 space-y-2.5">
            <article
                v-for="row in matchupContext.rows"
                :key="row.key"
                class="rounded-lg border border-border/60 bg-background/55 p-3"
            >
                <div
                    class="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(10rem,0.9fr)_minmax(0,1fr)] md:items-center"
                >
                    <div
                        class="flex items-center justify-between gap-3 rounded-md bg-card/70 px-3 py-2 md:justify-end"
                    >
                        <div class="md:text-right">
                            <div
                                class="text-lg font-semibold leading-none"
                                :class="edgeClass(row, 'away')"
                            >
                                {{ row.away.display }}
                            </div>
                            <div class="mt-1 text-[11px] text-muted-foreground">
                                {{ awayLabel || 'Away' }} {{ formatWinRate(row.away) }}
                            </div>
                        </div>
                    </div>

                    <div class="text-center">
                        <div class="text-sm font-semibold text-foreground">
                            {{ row.label }}
                        </div>
                        <div
                            v-if="row.subtitle"
                            class="mt-0.5 text-xs text-muted-foreground"
                        >
                            {{ row.subtitle }}
                        </div>
                        <div
                            class="mt-2 flex flex-wrap items-center justify-center gap-1.5"
                        >
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                            >
                                {{ sampleLabel(row) }}
                            </span>
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-semibold text-foreground/80"
                            >
                                {{ edgeLabel(row, awayLabel, homeLabel) }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-between gap-3 rounded-md bg-card/70 px-3 py-2"
                    >
                        <div>
                            <div
                                class="text-lg font-semibold leading-none"
                                :class="edgeClass(row, 'home')"
                            >
                                {{ row.home.display }}
                            </div>
                            <div class="mt-1 text-[11px] text-muted-foreground">
                                {{ homeLabel || 'Home' }} {{ formatWinRate(row.home) }}
                            </div>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
