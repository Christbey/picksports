<script setup lang="ts">
import SportTeam from '@/components/SportTeam.vue';
import { createWnbaTeamConfig } from '@/config/team-page-configs';
import WNBAGameController from '@/actions/App/Http/Controllers/WNBA/GameController';

const props = defineProps<{ teamId: number }>();

const config = createWnbaTeamConfig((id) => WNBAGameController(id));
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
