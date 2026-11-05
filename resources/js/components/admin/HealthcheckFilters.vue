<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const { selectedSport, filterBySport, formatSportName, sports } =
    useHealthchecksContext();
</script>
<template>
    <div class="flex gap-4">
        <select
            v-model="selectedSport"
            @change="filterBySport"
            class="rounded-lg border border-sidebar-border bg-white px-4 py-2 focus:ring-2 focus:ring-primary focus:outline-none dark:bg-sidebar"
        >
            <option value="all">All Sports</option>
            <option v-for="sport in sports" :key="sport" :value="sport">
                {{ formatSportName(sport) }}
            </option>
        </select>
    </div>
</template>
