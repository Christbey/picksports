<script setup lang="ts">
import SportInjuries from '@/components/SportInjuries.vue';
import { wnbaInjuriesConfig } from '@/config/sport-injuries-configs';
</script>

<template>
    <SportInjuries :config="wnbaInjuriesConfig" />
</template>
