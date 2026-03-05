<script setup lang="ts">
import SportInjuries from '@/components/SportInjuries.vue';
import { cbbInjuriesConfig } from '@/config/sport-injuries-configs';
</script>

<template>
    <SportInjuries :config="cbbInjuriesConfig" />
</template>
