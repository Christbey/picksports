<script setup lang="ts">
import SportTeamMetrics from '@/components/SportTeamMetrics.vue';
import { nbaTeamMetricsConfig } from '@/config/sport-team-metrics-configs';
</script>

<template>
    <SportTeamMetrics :config="nbaTeamMetricsConfig" />
</template>
