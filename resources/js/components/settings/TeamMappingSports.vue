<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useTeamMappingsContext } from '@/composables/useTeamMappings';
const { changeSport, currentSport, sports } = useTeamMappingsContext();
</script>
<template>
    <div>
        <div class="mb-2 text-sm font-medium">Sport</div>
        <div class="flex flex-wrap gap-2">
            <Button
                v-for="sport in sports"
                :key="sport.key"
                :variant="sport.key === currentSport ? 'default' : 'outline'"
                size="sm"
                @click="changeSport(sport.key)"
            >
                {{ sport.label }}
            </Button>
        </div>
    </div>
</template>
