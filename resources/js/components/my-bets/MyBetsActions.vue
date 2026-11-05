<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Download, Plus } from 'lucide-vue-next';
import { useMyBetsContext } from '@/composables/useMyBets';
const { showAddBetForm, exportBets } = useMyBetsContext();
</script>
<template>
    <div class="flex items-center justify-between gap-4">
        <Button @click="showAddBetForm = !showAddBetForm" class="gap-2">
            <Plus class="h-4 w-4" />
            Log New Bet
        </Button>
        <Button variant="outline" @click="exportBets" class="gap-2">
            <Download class="h-4 w-4" />
            Export to CSV
        </Button>
    </div>
</template>
