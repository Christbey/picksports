<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useNflForecastContext } from '@/composables/useNflForecast';

const { projectedBracket, formatPct } = useNflForecastContext();

const berthGroups = [
    {
        label: 'Division winners',
        rows: 'divisionWinners',
        probability: 'division_winner_probability',
    },
    {
        label: 'Wild cards',
        rows: 'wildCards',
        probability: 'wild_card_probability',
    },
] as const;
</script>

<template>
    <Card v-if="projectedBracket.length">
        <CardHeader>
            <CardTitle>Projected Playoff Field</CardTitle>
            <p class="text-sm text-muted-foreground">
                Most common combination of division winners and wild cards in
                the simulations, shown in its most common seed order.
            </p>
        </CardHeader>
        <CardContent>
            <div class="grid gap-6 lg:grid-cols-2">
                <table
                    v-for="field in projectedBracket"
                    :key="field.conference"
                    class="w-full text-sm"
                >
                    <caption class="pb-2 text-left text-base font-semibold">
                        {{
                            field.conference
                        }}
                    </caption>
                    <thead
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Seed
                            </th>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Team
                            </th>
                            <th scope="col" class="py-2 text-right font-medium">
                                Berth chance
                            </th>
                        </tr>
                    </thead>
                    <tbody v-for="group in berthGroups" :key="group.rows">
                        <tr>
                            <th
                                scope="rowgroup"
                                colspan="3"
                                class="pt-3 pb-1 text-left text-xs font-semibold"
                            >
                                {{ group.label }}
                            </th>
                        </tr>
                        <tr
                            v-for="{ seed, team } in field[group.rows]"
                            :key="seed"
                            class="border-b border-border/60"
                            :class="{
                                'last:border-0': group.rows === 'wildCards',
                            }"
                        >
                            <td class="py-2 pr-3 tabular-nums">{{ seed }}</td>
                            <th
                                scope="row"
                                class="py-2 pr-3 text-left font-medium"
                            >
                                {{ team.team_name }}
                                <span
                                    v-if="
                                        group.rows === 'divisionWinners' &&
                                        team.division
                                    "
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ team.division }}</span
                                >
                            </th>
                            <td class="py-2 text-right tabular-nums">
                                {{ formatPct(team[group.probability]) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
