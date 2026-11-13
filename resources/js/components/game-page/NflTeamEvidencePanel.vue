<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatDateLong } from '@/composables/useFormatters';
import type { NflTeamEvidence } from '@/types';

const props = defineProps<{
    away?: NflTeamEvidence;
    home?: NflTeamEvidence;
    awayLabel: string;
    homeLabel: string;
    window: string;
    workspace?: boolean;
}>();
const emit = defineEmits<{ 'update:window': [value: string] }>();
const mobileTeam = ref('away');
const metricsHelpOpen = ref(false);
const evidenceView = ref<'metrics' | 'comparison'>('metrics');
const metricGroup = ref<'scoring' | 'execution'>('scoring');
const windows = {
    recent_5: 'Last 5',
    recent_10: 'Last 10',
    season: 'This season',
    historical: 'Previous 3 seasons',
};
const labels: Record<string, string> = {
    points_for: 'Points scored / game',
    points_against: 'Points allowed / game',
    margin: 'Scoring margin',
    yards_per_play: 'Offensive yards / play',
    turnovers: 'Turnovers / game',
    third_down_pct: '3rd-down conversion %',
    red_zone_pct: 'Red-zone scoring %',
    yards_allowed: 'Yards allowed / game',
};
const visibleMetrics = computed(() =>
    Object.entries(labels).filter(
        ([key]) =>
            !props.workspace ||
            [
                'points_for',
                'points_against',
                'margin',
                'yards_allowed',
            ].includes(key) ===
                (metricGroup.value === 'scoring'),
    ),
);
const sides = computed(() =>
    [
        { key: 'away', label: props.awayLabel, profile: props.away },
        { key: 'home', label: props.homeLabel, profile: props.home },
    ].map((side) => ({
        ...side,
        evidence: side.profile?.windows[props.window]?.evidence,
    })),
);
const format = (value: number | null | undefined) =>
    value == null || !Number.isFinite(value) ? 'Unavailable' : value.toFixed(2);
const date = (value: string | null | undefined) =>
    value ? formatDateLong(value) : 'Unavailable';
</script>

