<script setup lang="ts">
import NFLGameController from '@/actions/App/Http/Controllers/NFL/GameController'
import SportTeam from '@/components/SportTeam.vue'
import { createNflTeamConfig } from '@/config/team-page-configs'
import { type Team } from '@/types'

const props = defineProps<{ team: Team }>()

const config = createNflTeamConfig((id) => NFLGameController(id))
</script>

<template>
    <SportTeam :config="config" :team="props.team" />
</template>
