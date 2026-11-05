<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useTeamMappingsContext } from '@/composables/useTeamMappings';
const { mappings, changePage } = useTeamMappingsContext();
</script>
<template>
    <div
        v-if="mappings.last_page > 1"
        class="flex items-center justify-between"
    >
        <div class="text-sm text-muted-foreground">
            Page {{ mappings.current_page }} of
            {{ mappings.last_page }}
        </div>
        <div class="flex gap-2">
            <Button
                :disabled="mappings.current_page === 1"
                variant="outline"
                size="sm"
                @click="changePage(mappings.current_page - 1)"
            >
                Previous
            </Button>
            <Button
                :disabled="mappings.current_page === mappings.last_page"
                variant="outline"
                size="sm"
                @click="changePage(mappings.current_page + 1)"
            >
                Next
            </Button>
        </div>
    </div>
</template>
