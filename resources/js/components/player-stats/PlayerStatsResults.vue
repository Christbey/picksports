<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { usePlayerStatsContext } from '@/composables/usePlayerStats';
import {
    formatColumnValue,
    rankColorClass,
} from '@/lib/playerStatsPresentation';
const {
    config,
    loading,
    searchQuery,
    sortBy,
    visibleLimit,
    sortedPlayers,
    visiblePlayers,
    statColumns,
} = usePlayerStatsContext();
</script>

<template>
    <div v-if="loading" class="space-y-2">
        <Skeleton v-for="i in 10" :key="i" class="h-12 w-full" />
    </div>

    <Card v-else>
        <CardHeader>
            <div class="ui-kicker">Standings</div>
            <CardTitle class="tracking-tight">Player Rankings</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="ui-table-wrap">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/35 text-left">
                            <th class="p-2 font-medium">#</th>
                            <th class="p-2 font-medium">Player</th>
                            <th class="p-2 text-right font-medium">Team</th>
                            <th class="p-2 text-right font-medium">GP</th>
                            <th
                                v-for="column in statColumns"
                                :key="column.key as string"
                                class="p-2 text-right font-medium"
                                :class="column.cellClass?.replace('p-2', '')"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(entry, index) in visiblePlayers"
                            :key="entry.player_id"
                            class="border-b transition-colors odd:bg-muted/15 hover:bg-muted/40"
                        >
                            <td class="p-2 text-muted-foreground">
                                <span
                                    class="font-medium"
                                    :class="
                                        rankColorClass(
                                            index,
                                            sortedPlayers.length,
                                        )
                                    "
                                >
                                    {{ index + 1 }}
                                </span>
                            </td>
                            <td class="p-2 font-medium">
                                <Link
                                    v-if="entry.player && config.playerLink"
                                    :href="config.playerLink(entry.player.id)"
                                    class="flex items-center gap-2 transition-colors hover:text-primary"
                                >
                                    <img
                                        v-if="entry.player.headshot_url"
                                        :src="entry.player.headshot_url"
                                        :alt="entry.player.full_name"
                                        loading="lazy"
                                        decoding="async"
                                        class="h-8 w-8 rounded-full object-cover"
                                    />
                                    <div
                                        v-else
                                        class="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-xs font-bold text-muted-foreground"
                                    >
                                        {{ entry.player.full_name?.charAt(0) }}
                                    </div>
                                    <span>{{ entry.player.full_name }}</span>
                                    <span
                                        v-if="entry.player.position"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ entry.player.position }}
                                    </span>
                                </Link>
                                <div
                                    v-else-if="entry.player"
                                    class="flex items-center gap-2"
                                >
                                    <img
                                        v-if="entry.player.headshot_url"
                                        :src="entry.player.headshot_url"
                                        :alt="entry.player.full_name"
                                        loading="lazy"
                                        decoding="async"
                                        class="h-8 w-8 rounded-full object-cover"
                                    />
                                    <div
                                        v-else
                                        class="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-xs font-bold text-muted-foreground"
                                    >
                                        {{ entry.player.full_name?.charAt(0) }}
                                    </div>
                                    <span>{{ entry.player.full_name }}</span>
                                    <span
                                        v-if="entry.player.position"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ entry.player.position }}
                                    </span>
                                </div>
                            </td>
                            <td class="p-2 text-right text-muted-foreground">
                                <Link
                                    v-if="
                                        entry.player?.team?.id != null &&
                                        config.teamLink
                                    "
                                    :href="
                                        config.teamLink(entry.player.team.id)
                                    "
                                    class="transition-colors hover:text-primary"
                                >
                                    {{ entry.player.team.abbreviation }}
                                </Link>
                                <span v-else>{{
                                    entry.player?.team?.abbreviation ?? '-'
                                }}</span>
                            </td>
                            <td class="p-2 text-right text-muted-foreground">
                                {{ entry.games_played }}
                            </td>
                            <td
                                v-for="column in statColumns"
                                :key="column.key as string"
                                class="p-2 text-right"
                                :class="[
                                    column.cellClass ?? '',
                                    column.key === sortBy
                                        ? rankColorClass(
                                              index,
                                              sortedPlayers.length,
                                          )
                                        : '',
                                ]"
                            >
                                {{ formatColumnValue(entry, column) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="sortedPlayers.length === 0 && !loading"
                class="py-8 text-center text-muted-foreground"
            >
                <p v-if="searchQuery">
                    No players found matching "{{ searchQuery }}"
                </p>
                <p v-else>No player stats available.</p>
            </div>

            <div
                v-if="visiblePlayers.length < sortedPlayers.length"
                class="flex justify-center pt-4"
            >
                <Button variant="outline" @click="visibleLimit += 100">
                    Show more players
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
