<script setup lang="ts">
import SportTeam from '@/components/SportTeam.vue';
import { createWcbbTeamConfig } from '@/config/team-page-configs';
import WCBBGameController from '@/actions/App/Http/Controllers/WCBB/GameController';

const props = defineProps<{ teamId: number }>();

const config = createWcbbTeamConfig((id) => WCBBGameController(id));
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
