<script setup lang="ts">
import SportInjuries from '@/components/SportInjuries.vue';
import { cfbInjuriesConfig } from '@/config/sport-injuries-configs';
</script>

<template>
    <SportInjuries :config="cfbInjuriesConfig" />
</template>
