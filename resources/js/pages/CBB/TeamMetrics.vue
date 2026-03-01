<script setup lang="ts">
import SportTeamMetrics from '@/components/SportTeamMetrics.vue';
import { cbbTeamMetricsConfig } from '@/config/sport-team-metrics-configs';
</script>

<template>
    <SportTeamMetrics :config="cbbTeamMetricsConfig" />
</template>
