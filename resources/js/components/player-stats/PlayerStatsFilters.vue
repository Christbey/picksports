<script setup lang="ts">
import SeasonSelect from '@/components/SeasonSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePlayerStatsContext } from '@/composables/usePlayerStats';
const {
    config,
    searchQuery,
    selectedSeasonType,
    sortBy,
    sortDesc,
    selectedCategory,
    availableSeasons,
    selectedSeason,
    categoryOptions,
    sortOptions,
    toggleSort,
} = usePlayerStatsContext();
</script>

<template>
    <Card>
        <CardContent class="pt-6">
            <div class="flex flex-wrap items-end gap-4">
                <div class="space-y-2">
                    <p class="ui-kicker">Season</p>
                    <SeasonSelect
                        id="player-stats-season"
                        v-model="selectedSeason"
                        :options="availableSeasons"
                    />
                </div>
                <div v-if="config.seasonTypeOptions?.length" class="space-y-2">
                    <p class="ui-kicker">Season Type</p>
                    <select
                        id="player-stats-season-type"
                        v-model="selectedSeasonType"
                        class="flex h-10 min-w-[180px] rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none"
                    >
                        <option value="">All</option>
                        <option
                            v-for="option in config.seasonTypeOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div class="min-w-[200px] flex-1">
                    <Input
                        v-model="searchQuery"
                        placeholder="Search by player or team name..."
                        class="w-full"
                    />
                </div>
                <div
                    v-if="categoryOptions.length > 0"
                    class="flex flex-wrap gap-2"
                >
                    <Button
                        v-for="category in categoryOptions"
                        :key="category.key"
                        :variant="
                            selectedCategory === category.key
                                ? 'default'
                                : 'outline'
                        "
                        size="sm"
                        class="h-9"
                        @click="selectedCategory = category.key"
                    >
                        {{ category.label }}
                    </Button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="option in sortOptions"
                        :key="option.key"
                        :variant="sortBy === option.key ? 'default' : 'outline'"
                        size="sm"
                        class="h-9"
                        @click="toggleSort(option.key)"
                    >
                        {{ option.label }}
                        {{
                            sortBy === option.key ? (sortDesc ? '↓' : '↑') : ''
                        }}
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
