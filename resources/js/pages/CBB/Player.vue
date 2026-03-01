<script setup lang="ts">
import SportPlayerShell from '@/components/player-page/SportPlayerShell.vue';
import { cbbPlayerPageConfig } from '@/config/player-page-configs';

interface PlayerPagePlayer {
    id: number;
    team_id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    name: string;
    jersey_number: string | null;
    position: string | null;
    height: string | null;
    weight: string | null;
    headshot_url: string | null;
    team: {
        id: number;
        name: string;
        display_name: string;
        abbreviation: string;
    } | null;
}

interface PlayerPageProp {
    id: number;
    market: string;
    line: number;
    over_price: number;
    under_price: number;
    game: {
        id: number;
        home_team: string;
        away_team: string;
    };
}

defineProps<{
    player: PlayerPagePlayer;
    playerProps?: PlayerPageProp[];
}>();
</script>

<template>
    <SportPlayerShell :config="cbbPlayerPageConfig" :player="player" :player-props="playerProps" />
</template>
