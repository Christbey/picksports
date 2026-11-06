<script setup lang="ts">
import { X } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';

import { usePlayerPropsBoardContext } from '@/composables/usePlayerPropsBoardContext';
const board = usePlayerPropsBoardContext();
defineProps<{ sport: string }>();
</script>

<template>
    <section class="space-y-6">
        <Card>
            <CardContent class="pt-6">
                <div class="flex min-w-0 flex-wrap items-end gap-4">
                    <div
                        class="w-full min-w-0 space-y-2 sm:min-w-[200px] sm:flex-1"
                    >
                        <Label for="date">Game Date</Label>
                        <select
                            id="date"
                            v-model="board.selectedDate"
                            @change="board.onDateChange"
                            class="ui-select"
                        >
                            <option value="">All dates</option>
                            <option
                                v-for="date in board.dates"
                                :key="date.value"
                                :value="date.value"
                            >
                                {{ date.label }}
                            </option>
                        </select>
                    </div>

                    <div
                        class="w-full min-w-0 space-y-2 sm:min-w-[200px] sm:flex-1"
                    >
                        <Label for="game">Matchup</Label>
                        <select
                            id="game"
                            v-model="board.selectedGame"
                            @change="board.onGameChange"
                            class="ui-select"
                            :disabled="board.filteredGames.length === 0"
                        >
                            <option value="">All games</option>
                            <option
                                v-for="game in board.filteredGames"
                                :key="game.id"
                                :value="game.id.toString()"
                            >
                                {{ game.label }}
                            </option>
                        </select>
                    </div>

                    <div
                        class="w-full min-w-0 space-y-2 sm:min-w-[180px] sm:flex-1"
                    >
                        <Label for="market">Prop Type</Label>
                        <select
                            id="market"
                            v-model="board.selectedMarket"
                            @change="board.onMarketChange"
                            class="ui-select"
                        >
                            <option value="">All props</option>
                            <option
                                v-for="market in board.markets"
                                :key="market.value"
                                :value="market.value"
                            >
                                {{ market.label }}
                            </option>
                        </select>
                    </div>

                    <div class="flex gap-2">
                        <Button
                            v-if="board.hasActiveFilters"
                            variant="outline"
                            @click="board.clearFilters"
                        >
                            <X class="mr-2 h-4 w-4" />
                            Clear
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="sport === 'NFL' && board.selectedView === 'recommendations'"
            class="flex flex-wrap items-end justify-between gap-3"
        >
            <div class="w-full sm:max-w-xs">
                <Label for="prop-player-search">Find a player or team</Label>
                <Input
                    id="prop-player-search"
                    v-model="board.playerSearch"
                    type="search"
                    placeholder="Search players or teams"
                    class="mt-2 w-full"
                    @input="board.visibleLimit = 18"
                />
            </div>
            <p class="text-sm text-muted-foreground" role="status">
                {{
                    board.loading
                        ? 'Updating board…'
                        : `${board.searchedRecommendations.length} props`
                }}
            </p>
        </div>
        <p
            v-if="sport === 'NFL' && board.selectedView === 'recommendations'"
            class="text-xs text-muted-foreground"
        >
            Fresh quotes and samples of at least 4 current-season games come
            first, then estimated hit chance. Higher probability does not
            necessarily mean better value.
        </p>
        <div
            v-if="
                sport === 'NFL' &&
                board.selectedView === 'recommendations' &&
                board.commonMarkets.length
            "
            class="flex flex-wrap gap-2"
            aria-label="Common prop markets"
        >
            <Button
                v-for="market in board.commonMarkets"
                :key="market.value"
                size="sm"
                :variant="
                    board.selectedMarket === market.value
                        ? 'default'
                        : 'outline'
                "
                :aria-pressed="board.selectedMarket === market.value"
                @click="
                    board.selectedMarket =
                        board.selectedMarket === market.value
                            ? ''
                            : market.value;
                    board.onMarketChange();
                "
            >
                {{ market.label }}
            </Button>
        </div>
    </section>
</template>
