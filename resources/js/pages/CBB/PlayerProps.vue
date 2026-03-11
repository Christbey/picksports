<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SportPlayerPropsShell from '@/components/player-props/SportPlayerPropsShell.vue';
import { getPlayerPropsPageConfig } from '@/config/player-props-page-configs';

const props = defineProps<{
    sport: string;
    filters: { date: string | null; game: string | number | null; market: string | null };
}>();

const config = getPlayerPropsPageConfig(props.sport);
</script>

<template>
    <Head :title="`${config.sportLabel} Player Props`" />
    <SportPlayerPropsShell
        :sport="sport"
        :sport-slug="config.sportSlug"
        :filters="filters"
        :sport-label="config.sportLabel"
        :description="config.description"
    />
</template>
