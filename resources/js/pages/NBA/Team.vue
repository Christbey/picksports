<script setup lang="ts">
import SportTeam from '@/components/SportTeam.vue';
import { createNbaTeamConfig } from '@/config/team-page-configs';
import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController';

const props = defineProps<{ teamId: number }>();

const config = createNbaTeamConfig((id) => NBAGameController(id));
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
