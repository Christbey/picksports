<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

import { useWcbbForecastContext } from '@/composables/useWcbbForecast';
const { selectedSeason, sortMode, onlyPlusEv, availableSeasonOptions } =
    useWcbbForecastContext();
</script>
<template>
    <Card>
        <CardContent class="pt-6">
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-full min-w-0 sm:w-auto sm:min-w-[220px]">
                    <label for="season" class="text-sm font-medium"
                        >Season</label
                    >
                    <select
                        id="season"
                        v-model.number="selectedSeason"
                        class="mt-1 flex h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none sm:h-10"
                    >
                        <option
                            v-for="season in availableSeasonOptions"
                            :key="season"
                            :value="season"
                        >
                            {{ season }}
                        </option>
                    </select>
                </div>
                <div class="w-full min-w-0 sm:w-auto sm:min-w-[220px]">
                    <label for="sort-mode" class="text-sm font-medium"
                        >Sort</label
                    >
                    <select
                        id="sort-mode"
                        v-model="sortMode"
                        class="mt-1 flex h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none sm:h-10"
                    >
                        <option value="model">Model Probability</option>
                        <option value="edge">Best Edge</option>
                    </select>
                </div>
                <label
                    class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <input
                        v-model="onlyPlusEv"
                        type="checkbox"
                        class="h-4 w-4 rounded border-input"
                    />
                    Only +EV
                </label>
            </div>
        </CardContent>
    </Card>
</template>
