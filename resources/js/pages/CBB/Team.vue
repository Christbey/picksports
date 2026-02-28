<script setup lang="ts">
import CBBGameController from '@/actions/App/Http/Controllers/CBB/GameController'
import SportTeam from '@/components/SportTeam.vue'
import { createCbbTeamConfig } from '@/config/team-page-configs'

const props = defineProps<{ teamId: number }>()

const config = createCbbTeamConfig((id) => CBBGameController(id))
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
