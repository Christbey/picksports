<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

import { useNflForecastContext } from '@/composables/useNflForecast';
const { selectedSeason, sortMode, onlyPlusEv, simulations, seasonOptions } =
    useNflForecastContext();
</script>
<template>
    <Card>
        <CardContent class="pt-6">
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-[180px]">
                    <label for="season" class="ui-kicker">Season</label>
                    <select
                        id="season"
                        v-model.number="selectedSeason"
                        class="ui-select mt-1 min-h-11 sm:min-h-0"
                    >
                        <option
                            v-for="season in seasonOptions"
                            :key="season"
                            :value="season"
                        >
                            {{ season }}
                        </option>
                    </select>
                </div>
                <div class="w-full min-w-0 sm:w-auto sm:min-w-[220px]">
                    <label for="sort-mode" class="ui-kicker">Sort</label>
                    <select
                        id="sort-mode"
                        v-model="sortMode"
                        class="ui-select mt-1 min-h-11 sm:min-h-0"
                    >
                        <option value="title">Super Bowl chance</option>
                        <option value="playoffs">Playoff chance</option>
                        <option value="wins">Projected wins</option>
                        <option value="edge">Largest model difference</option>
                    </select>
                </div>
                <label
                    class="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <input
                        v-model="onlyPlusEv"
                        type="checkbox"
                        class="h-4 w-4 rounded border-input"
                    />
                    Positive model differences
                </label>
                <p
                    v-if="simulations"
                    class="ml-auto pb-2 text-xs text-muted-foreground"
                >
                    {{ simulations.toLocaleString() }} simulations
                </p>
            </div>
        </CardContent>
    </Card>
</template>
