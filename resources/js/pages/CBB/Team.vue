<script setup lang="ts">
import SportTeam from '@/components/SportTeam.vue';
import { createCbbTeamConfig } from '@/config/team-page-configs';
import CBBGameController from '@/actions/App/Http/Controllers/CBB/GameController';

const props = defineProps<{ teamId: number }>();

const config = createCbbTeamConfig((id) => CBBGameController(id));
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
