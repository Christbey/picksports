<script setup lang="ts">
import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController';
import SportTeam from '@/components/SportTeam.vue';
import { createNbaTeamConfig } from '@/config/team-page-configs';

const props = defineProps<{ teamId: number }>();

const config = createNbaTeamConfig((id) => NBAGameController(id));
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
