<script setup lang="ts">
import SportTeam from '@/components/SportTeam.vue';
import { createMlbTeamConfig } from '@/config/team-page-configs';
import MLBGameController from '@/actions/App/Http/Controllers/MLB/GameController';

const props = defineProps<{
    teamId: number;
}>();

const config = createMlbTeamConfig((id) => MLBGameController(id));
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
