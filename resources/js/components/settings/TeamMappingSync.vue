<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useTeamMappingsContext } from '@/composables/useTeamMappings';
const { isPlayer, isSyncing, syncOddsApiMappings, currentProvider } =
    useTeamMappingsContext();
</script>
<template>
    <div
        v-if="!isPlayer && currentProvider === 'odds'"
        class="flex justify-end"
    >
        <Button :disabled="isSyncing" @click="syncOddsApiMappings">
            {{ isSyncing ? 'Syncing...' : 'Sync Odds API Teams' }}
        </Button>
    </div>
</template>
