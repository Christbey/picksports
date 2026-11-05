<script setup lang="ts">
import type {
    PositionEpaMatchups,
    PositionEpaSample,
} from '@/lib/nflMatchupSignals';

defineProps<{ data: PositionEpaMatchups; teamName: (id: number) => string }>();
const labels: Record<string, string> = {
    pass: 'Passing (sacks included)',
    run: 'Rushing',
    WR: 'WR targets',
    TE: 'TE targets',
    RB: 'RB targets',
};
const number = (value: number | null | undefined) =>
    value == null ? 'Unavailable' : value.toFixed(3);
const sample = (value: PositionEpaSample | null) =>
    value?.eligible
        ? `${number(value.epa_per_play)} · rank ${value.rank ?? 'unavailable'}`
        : 'Insufficient coverage';
</script>

<template>
    <details class="rounded-lg border p-3 text-xs">
        <summary class="min-h-11 cursor-pointer content-center font-semibold">
            {{ data.season }} EPA matchup by position
        </summary>
        <p class="my-3">
            Higher offensive EPA is better. Lower defensive EPA allowed is
            better. Rankings require all 32 qualified teams.
        </p>
        <section
            v-for="direction in data.directions"
            :key="direction.offense_team_id"
            class="my-4 space-y-2"
        >
            <h4 class="font-semibold">
                {{ teamName(direction.offense_team_id) }} offense vs
                {{ teamName(direction.defense_team_id) }} defense
            </h4>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr>
                            <th class="p-2">Play / target</th>
                            <th class="p-2">Offensive EPA</th>
                            <th class="p-2">Defensive EPA allowed</th>
                            <th class="p-2">Previous defenses faced</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="split in direction.splits"
                            :key="split.split"
                            class="border-t align-top"
                        >
                            <th class="p-2 font-medium">
                                {{ labels[split.split] ?? split.split }}
                            </th>
                            <td class="p-2">
                                {{ sample(split.offense) }}<br />{{
                                    split.offense?.plays ?? 0
                                }}
                                measured plays ·
                                {{ split.offense?.games ?? 0 }} games
                            </td>
                            <td class="p-2">
                                {{ sample(split.defense) }}<br />{{
                                    split.defense?.plays ?? 0
                                }}
                                measured plays ·
                                {{ split.defense?.games ?? 0 }} games
                                <span
                                    v-if="
                                        split.defense
                                            ?.target_mapping_coverage != null
                                    "
                                    ><br />Position mapping:
                                    {{
                                        (
                                            split.defense
                                                .target_mapping_coverage * 100
                                        ).toFixed(0)
                                    }}%</span
                                >
                                <span
                                    v-if="
                                        split.defense && !split.defense.eligible
                                    "
                                    ><br />{{ split.defense.reason }}</span
                                >
                            </td>
                            <td class="p-2">
                                {{
                                    number(
                                        split.previous_opponents
                                            .weighted_epa_allowed,
                                    )
                                }}
                                EPA allowed<br />{{
                                    split.previous_opponents.games
                                }}/{{ split.offense?.games ?? 0 }} comparable
                                games
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <p class="text-muted-foreground">
            Targets include incompletions and interceptions when the target
            identity is known. These are defensive-unit outcomes against a
            position, not individual coverage grades. No prediction or prop
            adjustment is applied.
        </p>
    </details>
</template>
