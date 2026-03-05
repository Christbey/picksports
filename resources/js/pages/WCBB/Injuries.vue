<script setup lang="ts">
import SportInjuries from '@/components/SportInjuries.vue';
import { wcbbInjuriesConfig } from '@/config/sport-injuries-configs';
</script>

<template>
    <SportInjuries :config="wcbbInjuriesConfig" />
</template>
