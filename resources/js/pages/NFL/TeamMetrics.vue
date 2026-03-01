<script setup lang="ts">
import SportTeamMetrics from '@/components/SportTeamMetrics.vue';
import { nflTeamMetricsConfig } from '@/config/sport-team-metrics-configs';
</script>

<template>
    <SportTeamMetrics :config="nflTeamMetricsConfig" />
</template>
