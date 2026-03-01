<script setup lang="ts">
import SportTeamMetrics from '@/components/SportTeamMetrics.vue';
import { wnbaTeamMetricsConfig } from '@/config/sport-team-metrics-configs';
</script>

<template>
    <SportTeamMetrics :config="wnbaTeamMetricsConfig" />
</template>
