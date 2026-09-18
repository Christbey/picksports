<script setup lang="ts">
import { computed } from 'vue';
import { formatDateLong } from '@/composables/useFormatters';
import type { NflTeamEvidence } from '@/types';

const props = defineProps<{
    away?: NflTeamEvidence;
    home?: NflTeamEvidence;
    awayLabel: string;
    homeLabel: string;
    window: string;
}>();
const emit = defineEmits<{ 'update:window': [value: string] }>();
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
        class="space-y-4 rounded-lg border p-4"
        aria-label="Team evidence windows"
    >
        <div
            class="flex flex-wrap gap-2"
            role="group"
            aria-label="History window"
        >
            <button
                v-for="(label, key) in windows"
                :key="key"
                type="button"
                class="rounded-md border px-3 py-2 text-sm"
                :class="
                    window === key
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-background'
                "
                :aria-pressed="window === key"
                @click="emit('update:window', key)"
            >
                {{ label }}
            </button>
        </div>
        <p class="text-xs text-muted-foreground">
            Regular season only. These windows overlap; they are not independent
            confirmations. Head-to-head meetings remain separate below.
        </p>
        <div class="grid gap-4 md:grid-cols-2">
            <article v-for="side in sides" :key="side.key" class="space-y-2">
                <h3 class="font-semibold">
                    {{ side.label }} ·
                    {{ side.evidence?.label ?? 'Evidence unavailable' }}
                </h3>
                <template v-if="side.evidence && side.evidence.sample_size > 0">
                    <p class="text-sm">
                        {{ side.evidence.record.wins }}–{{
                            side.evidence.record.losses
                        }}–{{ side.evidence.record.ties }} W–L–T ·
                        {{ side.evidence.sample_size }}
                        {{ side.evidence.sample_size === 1 ? 'game' : 'games' }}
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
                        Cross-season sample: personnel and coaching may differ.
                    </p>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                side.label
                            }}
                            metrics and available game samples
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col" class="text-left">Metric</th>
                                <th scope="col" class="text-right">Average</th>
                                <th scope="col" class="text-right">Games</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(label, key) in labels"
                                :key="key"
                                class="border-t"
                            >
                                <td class="py-1">{{ label }}</td>
                                <td class="text-right tabular-nums">
                                    {{
                                        format(
                                            side.evidence.metrics[key]?.value,
                                        )
                                    }}
                                </td>
                                <td class="text-right">
                                    {{
                                        side.evidence.metrics[key]
                                            ?.sample_size ?? 0
                                    }}/{{ side.evidence.sample_size }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="text-xs text-muted-foreground">
                        Per-game averages; absent stats are excluded, not zero.
                        Data last updated:
                        {{
                            side.evidence.latest_source_update ?? 'Unavailable'
                        }}.
                    </p>
                </template>
                <p
                    v-else-if="side.evidence"
                    class="text-sm text-muted-foreground"
                >
                    No completed regular-season games in this window before the
                    kickoff cutoff. No record or averages are available.
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    Could not load this team's evidence. No comparison is
                    inferred.
                </p>
            </article>
        </div>
        <details>
            <summary class="cursor-pointer text-sm font-medium">
                What changed? Last 5 vs preceding 10
            </summary>
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
        </details>
        <p class="text-xs text-muted-foreground">
            EPA, pressure, injuries, weather and market value require separately
            verified matchup inputs. This panel does not infer them from scoring
            averages or claim a combined betting edge.
        </p>
    </section>
</template>
