<script setup lang="ts">
import SportPlayerStatsShell from '@/components/player-stats/SportPlayerStatsShell.vue';
import { cfbPlayerStatsPageConfig } from '@/config/player-stats-page-configs';
</script>

<template>
    <SportPlayerStatsShell :config="cfbPlayerStatsPageConfig" />
</template>
