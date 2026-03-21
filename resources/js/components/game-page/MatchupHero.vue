<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
        winnerCorrect?: boolean | null;
        actualTotal?: number | null;
        bettingValue?: BettingRecommendation[];
        contextBadgeLabel?: string | null;
    }>(),
    {
        extraInfoItems: () => [],
        showScoreStatuses: () => ['STATUS_FINAL'],
        badgePulseStatuses: () => [],
        linkTeams: true,
        useTeamColorGlow: false,
        winnerCorrect: null,
        actualTotal: null,
        bettingValue: () => [],
        contextBadgeLabel: null,
    },
);

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
</script>

<template>
    <div
        class="ui-surface overflow-hidden text-white"
        :class="props.gradientClass"
    >
        <div class="px-5 py-7 md:px-6 md:py-8">
            <div
                class="flex flex-col items-center justify-between gap-6 md:flex-row"
            >
                <component
                    :is="props.linkTeams ? Link : 'div'"
                    v-if="awayTeam"
                    :href="props.linkTeams ? teamLink(awayTeam.id) : undefined"
                    class="flex flex-1 flex-col items-center gap-2 transition-opacity hover:opacity-85 md:items-end"
                >
                    <div class="relative">
                        <div
                            v-if="props.useTeamColorGlow && awayTeam.color"
                            class="absolute inset-0 rounded-full opacity-20 blur-xl"
                            :style="{ backgroundColor: `#${awayTeam.color}` }"
                        />
                        <img
                            v-if="awayTeam.logo"
                            :src="awayTeam.logo"
                            :alt="awayTeam.name || 'Away Team'"
                            class="relative z-10 h-20 w-20 object-contain drop-shadow-lg md:h-24 md:w-24"
                        />
                    </div>
                    <div class="text-center md:text-right">
                        <div
                            class="text-xl font-semibold tracking-tight md:text-2xl"
                        >
                            {{
                                awayTeam.display_name ||
                                `${awayTeam.location || ''} ${awayTeam.name || ''}`.trim() ||
                                awayTeam.name ||
                                awayTeam.abbreviation ||
                                'Away Team'
                            }}
                        </div>
                        <div
                            class="text-xs tracking-wide text-white/70 uppercase"
                        >
                            Away
                        </div>
                        <div
                            v-if="awayRecentForm"
                            class="mt-1 text-xs text-white/60"
                        >
                            {{ awayRecentForm }}
                        </div>
                    </div>
                </component>

                <div class="min-w-[120px] text-center">
                    <div
                        v-if="
                            props.showScoreStatuses.includes(game.status) &&
                            game.away_score !== undefined &&
                            game.home_score !== undefined
                        "
                        class="text-4xl font-semibold tracking-tight md:text-5xl"
                    >
                        {{ game.away_score }} - {{ game.home_score }}
                    </div>
                    <div
                        v-else
                        class="text-2xl font-semibold text-white/70 md:text-3xl"
                    >
                        vs
                    </div>
                    <div class="mt-2 flex items-center justify-center gap-2">
                        <Badge
                            class="border-white/30 bg-white/20 text-white hover:bg-white/30"
                            :class="{
                                'animate-pulse !border-red-500 !bg-red-600':
                                    props.badgePulseStatuses.includes(
                                        game.status,
                                    ),
                            }"
                            >{{ gameStatus }}</Badge
                        >
                        <Badge
                            v-if="props.contextBadgeLabel"
                            class="border-white/35 bg-white/20 text-white hover:bg-white/30"
                        >
                            {{ props.contextBadgeLabel }}
                        </Badge>
                        <Badge
                            v-if="winnerLabel()"
                            class="border-white/35 bg-white/20 text-white hover:bg-white/30"
                            :class="winnerClass()"
                        >
                            {{ winnerLabel() }}
                        </Badge>
                        <Badge
                            v-if="totalLabel()"
                            class="border-white/35 bg-white/20 text-white hover:bg-white/30"
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
                    class="flex flex-1 flex-col items-center gap-2 transition-opacity hover:opacity-85 md:items-start"
                >
                    <div class="relative">
                        <div
                            v-if="props.useTeamColorGlow && homeTeam.color"
                            class="absolute inset-0 rounded-full opacity-20 blur-xl"
                            :style="{ backgroundColor: `#${homeTeam.color}` }"
                        />
                        <img
                            v-if="homeTeam.logo"
                            :src="homeTeam.logo"
                            :alt="homeTeam.name || 'Home Team'"
                            class="relative z-10 h-20 w-20 object-contain drop-shadow-lg md:h-24 md:w-24"
                        />
                    </div>
                    <div class="text-center md:text-left">
                        <div
                            class="text-xl font-semibold tracking-tight md:text-2xl"
                        >
                            {{
                                homeTeam.display_name ||
                                `${homeTeam.location || ''} ${homeTeam.name || ''}`.trim() ||
                                homeTeam.name ||
                                homeTeam.abbreviation ||
                                'Home Team'
                            }}
                        </div>
                        <div
                            class="text-xs tracking-wide text-white/70 uppercase"
                        >
                            Home
                        </div>
                        <div
                            v-if="homeRecentForm"
                            class="mt-1 text-xs text-white/60"
                        >
                            {{ homeRecentForm }}
                        </div>
                    </div>
                </component>
            </div>
        </div>

        <div
            class="border-t border-white/15 bg-black/20 px-5 py-3 text-sm text-white/80 md:px-6"
        >
            <div
                class="flex flex-wrap items-center justify-center gap-x-6 gap-y-1"
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
