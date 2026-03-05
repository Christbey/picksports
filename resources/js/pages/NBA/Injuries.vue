<script setup lang="ts">
import SportInjuries from '@/components/SportInjuries.vue';
import { nbaInjuriesConfig } from '@/config/sport-injuries-configs';
</script>

<template>
    <SportInjuries :config="nbaInjuriesConfig" />
</template>
