<script setup lang="ts">
import WNBAGameController from '@/actions/App/Http/Controllers/WNBA/GameController'
import SportTeam from '@/components/SportTeam.vue'
import { createWnbaTeamConfig } from '@/config/team-page-configs'

const props = defineProps<{ teamId: number }>()

const config = createWnbaTeamConfig((id) => WNBAGameController(id))
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
