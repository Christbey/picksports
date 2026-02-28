<script setup lang="ts">
import WCBBGameController from '@/actions/App/Http/Controllers/WCBB/GameController'
import SportTeam from '@/components/SportTeam.vue'
import { createWcbbTeamConfig } from '@/config/team-page-configs'

const props = defineProps<{ teamId: number }>()

const config = createWcbbTeamConfig((id) => WCBBGameController(id))
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
