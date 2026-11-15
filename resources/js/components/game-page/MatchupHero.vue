<script setup lang="ts">
import { computed } from 'vue';
import { normalizeTeamColor } from '@/lib/teamColor';
import { Link } from '@/platform';
import { Badge } from '@/components/ui/badge';
import type {
    BettingRecommendation,
    GamePageGame,
    GamePageHrefLike,
    GamePageTeam,
} from '@/types';

const props = withDefaults(
    defineProps<{
        awayTeam: GamePageTeam | null;
        homeTeam: GamePageTeam | null;
        awayRecentForm?: string;
        homeRecentForm?: string;
        game: GamePageGame;
        gameStatus: string;
        formatDate: (dateString: string | null) => string;
        teamLink: (id: number) => GamePageHrefLike;
        gradientClass: string;
        venueLabel?: string | null;
        broadcastNetworks?: string[];
        extraInfoItems?: string[];
        showScoreStatuses?: string[];
        badgePulseStatuses?: string[];
        linkTeams?: boolean;
        useTeamColorGlow?: boolean;
        compact?: boolean;
        winnerCorrect?: boolean | null;
        actualTotal?: number | null;
        bettingValue?: BettingRecommendation[];
        contextBadgeLabel?: string | null;
        awayStarterName?: string | null;
        homeStarterName?: string | null;
        awayStarterRating?: number | null;
        homeStarterRating?: number | null;
    }>(),
    {
        extraInfoItems: () => [],
        showScoreStatuses: () => ['STATUS_FINAL'],
        badgePulseStatuses: () => [],
        linkTeams: true,
        useTeamColorGlow: false,
        compact: false,
        winnerCorrect: null,
        actualTotal: null,
        bettingValue: () => [],
        contextBadgeLabel: null,
        awayStarterName: null,
        homeStarterName: null,
        awayStarterRating: null,
        homeStarterRating: null,
    },
);

const awayColor = computed(() => normalizeTeamColor(props.awayTeam?.color));
const homeColor = computed(() => normalizeTeamColor(props.homeTeam?.color));

const teamName = (team: GamePageTeam, fallback: string): string =>
    team.display_name ||
    `${team.location || ''} ${team.name || ''}`.trim() ||
    team.abbreviation ||
    fallback;

function winnerLabel(): string | null {
    if (props.winnerCorrect === true) return 'WIN';
    if (props.winnerCorrect === false) return 'LOSS';

    return null;
}

function winnerClass(): string {
    if (props.winnerCorrect === true) {
        return '!bg-green-600 !border-green-500 !text-white';
    }

    if (props.winnerCorrect === false) {
        return '!bg-red-600 !border-red-500 !text-white';
    }

    return '';
}

function parseTotalDirection(recommendation: string): 'over' | 'under' | null {
    const normalized = recommendation.toLowerCase();
    if (normalized.includes('over')) return 'over';
    if (normalized.includes('under')) return 'under';

    return null;
}

function totalLabel(): string | null {
    const totalBet = props.bettingValue?.find((bet) => bet.type === 'total');
    if (!totalBet) return null;
    if (props.actualTotal === null || props.actualTotal === undefined)
        return null;
    if (totalBet.market_line === null || totalBet.market_line === undefined)
        return null;

    const direction = parseTotalDirection(totalBet.recommendation);
    if (!direction) return null;

    const actual = Number(props.actualTotal);
    const line = Number(totalBet.market_line);
    if (!Number.isFinite(actual) || !Number.isFinite(line)) return null;

    if (actual === line) return 'O/U PUSH';
    const correct = direction === 'over' ? actual > line : actual < line;

    return correct ? 'O/U WIN' : 'O/U LOSS';
}

function totalClass(): string {
    const label = totalLabel();
    if (label === 'O/U WIN') {
        return '!bg-green-600 !border-green-500 !text-white';
    }

    if (label === 'O/U LOSS') {
        return '!bg-red-600 !border-red-500 !text-white';
    }

    if (label === 'O/U PUSH') {
        return '!bg-zinc-600 !border-zinc-500 !text-white';
    }

    return '';
}

