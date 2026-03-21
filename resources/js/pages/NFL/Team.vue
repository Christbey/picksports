<script setup lang="ts">
import NFLGameController from '@/actions/App/Http/Controllers/NFL/GameController';
import SportTeam from '@/components/SportTeam.vue';
import { createNflTeamConfig } from '@/config/team-page-configs';

const props = defineProps<{ teamId: number }>();

const config = createNflTeamConfig((id) => NFLGameController(id));
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
