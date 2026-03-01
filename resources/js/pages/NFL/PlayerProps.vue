<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SportPlayerPropsShell from '@/components/player-props/SportPlayerPropsShell.vue';
import { getPlayerPropsPageConfig } from '@/config/player-props-page-configs';

type Recommendation = {
    id: number;
    player: { id: number; name: string; position: string; team: string; headshot: string; url: string | null };
    market: string;
    line: number;
    recommendation: 'Over' | 'Under';
    odds: number;
    confidence: number;
    stats: {
        season_avg: number;
        recent_avg: number;
        last5_avg: number;
        times_covered_last5: { hits: number; games: number } | null;
        times_covered_season: { hits: number; games: number } | null;
        vs_opponent_avg: number | null;
        consistency: { std_dev: number; level: string; min: number; max: number } | null;
    };
    streak: { count: number; type: string; status: string } | null;
    edge: number;
    reasoning: string[];
    game: { id: number; home_team: string; away_team: string; date: string; time: string };
    bookmaker: string;
};

type DateOption = { value: string; label: string };
type GameOption = { id: number; label: string; date: string; time: string };

const props = defineProps<{
    sport: string;
    recommendations: Recommendation[];
    dates: DateOption[];
    games: GameOption[];
    filters: { date: string | null; game: string | number | null };
}>();

const config = getPlayerPropsPageConfig(props.sport);
</script>

<template>
    <Head :title="`${config.sportLabel} Player Props`" />
    <SportPlayerPropsShell
        :sport="sport"
        :recommendations="recommendations"
        :dates="dates"
        :games="games"
        :filters="filters"
        :sport-label="config.sportLabel"
        :description="config.description"
    />
</template>