<template>
    <section
        class="space-y-4 rounded-lg border bg-background/35 p-3 sm:p-4"
        aria-label="Team evidence windows"
    >
        <div
            class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap"
            role="group"
            aria-label="History window"
        >
            <Button
                v-for="(label, key) in windows"
                :key="key"
                type="button"
                class="h-auto min-h-11 px-2 whitespace-normal"
                :variant="window === key ? 'default' : 'outline'"
                :aria-pressed="window === key"
                @click="emit('update:window', key)"
            >
                {{ label }}
            </Button>
        </div>
        <nav
            v-if="workspace"
            aria-label="Evidence views"
            class="flex gap-1 rounded-lg bg-muted/40 p-1"
        >
            <Button
                type="button"
                class="flex-1"
                :variant="evidenceView === 'metrics' ? 'default' : 'ghost'"
                :aria-pressed="evidenceView === 'metrics'"
                @click="evidenceView = 'metrics'"
            >
                Metrics
            </Button>
            <Button
                type="button"
                class="flex-1"
                :variant="evidenceView === 'comparison' ? 'default' : 'ghost'"
                :aria-pressed="evidenceView === 'comparison'"
                @click="evidenceView = 'comparison'"
            >
                What changed?
            </Button>
        </nav>
        <div
            v-show="!workspace || evidenceView === 'metrics'"
            class="space-y-4"
        >
            <div
                v-if="workspace"
                role="group"
                aria-label="Metric group"
                class="grid grid-cols-2 gap-2"
            >
                <Button
                    type="button"
                    variant="outline"
                    :aria-pressed="metricGroup === 'scoring'"
                    @click="metricGroup = 'scoring'"
                    >Scoring</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :aria-pressed="metricGroup === 'execution'"
                    @click="metricGroup = 'execution'"
                    >Execution</Button
                >
            </div>
            <div
                class="grid grid-cols-2 gap-2 md:hidden"
                role="group"
                aria-label="Team evidence selection"
            >
                <Button
                    v-for="side in sides"
                    :key="side.key"
                    type="button"
                    :variant="mobileTeam === side.key ? 'default' : 'outline'"
                    :aria-pressed="mobileTeam === side.key"
                    @click="mobileTeam = side.key"
                >
                    {{ side.label }}
                </Button>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div
                    v-for="side in sides"
                    :key="side.key"
                    class="min-w-0 space-y-2"
                    :class="mobileTeam === side.key ? '' : 'hidden md:block'"
                >
                    <h3 class="font-semibold">
                        {{ side.label }} ·
                        {{ side.evidence?.label ?? 'Evidence unavailable' }}
                    </h3>
                    <template
                        v-if="side.evidence && side.evidence.sample_size > 0"
                    >
                        <p class="text-sm">
                            {{ side.evidence.record.wins }}–{{
                                side.evidence.record.losses
                            }}–{{ side.evidence.record.ties }} W–L–T ·
                            {{ side.evidence.sample_size }}
                            {{
                                side.evidence.sample_size === 1
                                    ? 'game'
                                    : 'games'
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ date(side.evidence.from_date) }} –
                            {{ date(side.evidence.through_date) }} · Seasons:
                            {{ side.evidence.seasons.join(', ') || 'none' }}
                        </p>
                        <p
                            v-if="side.evidence.seasons.length > 1"
                            class="text-xs text-amber-600"
                        >
                            Cross-season sample: personnel and coaching may
                            differ.
                        </p>
                        <table
                            :aria-label="`${side.label} metrics and available game samples`"
                            class="w-full text-sm"
                        >
                            <thead>
                                <tr>
                                    <th
                                        scope="col"
                                        class="text-left font-semibold"
                                    >
                                        Metric
                                    </th>
                                    <th
                                        scope="col"
                                        class="text-right font-semibold"
                                    >
                                        Average
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-2 text-right font-semibold"
                                    >
                                        NFL rank
                                    </th>
                                    <th
                                        scope="col"
                                        class="text-right font-semibold"
                                    >
                                        Games
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="[key, label] in visibleMetrics"
                                    :key="key"
                                >
                                    <th
                                        scope="row"
                                        class="border-t py-1 text-left font-normal"
                                    >
                                        {{ label }}
                                    </th>
                                    <td
                                        class="border-t py-1 text-right tabular-nums"
                                    >
                                        {{
                                            format(
                                                side.evidence.metrics[key]
                                                    ?.value,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="border-t px-2 py-1 text-right whitespace-nowrap tabular-nums"
                                    >
                                        <template
                                            v-if="
                                                side.evidence.metrics[key]
                                                    ?.rank != null
                                            "
                                        >
                                            {{
                                                side.evidence.metrics[key]
                                                    ?.rank_tied
                                                    ? 'T-'
                                                    : ''
                                            }}{{
                                                side.evidence.metrics[key]
                                                    ?.rank
                                            }}/{{
                                                side.evidence.metrics[key]
                                                    ?.ranked_teams
                                            }}
                                        </template>
                                        <span
                                            v-else
                                            aria-label="Rank unavailable"
                                            >—</span
                                        >
                                    </td>
                                    <td class="border-t py-1 text-right">
                                        {{
                                            side.evidence.metrics[key]
                                                ?.sample_size ?? 0
                                        }}/{{ side.evidence.sample_size }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="text-xs text-muted-foreground">
                            Data last updated:
                            {{
                                side.evidence.latest_source_update ??
                                'Unavailable'
                            }}.
                        </div>
                    </template>
                    <p
                        v-else-if="side.evidence"
                        class="text-sm text-muted-foreground"
                    >
                        No completed regular-season games in this window before
                        the kickoff cutoff. No record or averages are available.
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        Could not load this team's evidence. No comparison is
                        inferred.
                    </p>
                </div>
            </div>
            <details
                @toggle="
                    metricsHelpOpen = (
                        $event.currentTarget as HTMLDetailsElement
                    ).open
                "
            >
                <summary
                    class="min-h-11 cursor-pointer content-center text-sm font-medium"
                >
                    How to read the metrics
                </summary>
                <ul
                    v-if="metricsHelpOpen"
                    class="list-disc space-y-1 pl-4 text-xs text-muted-foreground"
                >
                    <li>Regular season only. Windows overlap.</li>
                    <li>Team selection changes the metrics table only.</li>
                    <li>Averages exclude games with missing stats.</li>
                    <li>
                        Ranks use the same window before kickoff. Rank 1 is
                        best. Ties share a rank.
                    </li>
                    <li>
                        EPA, pressure, injuries, weather, and market value
                        require separate evidence.
                    </li>
                </ul>
            </details>
        </div>
        <component
            :is="workspace ? 'section' : 'details'"
            v-show="!workspace || evidenceView === 'comparison'"
            aria-label="Team evidence comparison"
        >
            <component
                :is="workspace ? 'h3' : 'summary'"
                class="min-h-11 content-center text-sm font-medium"
                :class="{ 'cursor-pointer': !workspace }"
            >
                What changed? Last 5 vs preceding 10
            </component>
            <p class="mt-2 text-xs text-muted-foreground">
                Non-overlapping samples; at least 3 recorded games on each side
                required. Not opponent-adjusted or betting recommendations.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
                <div v-for="side in sides" :key="side.key">
                    <h4 class="mt-2 font-medium">{{ side.label }}</h4>
                    <p
                        v-if="!side.profile"
                        class="text-sm text-muted-foreground"
                    >
                        Comparison unavailable.
                    </p>
                    <p
                        v-for="change in side.profile?.changes ?? []"
                        :key="change.metric"
                        class="text-sm"
                    >
                        {{ labels[change.metric] }}:
                        {{ format(change.recent.value) }} (n={{
                            change.recent.sample_size
                        }}) vs {{ format(change.baseline.value) }} (n={{
                            change.baseline.sample_size
                        }}) ·
                        {{
                            change.delta == null
                                ? 'Insufficient evidence'
                                : `Change ${change.delta > 0 ? '+' : ''}${change.delta.toFixed(2)}`
                        }}
                    </p>
                </div>
            </div>
        </component>
    </section>
</template>
