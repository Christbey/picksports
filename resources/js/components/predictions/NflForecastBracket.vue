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

const outsideGroups = [
    { label: 'In the hunt', rows: 'inTheHunt' },
    { label: 'Virtually eliminated', rows: 'virtuallyEliminated' },
    { label: 'Eliminated', rows: 'eliminated' },
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
            <p
                v-if="
                    projectedBracket.some((field) =>
                        outsideGroups.some(({ rows }) => field[rows].length),
                    )
                "
                class="text-xs text-muted-foreground"
            >
                Virtually eliminated teams have a simulated playoff chance below
                1%. Mathematical elimination uses the remaining schedule and
                standings, allowing for favorable tiebreakers.
            </p>
        </CardHeader>
        <CardContent>
            <div class="grid gap-6 lg:grid-cols-2">
                <div v-for="field in projectedBracket" :key="field.conference">
                    <table class="w-full text-sm">
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
                                <th
                                    scope="col"
                                    class="py-2 text-right font-medium"
                                >
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
                                <td class="py-2 pr-3 tabular-nums">
                                    {{ seed }}
                                </td>
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
                    <section
                        v-for="group in outsideGroups.filter(
                            ({ rows }) => field[rows].length,
                        )"
                        :key="group.rows"
                        class="mt-4"
                    >
                        <h3 class="border-b pb-1 text-xs font-semibold">
                            {{ group.label }}
                        </h3>
                        <ul class="divide-y divide-border/60 text-sm">
                            <li
                                v-for="team in field[group.rows]"
                                :key="team.team_id"
                                class="flex justify-between gap-3 py-2"
                            >
                                <span>{{ team.team_name }}</span>
                                <span
                                    v-if="group.rows !== 'eliminated'"
                                    class="tabular-nums"
                                >
                                    {{
                                        formatPct(
                                            team.make_playoffs_probability,
                                            group.rows === 'virtuallyEliminated'
                                                ? 2
                                                : 1,
                                        )
                                    }}
                                    <span class="sr-only"
                                        >simulated playoff chance</span
                                    >
                                </span>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
