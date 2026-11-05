<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useTeamMappingsContext } from '@/composables/useTeamMappings';
const { changeFilter, currentFilter, stats } = useTeamMappingsContext();
</script>
<template>
    <div>
        <div class="mb-2 text-sm font-medium">Filter</div>
        <div class="flex flex-wrap gap-2">
            <Button
                :variant="currentFilter === 'all' ? 'default' : 'outline'"
                size="sm"
                @click="changeFilter('all')"
            >
                All ({{ stats.total }})
            </Button>
            <Button
                :variant="currentFilter === 'mapped' ? 'default' : 'outline'"
                size="sm"
                @click="changeFilter('mapped')"
            >
                Mapped ({{ stats.mapped }})
            </Button>
            <Button
                :variant="currentFilter === 'unmapped' ? 'default' : 'outline'"
                size="sm"
                @click="changeFilter('unmapped')"
            >
                Unmapped ({{ stats.unmapped }})
            </Button>
        </div>
    </div>
</template>
