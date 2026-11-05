<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useTeamMappingsContext } from '@/composables/useTeamMappings';
const { isPlayer, changeProvider, currentProvider, providers } =
    useTeamMappingsContext();
</script>
<template>
    <div v-if="!isPlayer && providers.length > 1">
        <div class="mb-2 text-sm font-medium">Provider</div>
        <div class="flex flex-wrap gap-2">
            <Button
                v-for="provider in providers"
                :key="provider.key"
                :variant="
                    provider.key === currentProvider ? 'default' : 'outline'
                "
                size="sm"
                @click="changeProvider(provider.key)"
            >
                {{ provider.label }}
            </Button>
        </div>
    </div>
</template>
