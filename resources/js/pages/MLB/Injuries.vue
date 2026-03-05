<script setup lang="ts">
import SportInjuries from '@/components/SportInjuries.vue';
import { mlbInjuriesConfig } from '@/config/sport-injuries-configs';
</script>

<template>
    <SportInjuries :config="mlbInjuriesConfig" />
</template>
