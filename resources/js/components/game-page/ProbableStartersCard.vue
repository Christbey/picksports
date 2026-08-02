<script setup lang="ts">
import type { GamePageTeam, MlbStartingPitcherForecast } from '@/types';
import { computed } from 'vue';

const props = defineProps<{
    awayTeam: GamePageTeam | null;
    homeTeam: GamePageTeam | null;
    awayStarterName?: string | null;
    homeStarterName?: string | null;
    awayStarterRating?: number | null;
    homeStarterRating?: number | null;
    awayStarterSource?: string | null;
    homeStarterSource?: string | null;
    awayStarterConfidence?: number | null;
    homeStarterConfidence?: number | null;
    awayStarterForecast?: MlbStartingPitcherForecast | null;
    homeStarterForecast?: MlbStartingPitcherForecast | null;
}>();

const awayLabel = computed(
    () =>
        props.awayTeam?.abbreviation || props.awayTeam?.display_name || 'Away',
);

const homeLabel = computed(
    () =>
        props.homeTeam?.abbreviation || props.homeTeam?.display_name || 'Home',
);

const ratingEdge = computed(() => {
    if (
        props.awayStarterRating === null ||
        props.awayStarterRating === undefined ||
        props.homeStarterRating === null ||
        props.homeStarterRating === undefined
    ) {
        return null;
    }

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

const cardDescription = computed(() => {
    const sources = [props.awayStarterSource, props.homeStarterSource];

    if (sources.every((source) => source === 'espn_boxscore_confirmed')) {
        return 'Actual starters confirmed from the ESPN box score.';
    }

    if (sources.includes('espn_boxscore_confirmed')) {
        return 'The box score has confirmed one starter; the remaining source is shown below.';
    }

    if (sources.includes('rotation_projection')) {
        return 'Rotation projections update automatically when a trusted source names a different probable starter.';
    }

    if (sources.every((source) => source === 'espn_probable')) {
        return 'Probable starters supplied by ESPN and monitored for changes.';
    }

    return 'Probable starters have not been confirmed and no rotation projection is available yet.';
});

function starterStatus(
    source: string | null | undefined,
    confidence: number | null | undefined,
): string {
    if (source === 'espn_boxscore_confirmed') return 'Confirmed starter';
    if (source === 'espn_probable') return 'ESPN probable';
    if (source === 'rotation_projection') {
        const percentage = Number.isFinite(Number(confidence))
            ? ` ${Math.round(Number(confidence) * 100)}%`
            : '';

        return `Rotation projection${percentage}`;
    }

    return 'Awaiting confirmation';
}

function formatRating(rating: number | null | undefined): string | null {
    if (
        rating === null ||
        rating === undefined ||
        Number.isNaN(Number(rating))
    ) {
        return null;
    }

    return Math.round(Number(rating)).toString();
}

function forecastLabel(forecast: MlbStartingPitcherForecast): string {
    const name =
        forecast.predicted_pitcher?.full_name || 'Unknown rotation pitcher';
    const rating = formatRating(forecast.predicted_pitcher_rating);
    const confidence = Number.isFinite(Number(forecast.confidence))
        ? `${Math.round(Number(forecast.confidence) * 100)}%`
        : null;
    const details = [rating ? `rating ${rating}` : null, confidence]
        .filter(Boolean)
        .join(', ');
    const prediction = details ? `${name} (${details})` : name;

    if (forecast.grade === 'correct') {
        return `Rotation forecast correct: ${prediction}`;
    }

    if (forecast.grade === 'incorrect') {
        return `Rotation forecast missed: ${prediction}`;
    }

    return `Tracked rotation forecast: ${prediction}`;
}

function forecastEligibility(forecast: MlbStartingPitcherForecast): string {
    return forecast.known_before_game_start
        ? 'Pregame eligible'
        : 'Post-start, excluded from accuracy';
}
</script>

<template>
    <section class="ui-surface p-4 md:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="ui-kicker">Starting Pitchers</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ cardDescription }}
                </p>
            </div>
            <span
                class="rounded-full bg-muted px-3 py-1 text-xs font-semibold text-foreground/80"
            >
                {{ edgeLabel }}
            </span>
        </div>

        <div
            class="mt-4 rounded-lg border border-border/60 bg-background/55 p-3"
        >
            <div class="grid gap-3 md:grid-cols-[1fr_auto_1fr] md:items-center">
                <article
                    class="rounded-md border border-border/50 bg-card/70 p-3"
                >
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
                                    {{ awayStarterName || 'Awaiting starter' }}
                                </div>
                                <div
                                    class="text-xs tracking-[0.14em] text-muted-foreground uppercase"
                                >
                                    {{ awayLabel }} away starter
                                </div>
                                <div
                                    class="mt-1 text-[11px] text-muted-foreground"
                                >
                                    {{
                                        starterStatus(
                                            awayStarterSource,
                                            awayStarterConfidence,
                                        )
                                    }}
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div
                                class="text-[10px] text-muted-foreground uppercase"
                            >
                                Rating
                            </div>
                            <div class="text-sm font-semibold text-foreground">
                                {{ formatRating(awayStarterRating) || '—' }}
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="awayStarterForecast"
                        class="mt-3 border-t border-border/40 pt-2 text-[11px] leading-4"
                    >
                        <div
                            :class="{
                                'text-emerald-600 dark:text-emerald-300':
                                    awayStarterForecast.grade === 'correct',
                                'text-amber-700 dark:text-amber-300':
                                    awayStarterForecast.grade === 'incorrect',
                                'text-muted-foreground':
                                    !awayStarterForecast.grade,
                            }"
                        >
                            {{ forecastLabel(awayStarterForecast) }}
                        </div>
                        <div class="text-muted-foreground/80">
                            {{ forecastEligibility(awayStarterForecast) }}
                        </div>
                    </div>
                </article>

                <div
                    class="flex items-center justify-center rounded-md bg-muted px-3 py-2 text-xs font-semibold text-muted-foreground"
                >
                    vs
                </div>

                <article
                    class="rounded-md border border-border/50 bg-card/70 p-3"
                >
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
                                    {{ homeStarterName || 'Awaiting starter' }}
                                </div>
                                <div
                                    class="text-xs tracking-[0.14em] text-muted-foreground uppercase"
                                >
                                    {{ homeLabel }} home starter
                                </div>
                                <div
                                    class="mt-1 text-[11px] text-muted-foreground"
                                >
                                    {{
                                        starterStatus(
                                            homeStarterSource,
                                            homeStarterConfidence,
                                        )
                                    }}
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div
                                class="text-[10px] text-muted-foreground uppercase"
                            >
                                Rating
                            </div>
                            <div class="text-sm font-semibold text-foreground">
                                {{ formatRating(homeStarterRating) || '—' }}
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="homeStarterForecast"
                        class="mt-3 border-t border-border/40 pt-2 text-[11px] leading-4"
                    >
                        <div
                            :class="{
                                'text-emerald-600 dark:text-emerald-300':
                                    homeStarterForecast.grade === 'correct',
                                'text-amber-700 dark:text-amber-300':
                                    homeStarterForecast.grade === 'incorrect',
                                'text-muted-foreground':
                                    !homeStarterForecast.grade,
                            }"
                        >
                            {{ forecastLabel(homeStarterForecast) }}
                        </div>
                        <div class="text-muted-foreground/80">
                            {{ forecastEligibility(homeStarterForecast) }}
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
                    {{ formatRating(awayStarterRating) || '—' }}
                </div>
                <div class="rounded-md bg-muted/70 px-3 py-2">
                    <span class="font-semibold text-foreground">Home:</span>
                    {{ formatRating(homeStarterRating) || '—' }}
                </div>
                <div class="rounded-md bg-muted/70 px-3 py-2">
                    <span class="font-semibold text-foreground"
                        >Diff:&nbsp;</span
                    >
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