function formatStarterLine(
    name: string | null | undefined,
    rating: number | null | undefined,
): string | null {
    if (!name) return null;
    if (
        rating === null ||
        rating === undefined ||
        Number.isNaN(Number(rating))
    ) {
        return `SP: ${name}`;
    }

    return `SP: ${name} (${Math.round(Number(rating))})`;
}
</script>

<template>
    <div class="ui-surface overflow-hidden">
        <div
            class="flex h-1 w-full"
            :class="props.gradientClass"
            aria-hidden="true"
        >
            <span class="flex-1" :style="{ backgroundColor: awayColor }" />
            <span class="flex-1" :style="{ backgroundColor: homeColor }" />
        </div>
        <div class="px-3 py-4 sm:px-4 md:px-6 md:py-6">
            <div
                data-matchup-row
                class="grid items-center md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]"
                :class="
                    compact
                        ? 'grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] gap-2 md:gap-4'
                        : 'gap-4'
                "
            >
                <component
                    :is="props.linkTeams ? Link : 'div'"
                    v-if="awayTeam"
                    :href="props.linkTeams ? teamLink(awayTeam.id) : undefined"
                    :aria-label="`${teamName(awayTeam, 'Away Team')} (Away)`"
                    class="flex min-w-0 items-center rounded-md transition-opacity hover:opacity-85 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring md:justify-end"
                    :class="
                        compact
                            ? 'min-h-11 flex-col gap-1 md:flex-row md:gap-3'
                            : 'gap-3'
                    "
                >
                    <div class="relative shrink-0">
                        <div
                            v-if="props.useTeamColorGlow && awayColor"
                            class="absolute inset-0 rounded-full opacity-15 blur-lg"
                            :style="{ backgroundColor: awayColor }"
                        />
                        <img
                            v-if="awayTeam.logo"
                            :src="awayTeam.logo"
                            :alt="awayTeam.name || 'Away Team'"
                            class="relative z-10 object-contain md:h-16 md:w-16"
                            :class="compact ? 'h-10 w-10' : 'h-14 w-14'"
                        />
                    </div>
                    <div
                        class="max-w-full min-w-0 md:text-right"
                        :class="compact ? 'text-center' : 'text-left'"
                    >
                        <div
                            class="truncate font-semibold tracking-tight md:text-xl"
                            :class="compact ? 'text-sm' : 'text-lg'"
                        >
                            <span v-if="compact" class="md:hidden">{{
                                awayTeam.abbreviation ||
                                teamName(awayTeam, 'Away')
                            }}</span>
                            <span :class="compact ? 'hidden md:inline' : ''">{{
                                teamName(awayTeam, 'Away Team')
                            }}</span>
                        </div>
                        <div
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Away
                        </div>
                        <div
                            v-if="awayRecentForm"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ awayRecentForm }}
                        </div>
                        <div
                            v-if="
                                formatStarterLine(
                                    awayStarterName,
                                    awayStarterRating,
                                )
                            "
                            class="mt-1 truncate text-xs text-muted-foreground"
                        >
                            {{
                                formatStarterLine(
                                    awayStarterName,
                                    awayStarterRating,
                                )
                            }}
                        </div>
                    </div>
                </component>

                <div
                    class="min-w-0 text-center"
                    :class="
                        compact ? 'max-w-32 px-1 md:max-w-none md:px-4' : 'px-4'
                    "
                >
                    <div
                        v-if="
                            props.showScoreStatuses.includes(game.status) &&
                            game.away_score !== undefined &&
                            game.home_score !== undefined
                        "
                        class="font-bold tracking-tight whitespace-nowrap tabular-nums md:text-5xl"
                        :class="compact ? 'text-xl sm:text-3xl' : 'text-3xl'"
                    >
                        {{ game.away_score }} - {{ game.home_score }}
                    </div>
                    <div
                        v-else
                        class="text-lg font-medium text-muted-foreground md:text-xl"
                    >
                        vs
                    </div>
                    <div
                        class="mt-2 flex flex-wrap items-center justify-center gap-1.5"
                    >
                        <Badge
                            variant="secondary"
                            :class="{
                                'animate-pulse !border-red-500 !bg-red-600':
                                    props.badgePulseStatuses.includes(
                                        game.status,
                                    ),
                            }"
                            >{{ gameStatus }}</Badge
                        >
                        <Badge v-if="props.contextBadgeLabel" variant="outline">
                            {{ props.contextBadgeLabel }}
                        </Badge>
                        <Badge
                            v-if="winnerLabel()"
                            variant="outline"
                            :class="winnerClass()"
                        >
                            {{ winnerLabel() }}
                        </Badge>
                        <Badge
                            v-if="totalLabel()"
                            variant="outline"
                            :class="totalClass()"
                        >
                            {{ totalLabel() }}
                        </Badge>
                    </div>
                </div>

                <component
                    :is="props.linkTeams ? Link : 'div'"
                    v-if="homeTeam"
                    :href="props.linkTeams ? teamLink(homeTeam.id) : undefined"
                    :aria-label="`${teamName(homeTeam, 'Home Team')} (Home)`"
                    class="flex min-w-0 items-center rounded-md transition-opacity hover:opacity-85 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                    :class="
                        compact
                            ? 'min-h-11 flex-col gap-1 md:flex-row md:gap-3'
                            : 'gap-3'
                    "
                >
                    <div class="relative shrink-0 md:order-2">
                        <div
                            v-if="props.useTeamColorGlow && homeColor"
                            class="absolute inset-0 rounded-full opacity-15 blur-lg"
                            :style="{ backgroundColor: homeColor }"
                        />
                        <img
                            v-if="homeTeam.logo"
                            :src="homeTeam.logo"
                            :alt="homeTeam.name || 'Home Team'"
                            class="relative z-10 object-contain md:h-16 md:w-16"
                            :class="compact ? 'h-10 w-10' : 'h-14 w-14'"
                        />
                    </div>
                    <div
                        class="max-w-full min-w-0 md:text-left"
                        :class="compact ? 'text-center' : 'text-left'"
                    >
                        <div
                            class="truncate font-semibold tracking-tight md:text-xl"
                            :class="compact ? 'text-sm' : 'text-lg'"
                        >
                            <span v-if="compact" class="md:hidden">{{
                                homeTeam.abbreviation ||
                                teamName(homeTeam, 'Home')
                            }}</span>
                            <span :class="compact ? 'hidden md:inline' : ''">{{
                                teamName(homeTeam, 'Home Team')
                            }}</span>
                        </div>
                        <div
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Home
                        </div>
                        <div
                            v-if="homeRecentForm"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ homeRecentForm }}
                        </div>
                        <div
                            v-if="
                                formatStarterLine(
                                    homeStarterName,
                                    homeStarterRating,
                                )
                            "
                            class="mt-1 truncate text-xs text-muted-foreground"
                        >
                            {{
                                formatStarterLine(
                                    homeStarterName,
                                    homeStarterRating,
                                )
                            }}
                        </div>
                    </div>
                </component>
            </div>
        </div>

        <div
            class="border-t border-border/70 bg-muted/25 px-4 py-2.5 text-xs text-muted-foreground sm:text-sm md:px-6"
        >
            <div
                class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1.5 md:gap-x-6"
            >
                <span>{{ formatDate(game.game_date) }}</span>
                <span v-for="(item, idx) in props.extraInfoItems" :key="idx">{{
                    item
                }}</span>
                <span v-if="venueLabel" class="flex items-center gap-1">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-3.5 w-3.5"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    {{ venueLabel }}
                </span>
                <span
                    v-if="broadcastNetworks && broadcastNetworks.length > 0"
                    class="flex items-center gap-1"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-3.5 w-3.5"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                    >
                        <path
                            d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z"
                        />
                    </svg>
                    {{ broadcastNetworks.join(', ') }}
                </span>
            </div>
        </div>
    </div>
</template>
