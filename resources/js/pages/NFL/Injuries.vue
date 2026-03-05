<script setup lang="ts">
import SportInjuries from '@/components/SportInjuries.vue';
import { nflInjuriesConfig } from '@/config/sport-injuries-configs';
</script>

<template>
    <SportInjuries :config="nflInjuriesConfig" />
</template>
