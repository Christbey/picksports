<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

import { useCbbForecastContext } from '@/composables/useCbbForecast';
const {
    selectedSeason,
    forecastMode,
    snapshotAsOf,
    availableSeasonOptions,
    actualFieldKnown,
} = useCbbForecastContext();
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
                <div
                    class="min-w-0 text-sm text-muted-foreground sm:min-w-[260px]"
                >
                    <p class="font-medium text-foreground">
                        Actual tournament field
                    </p>
                    <p v-if="actualFieldKnown" class="mt-1">
                        This page now centers on the confirmed field and uses
                        {{
                            forecastMode === 'live_snapshot'
                                ? 'the latest live tournament snapshot'
                                : 'the baseline forecast model'
                        }}
                        for title and deep-run outlook.
                    </p>
                    <p v-else class="mt-1">
                        Official field data is not available yet for this
                        season.
                    </p>
                    <p v-if="snapshotAsOf" class="mt-1 text-xs">
                        Live snapshot as of
                        {{ new Date(snapshotAsOf).toLocaleString() }}
                    </p>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
