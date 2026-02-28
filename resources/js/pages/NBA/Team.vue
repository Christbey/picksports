<script setup lang="ts">
import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController'
import SportTeam from '@/components/SportTeam.vue'
import { createNbaTeamConfig } from '@/config/team-page-configs'
import { type Team } from '@/types'

const props = defineProps<{ team: Team }>()

const config = createNbaTeamConfig((id) => NBAGameController(id))
</script>

<template>
    <SportTeam :config="config" :team="props.team" />
</template>
