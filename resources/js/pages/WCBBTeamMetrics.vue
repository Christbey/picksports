<script setup lang="ts">
import SportTeamMetrics from '@/components/SportTeamMetrics.vue';
import { wcbbTeamMetricsConfig } from '@/config/sport-team-metrics-configs';
</script>

<template>
    <SportTeamMetrics :config="wcbbTeamMetricsConfig" />
</template>
