<script setup lang="ts">
import SportTeam from '@/components/SportTeam.vue';
import { createNflTeamConfig } from '@/config/team-page-configs';
import NFLGameController from '@/actions/App/Http/Controllers/NFL/GameController';

const props = defineProps<{ teamId: number }>();

const config = createNflTeamConfig((id) => NFLGameController(id));
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
