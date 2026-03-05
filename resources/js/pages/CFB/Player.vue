<script setup lang="ts">
import SportPlayerShell from '@/components/player-page/SportPlayerShell.vue';
import { cfbPlayerPageConfig } from '@/config/player-page-configs';

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

defineProps<{ player: PlayerPagePlayer }>();
</script>

<template>
    <SportPlayerShell :config="cfbPlayerPageConfig" :player="player" />
</template>
