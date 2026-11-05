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
                <div class="min-w-[220px]">
                    <p class="ui-kicker">Season</p>
                    <select
                        id="season"
                        v-model.number="selectedSeason"
                        class="ui-select mt-1"
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
                <div class="min-w-[220px]">
                    <p class="ui-kicker">Sort</p>
                    <select
                        id="sort-mode"
                        v-model="sortMode"
                        class="ui-select mt-1"
                    >
                        <option value="model">Model Probability</option>
                        <option value="edge">Best Edge</option>
                    </select>
                </div>
                <label
                    class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-muted-foreground"
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
