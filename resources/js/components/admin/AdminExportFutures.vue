<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useAdminExportsContext } from '@/composables/useAdminExports';
const {
    signalForFutures,
    getConfidenceColor,
    getSignalBand,
    formatStatNumber,
    buildFuturesRows,
    exportFuturesRow,
    props,
} = useAdminExportsContext();
</script>

<template>
    <div
        v-if="props.futures.length === 0"
        class="rounded-xl border border-sidebar-border bg-background p-10 text-center text-muted-foreground dark:bg-sidebar"
    >
        No futures forecasts found for the selected sport/season.
    </div>

    <div
        v-else
        class="overflow-x-auto rounded-xl border border-sidebar-border bg-background dark:bg-sidebar"
    >
        <table class="w-full min-w-[1120px] table-auto">
            <thead class="sticky top-0 z-10 bg-muted">
                <tr>
                    <th
                        class="w-[200px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Team
                    </th>
                    <th
                        class="w-[140px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Season/Seed
                    </th>
                    <th
                        class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Playoff/Title
                    </th>
                    <th
                        class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Signal
                    </th>
                    <th
                        class="min-w-[360px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Insights
                    </th>
                    <th
                        class="w-[110px] px-4 py-3 text-right text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Export
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, index) in props.futures"
                    :key="row.id"
                    class="border-t border-border align-top hover:bg-muted/40"
                >
                    <td class="px-4 py-4">
                        <p class="text-base font-semibold text-foreground">
                            {{ row.team }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ row.conference_or_league ?? 'N/A' }}
                        </p>
                    </td>
                    <td class="px-4 py-4">
                        <p class="text-sm font-semibold text-foreground">
                            Season {{ row.season }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Seed {{ row.projected_seed ?? 'N/A' }}
                        </p>
                    </td>
                    <td class="px-4 py-4">
                        <p class="text-sm font-semibold text-foreground">
                            Make
                            {{
                                formatStatNumber(row.playoff_make_probability)
                            }}%
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Title
                            {{ formatStatNumber(row.champion_probability) }}%
                        </p>
                    </td>
                    <td class="px-4 py-4">
                        <div class="space-y-1">
                            <div
                                class="flex items-center justify-between text-xs text-muted-foreground"
                            >
                                <span>Signal</span>
                                <span class="font-semibold text-foreground"
                                    >{{ signalForFutures(row) }}%</span
                                >
                            </div>
                            <div
                                class="h-2.5 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full"
                                    :class="
                                        getConfidenceColor(
                                            signalForFutures(row),
                                        )
                                    "
                                    :style="{
                                        width: `${signalForFutures(row)}%`,
                                    }"
                                />
                            </div>
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ getSignalBand(signalForFutures(row)) }}
                            </p>
                        </div>
                    </td>
                    <td class="px-4 py-4">
                        <div class="grid grid-cols-2 gap-1.5">
                            <div
                                v-for="stat in buildFuturesRows(row).slice(
                                    0,
                                    6,
                                )"
                                :key="`${row.id}-${stat.key}`"
                                class="rounded-md border border-border bg-muted/40 px-2 py-1.5"
                            >
                                <p
                                    class="text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ stat.label }}
                                </p>
                                <p
                                    class="truncate text-xs font-semibold text-foreground"
                                >
                                    {{ stat.value }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-4 text-right">
                        <Button size="sm" @click="exportFuturesRow(row, index)"
                            >Export PNG</Button
                        >
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
