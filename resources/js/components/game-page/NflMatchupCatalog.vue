<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    matchupCategories,
    matchupChecklist,
    signalStatus,
    type NflMatchupSignalData,
} from '@/lib/nflMatchupSignals';

const props = defineProps<{
    data: NflMatchupSignalData;
    diagnostics?: boolean;
}>();
const query = ref('');
const category = ref('all');
const status = ref('all');
const visibleCount = ref(20);
const checklist = computed(() =>
    matchupChecklist(props.data).filter(
        (entry) =>
            props.diagnostics ||
            ['matched', 'not_matched', 'descriptive_record'].includes(
                entry.status,
            ),
    ),
);
const filtered = computed(() =>
    checklist.value.filter(
        (entry) =>
            (category.value === 'all' || entry.category === category.value) &&
            (status.value === 'all' || entry.status === status.value) &&
            `${entry.id} ${entry.label} ${entry.definition ?? ''}`
                .toLowerCase()
                .includes(query.value.trim().toLowerCase()),
    ),
);
watch([query, category, status, () => props.data], () => {
    visibleCount.value = 20;
});
const team = (id: number) =>
    Object.values(props.data.situational).find((side) => side.team_id === id)
        ?.label ?? `Team ${id}`;
const value = (number: number | null | undefined) =>
    number == null || !Number.isFinite(number)
        ? 'Unavailable'
        : number.toFixed(3);
</script>

<template>
    <section
        class="min-w-0 space-y-3"
        :aria-label="
            diagnostics
                ? 'Full independent matchup checklist'
                : 'Evaluated matchup comparisons'
        "
    >
        <h3 class="font-semibold">
            {{ diagnostics ? 'Full checklist' : 'Evaluated matchups' }} ·
            {{ checklist.length }} items
        </h3>
        <p v-if="diagnostics" class="text-xs text-muted-foreground">
            Every supplied item is retained. Open an item for both teams'
            evidence, sample sizes, definition or missing inputs. Status counts
            are not confidence scores.
        </p>
        <input
            v-model="query"
            type="search"
            aria-label="Search matchup checklist"
            placeholder="Search by number or matchup…"
            class="min-h-11 w-full rounded-md border bg-background px-3 text-sm"
        />
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <select
                v-model="category"
                aria-label="Matchup checklist category"
                class="min-h-11 min-w-0 rounded-md border bg-background px-3 text-sm"
            >
                <option value="all">All categories</option>
                <option
                    v-for="(label, key) in matchupCategories"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </select>
            <select
                v-model="status"
                aria-label="Matchup checklist status"
                class="min-h-11 min-w-0 rounded-md border bg-background px-3 text-sm"
            >
                <option value="all">All statuses</option>
                <option value="matched">Condition met</option>
                <option value="not_matched">Condition not met</option>
                <option value="descriptive_record">
                    Historical record available
                </option>
                <option v-if="diagnostics" value="insufficient_data">
                    Insufficient history
                </option>
                <option v-if="diagnostics" value="unavailable">
                    Not yet evaluated
                </option>
            </select>
        </div>
        <p role="status" class="text-xs text-muted-foreground">
            Showing {{ Math.min(visibleCount, filtered.length) }} of
            {{ filtered.length }} matching items.
        </p>
        <div class="divide-y rounded-lg border px-3">
            <details
                v-for="entry in filtered.slice(0, visibleCount)"
                :key="entry.id"
                class="min-w-0 py-2"
            >
                <summary
                    class="min-h-11 cursor-pointer content-center text-sm break-words"
                >
                    <span class="font-medium"
                        >#{{ entry.id }} {{ entry.label }}</span
                    >
                    <span class="mt-1 block text-xs text-muted-foreground">{{
                        signalStatus(entry.status)
                    }}</span>
                </summary>
                <div class="space-y-2 pb-2 text-xs text-muted-foreground">
                    <p v-if="entry.definition">
                        Definition: {{ entry.definition }}
                    </p>
                    <div
                        v-for="signal in entry.signals"
                        :key="signal.offense_team_id"
                        class="rounded-md bg-muted/40 p-2"
                    >
                        <p class="font-medium text-foreground">
                            {{ team(signal.offense_team_id) }}
                            <template v-if="signal.evidence.personnel_only"
                                >projected offensive line:</template
                            >
                            <template v-else
                                >offense vs
                                {{
                                    team(signal.defense_team_id)
                                }}
                                defense:</template
                            >
                            {{ signalStatus(signal.status) }}
                        </p>
                        <p v-if="signal.evidence.offense.display_value">
                            {{ signal.evidence.offense.display_value }}
                        </p>
                        <p v-else>
                            {{
                                signal.evidence.offense.player_name ?? 'Offense'
                            }}
                            {{ value(signal.evidence.offense.value) }} · rank
                            {{ signal.evidence.offense.rank ?? 'unavailable' }}
                            · {{ signal.evidence.offense.games }} games
                        </p>
                        <p v-if="!signal.evidence.personnel_only">
                            Defense {{ value(signal.evidence.defense.value) }} ·
                            rank
                            {{ signal.evidence.defense.rank ?? 'unavailable' }}
                            · {{ signal.evidence.defense.games }} games
                        </p>
                        <p v-if="diagnostics">{{ signal.reason }}</p>
                        <p>
                            <template v-if="!signal.evidence.personnel_only"
                                >League coverage:
                                {{
                                    signal.evidence.league_teams
                                }}
                                teams.</template
                            >
                            Source: {{ signal.evidence.source }}.
                        </p>
                    </div>
                    <div
                        v-for="situation in entry.situations"
                        :key="situation.team"
                        class="rounded-md bg-muted/40 p-2"
                    >
                        <p class="font-medium text-foreground">
                            {{ situation.team }}:
                            {{ situation.record.record.wins }}–{{
                                situation.record.record.losses
                            }}–{{ situation.record.record.ties }}
                            {{
                                situation.record.record_type === 'ats'
                                    ? 'W–L–P ATS'
                                    : situation.record.record_type === 'totals'
                                      ? 'O–U–P'
                                      : 'W–L–T'
                            }}
                            · {{ situation.record.sample_size }} games
                        </p>
                        <p>
                            {{ signalStatus(situation.record.status) }}.
                            {{ situation.record.definition }}
                        </p>
                        <p>
                            {{ situation.record.from_date ?? 'No start date' }}
                            –
                            {{
                                situation.record.through_date ?? 'No end date'
                            }}. Current and previous three regular seasons;
                            independent of the efficiency-window selector.
                        </p>
                    </div>
                    <template v-if="entry.status === 'unavailable'">
                        <p>{{ entry.reason }}</p>
                        <p
                            v-for="input in entry.required_inputs ?? []"
                            :key="input"
                        >
                            Required: {{ input }}
                        </p>
                    </template>
                    <p>Prediction effect: none.</p>
                </div>
            </details>
        </div>
        <p v-if="!filtered.length" class="text-sm text-muted-foreground">
            No items match these filters.
        </p>
        <button
            v-if="filtered.length > visibleCount"
            type="button"
            class="min-h-11 w-full rounded-md border px-3 text-sm"
            @click="visibleCount += 20"
        >
            Show 20 more items
        </button>
    </section>
</template>
