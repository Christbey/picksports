<script setup lang="ts">
import SportTeamMetrics from '@/components/SportTeamMetrics.vue';
import { mlbTeamMetricsConfig } from '@/config/sport-team-metrics-configs';
</script>

<template>
    <SportTeamMetrics :config="mlbTeamMetricsConfig" />
</template>
