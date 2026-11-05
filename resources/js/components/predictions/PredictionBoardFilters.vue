<script setup lang="ts">
import SeasonSelect from '@/components/SeasonSelect.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePredictionBoardContext } from '@/composables/usePredictionBoard';
const {
    filterMode,
    seasonType,
    showFilters,
    availableSeasons,
    selectedSeason,
    applyFilters,
    clearFilters,
    loading,
} = usePredictionBoardContext();
</script>

<template>
    <div
        v-if="showFilters"
        id="board-filters"
        class="flex flex-wrap items-end gap-4 rounded-lg bg-muted/40 p-4"
    >
        <SeasonSelect
            id="predictions-season"
            v-model="selectedSeason"
            :options="availableSeasons"
            :disabled="loading"
        />
        <div v-if="filterMode === 'seasonWeek'">
            <Label for="season-type">Season type</Label>
            <select
                id="season-type"
                v-model="seasonType"
                :disabled="loading"
                class="mt-1 block h-10 rounded-md border border-input bg-background px-3 text-sm"
                @change="applyFilters"
            >
                <option value="">All season types</option>
                <option value="Regular Season">Regular season</option>
                <option value="Postseason">Postseason</option>
            </select>
        </div>
        <Button variant="ghost" :disabled="loading" @click="clearFilters"
            >Reset</Button
        >
    </div>
</template>
