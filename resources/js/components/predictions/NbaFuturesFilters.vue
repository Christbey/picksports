<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { useNbaFuturesContext } from '@/composables/useNbaFutures';
const { selectedSeason, sortMode, onlyPlusEv, availableSeasonOptions } =
    useNbaFuturesContext();
</script>
<template>
    <Card>
        <CardContent class="pt-6">
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-full min-w-0 sm:w-auto sm:min-w-[220px]">
                    <label for="season" class="ui-kicker">Season</label>
                    <select
                        id="season"
                        v-model.number="selectedSeason"
                        class="ui-select mt-1 min-h-11 sm:min-h-0"
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
                    <label for="sort-mode" class="ui-kicker">Sort</label>
                    <select
                        id="sort-mode"
                        v-model="sortMode"
                        class="ui-select mt-1 min-h-11 sm:min-h-0"
                    >
                        <option value="model">Model Probability</option>
                        <option value="edge">Best Edge</option>
                    </select>
                </div>
                <label
                    class="mt-0 inline-flex min-h-11 items-center gap-2 text-sm font-medium text-muted-foreground sm:mt-5 sm:min-h-0"
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
