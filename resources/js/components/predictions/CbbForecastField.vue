<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { useCbbForecastContext } from '@/composables/useCbbForecast';
const { formatPct, formatTeam, regionalField, firstFourTeams } =
    useCbbForecastContext();
</script>
<template>
    <Card>
        <CardHeader>
            <CardTitle>Confirmed Tournament Field</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
            <div class="grid gap-4 lg:grid-cols-2">
                <Card
                    v-for="group in regionalField"
                    :key="group.region"
                    class="border-border/70"
                >
                    <CardHeader class="pb-3">
                        <CardTitle class="text-base">{{
                            group.region
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="group.teams.length > 0" class="space-y-2">
                            <div
                                v-for="row in group.teams"
                                :key="`field-${group.region}-${row.team_id}`"
                                class="flex items-center justify-between gap-3 rounded-lg border border-border/60 bg-muted/20 px-3 py-2"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <img
                                        v-if="row.team?.logo"
                                        :src="row.team.logo"
                                        :alt="formatTeam(row.team, row.team_id)"
                                        class="h-8 w-8 rounded object-contain"
                                    />
                                    <div class="min-w-0">
                                        <p
                                            class="truncate text-sm font-semibold"
                                        >
                                            {{
                                                formatTeam(
                                                    row.team,
                                                    row.team_id,
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                row.team?.conference ??
                                                'Independent'
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">
                                        No.
                                        {{ row.actual_seed ?? '-' }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            formatPct(
                                                row.champion_probability,
                                                2,
                                            )
                                        }}
                                        title
                                    </p>
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">
                            No confirmed teams loaded for this region yet.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card v-if="firstFourTeams.length > 0" class="border-border/70">
                <CardHeader class="pb-3">
                    <CardTitle class="text-base">First Four</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 md:grid-cols-2">
                        <div
                            v-for="row in firstFourTeams"
                            :key="`first-four-${row.team_id}`"
                            class="flex items-center justify-between gap-3 rounded-lg border border-border/60 bg-muted/20 px-3 py-2"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <img
                                    v-if="row.team?.logo"
                                    :src="row.team.logo"
                                    :alt="formatTeam(row.team, row.team_id)"
                                    class="h-8 w-8 rounded object-contain"
                                />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">
                                        {{ formatTeam(row.team, row.team_id) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ row.actual_region ?? 'Region TBD' }}
                                        · No.
                                        {{ row.actual_seed ?? '-' }}
                                    </p>
                                </div>
                            </div>
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{
                                    formatPct(
                                        row.tournament_make_probability,
                                        1,
                                    )
                                }}
                                in
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </CardContent>
    </Card>
</template>
