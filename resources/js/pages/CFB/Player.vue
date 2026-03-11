<script setup lang="ts">
import SportPlayerShell from '@/components/player-page/SportPlayerShell.vue';
import { cfbPlayerPageConfig } from '@/config/player-page-configs';

defineProps<{ playerId: number }>();
</script>

<template>
    <SportPlayerShell :config="cfbPlayerPageConfig" :player-id="playerId" />
</template>
