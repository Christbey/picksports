<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useAdminExportsContext } from '@/composables/useAdminExports';
const {
    activeTab,
    selectedSport,
    selectedDate,
    selectedGame,
    selectedMarket,
    selectedSeason,
    selectedPreset,
    adSafeMode,
    exportingAll,
    presets,
    activeRowsCount,
    filteredGames,
    filteredDates,
    seasonOptions,
    hasActiveFilters,
    applyFilters,
    clearFilters,
    onDateChange,
    exportAllCurrent,
    activeTabMeta,
    props,
} = useAdminExportsContext();
</script>

<template>
    <div
        class="rounded-xl border border-sidebar-border bg-background p-4 shadow-sm dark:bg-sidebar"
    >
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="space-y-2">
                <Label for="sport">Sport</Label>
                <select
                    id="sport"
                    v-model="selectedSport"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    @change="applyFilters"
                >
                    <option value="NBA">NBA</option>
                    <option value="NFL">NFL</option>
                    <option value="MLB">MLB</option>
                    <option value="CBB">CBB</option>
                </select>
            </div>

            <div
                v-if="activeTab === 'props' || activeTab === 'predictions'"
                class="space-y-2"
            >
                <Label for="date">Date</Label>
                <select
                    id="date"
                    v-model="selectedDate"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    @change="onDateChange"
                >
                    <option value="">All dates</option>
                    <option
                        v-for="date in filteredDates"
                        :key="date.value"
                        :value="date.value"
                    >
                        {{ date.label }}
                    </option>
                </select>
            </div>

            <div
                v-if="activeTab === 'props' || activeTab === 'predictions'"
                class="space-y-2"
            >
                <Label for="game">Game</Label>
                <select
                    id="game"
                    v-model="selectedGame"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All games</option>
                    <option
                        v-for="game in filteredGames"
                        :key="game.id"
                        :value="game.id.toString()"
                    >
                        {{ game.label }}
                    </option>
                </select>
            </div>

            <div
                v-if="activeTab === 'futures' || activeTab === 'tournament'"
                class="space-y-2"
            >
                <Label for="season">Season</Label>
                <select
                    id="season"
                    v-model="selectedSeason"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    @change="applyFilters"
                >
                    <option value="">Latest season</option>
                    <option
                        v-for="season in seasonOptions"
                        :key="season.value"
                        :value="season.value"
                    >
                        {{ season.label }}
                    </option>
                </select>
            </div>

            <div v-if="activeTab === 'props'" class="space-y-2">
                <Label for="market">Market</Label>
                <select
                    id="market"
                    v-model="selectedMarket"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All markets</option>
                    <option
                        v-for="market in props.markets"
                        :key="market.value"
                        :value="market.value"
                    >
                        {{ market.label }}
                    </option>
                </select>
            </div>

            <div class="space-y-2">
                <Label for="preset">Export Size</Label>
                <select
                    id="preset"
                    v-model="selectedPreset"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                >
                    <option
                        v-for="preset in presets"
                        :key="preset.id"
                        :value="preset.id"
                    >
                        {{ preset.label }}
                    </option>
                </select>
            </div>

            <div class="space-y-2">
                <Label for="ad-safe">Ad-safe Preset</Label>
                <label
                    class="flex h-9 items-center gap-2 rounded-md border border-input px-3 text-sm"
                >
                    <input
                        id="ad-safe"
                        v-model="adSafeMode"
                        type="checkbox"
                        class="h-4 w-4 rounded border-input"
                    />
                    <span>{{ adSafeMode ? 'On' : 'Off' }}</span>
                </label>
            </div>
        </div>
        <div
            class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4"
        >
            <p class="text-xs text-muted-foreground">
                {{ activeTabMeta.label }} • {{ activeRowsCount }} rows loaded
            </p>
            <div class="flex items-center gap-2">
                <Button
                    v-if="hasActiveFilters"
                    variant="outline"
                    size="sm"
                    @click="clearFilters"
                    >Clear Filters</Button
                >
                <Button
                    :disabled="activeRowsCount === 0 || exportingAll"
                    size="sm"
                    @click="exportAllCurrent"
                >
                    {{ exportingAll ? 'Exporting...' : 'Export All Shown' }}
                </Button>
            </div>
        </div>
    </div>
</template>
