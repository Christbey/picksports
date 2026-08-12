<script setup lang="ts">
import { computed, reactive } from 'vue';
import type { PredictionSummary } from '@/types';

interface InjuryItem {
    id: number;
    player_id: number;
    player_name?: string | null;
    player_headshot?: string | null;
    status?: string | null;
    detail?: string | null;
    type?: string | null;
    impact_score?: number | null;
    impact_label?: string | null;
    impact_spread?: number | null;
    impact_total?: number | null;
    impact_multiplier?: number | null;
    injury_date?: string | null;
    return_date?: string | null;
    source_updated_at?: string | null;
}

const props = defineProps<{
    awayTeamAbbr?: string | null;
    homeTeamAbbr?: string | null;
    awayInjuries: InjuryItem[];
    homeInjuries: InjuryItem[];
    depthChartContext?: PredictionSummary['depth_chart_context'] | null;
}>();

const teams = computed(() => [
    {
        key: 'away',
        label: props.awayTeamAbbr || 'Away',
        injuries: props.awayInjuries,
    },
    {
        key: 'home',
        label: props.homeTeamAbbr || 'Home',
        injuries: props.homeInjuries,
    },
]);

const totalInjuries = computed(
    () => props.awayInjuries.length + props.homeInjuries.length,
);

const visibleLimit = 3;
const expandedTeams = reactive<Record<string, boolean>>({
    away: false,
    home: false,
});

const visibleInjuries = (team: { key: string; injuries: InjuryItem[] }) =>
    expandedTeams[team.key]
        ? team.injuries
        : team.injuries.slice(0, visibleLimit);

const hiddenCount = (team: { key: string; injuries: InjuryItem[] }): number =>
    Math.max(team.injuries.length - visibleLimit, 0);

const statusClass = (status: string | null | undefined): string => {
    const normalized = (status || '').toLowerCase();
    if (normalized.includes('out') || normalized.includes('ir'))
        return 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300';
    if (normalized.includes('doubt'))
        return 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300';
    if (normalized.includes('question'))
        return 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300';
    if (normalized.includes('probable') || normalized.includes('day-to-day'))
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300';
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
};

const impactInfo = (
    injury: InjuryItem,
): { label: string; className: string; chipClass: string } => {
    const score = injury.impact_score ? ` ${injury.impact_score}` : '';

    if (injury.impact_label) {
        const normalized = injury.impact_label.toLowerCase();
        if (normalized === 'high') {
            return {
                label: `High${score}`,
                className: 'text-red-600 dark:text-red-300',
                chipClass:
                    'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
            };
        }
        if (normalized === 'medium') {
            return {
                label: `Medium${score}`,
                className: 'text-orange-600 dark:text-orange-300',
                chipClass:
                    'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-950/40 dark:text-orange-300 dark:ring-orange-900',
            };
        }

        return {
            label: `Low${score}`,
            className: 'text-zinc-600 dark:text-zinc-300',
            chipClass:
                'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700',
        };
    }

    const status = (injury.status || '').toLowerCase();
    const note = `${injury.detail || ''} ${injury.type || ''}`.toLowerCase();

    const severeKeywords = ['surgery', 'acl', 'achilles', 'fracture', 'tear'];
    const moderateKeywords = [
        'sprain',
        'strain',
        'hamstring',
        'knee',
        'ankle',
        'concussion',
    ];

    const severe = severeKeywords.some((k) => note.includes(k));
    const moderate = moderateKeywords.some((k) => note.includes(k));

    if (status.includes('out') && severe) {
        return {
            label: 'High',
            className: 'text-red-600 dark:text-red-300',
            chipClass:
                'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
        };
    }
    if (status.includes('out') || status.includes('doubt')) {
        return {
            label: 'Medium',
            className: 'text-orange-600 dark:text-orange-300',
            chipClass:
                'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-950/40 dark:text-orange-300 dark:ring-orange-900',
        };
    }
    if (status.includes('question') || moderate) {
        return {
            label: 'Low-med',
            className: 'text-amber-600 dark:text-amber-300',
            chipClass:
                'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
        };
    }

    return {
        label: 'Low',
        className: 'text-zinc-600 dark:text-zinc-300',
        chipClass:
            'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700',
    };
};

const initials = (name: string | null | undefined): string => {
    if (!name) return 'P';
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() || '')
        .join('');
};

const hasModelEffect = (injury: InjuryItem): boolean =>
    Math.abs(Number(injury.impact_spread || 0)) > 0 ||
    Math.abs(Number(injury.impact_total || 0)) > 0;

const injuryNote = (injury: InjuryItem): string => {
    const parts = [injury.detail, injury.type]
        .map((value) => value?.trim())
        .filter(Boolean);

    return [...new Set(parts)].join(' - ') || 'No injury detail';
};

const compactDate = (value: string | null | undefined): string | null => {
    if (!value) return null;
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;

    return parsed.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
};

const formatSource = (value: string | null | undefined): string =>
    String(value || 'availability model').replaceAll('_', ' ');

const signed = (value: number): string =>
    value > 0 ? `+${value.toFixed(1)}` : value.toFixed(1);

const modelImpactLines = computed(() => {
    const context = props.depthChartContext;
    if (!context) return [];

    if (context.type === 'injury_weighting') {
        const items: string[] = [];
        const homeOut = context.home_out_weighted ?? 0;
        const homeQuestionable = context.home_questionable_weighted ?? 0;
        const awayOut = context.away_out_weighted ?? 0;
        const awayQuestionable = context.away_questionable_weighted ?? 0;
        const weightedTotal =
            homeOut + homeQuestionable + awayOut + awayQuestionable;
        const spreadAdjustment = context.spread_adjustment ?? 0;
        const totalAdjustment = context.total_adjustment ?? 0;
        const hasSpreadAdjustment = Math.abs(spreadAdjustment) >= 0.05;
        const hasTotalAdjustment = Math.abs(totalAdjustment) >= 0.05;

        if (weightedTotal > 0) {
            items.push(
                `Weighted availability: ${props.homeTeamAbbr || 'Home'} ${homeOut.toFixed(2)} out / ${homeQuestionable.toFixed(2)} questionable, ${props.awayTeamAbbr || 'Away'} ${awayOut.toFixed(2)} out / ${awayQuestionable.toFixed(2)} questionable.`,
            );
        } else if (
            context.applied ||
            hasSpreadAdjustment ||
            hasTotalAdjustment
        ) {
            items.push(
                `Availability model used ${formatSource(context.injury_total_model_source || context.injury_model_source)}; no weighted depth-chart injuries were detected.`,
            );
        }

        if (hasSpreadAdjustment) {
            items.push(`Spread impact ${signed(spreadAdjustment)}.`);
        }

        if (hasTotalAdjustment) {
            items.push(`Total impact ${signed(totalAdjustment)}.`);
        }

        return items;
    }

    const items: string[] = [];
    if (context.home_pitcher_source || context.away_pitcher_source) {
        items.push(
            `Pitcher source: home ${formatSource(context.home_pitcher_source)}, away ${formatSource(context.away_pitcher_source)}.`,
        );
    }

    if (
        context.home_depth_chart_fallback_used ||
        context.away_depth_chart_fallback_used
    ) {
        items.push(
            `Depth-chart starter fallback was used${context.home_depth_chart_fallback_used && context.away_depth_chart_fallback_used ? ' on both sides' : context.home_depth_chart_fallback_used ? ' for the home side' : ' for the away side'}.`,
        );
    }

    if (context.probable_pitcher_injury_applied) {
        items.push('Probable pitcher availability adjustments were applied.');
    }

    return items;
});
</script>

<template>
    <div class="ui-surface p-4 md:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="ui-kicker">Injuries & Availability</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ totalInjuries }} active
                    {{ totalInjuries === 1 ? 'listing' : 'listings' }}
                </p>
            </div>
            <div class="flex items-center gap-2 text-[11px] font-medium">
                <span
                    class="rounded-full bg-muted px-2 py-1 text-foreground/80"
                >
                    {{ awayTeamAbbr || 'Away' }} {{ awayInjuries.length }}
                </span>
                <span
                    class="rounded-full bg-muted px-2 py-1 text-foreground/80"
                >
                    {{ homeTeamAbbr || 'Home' }} {{ homeInjuries.length }}
                </span>
            </div>
        </div>

        <div
            v-if="modelImpactLines.length > 0"
            class="mt-4 rounded-lg border border-border/60 bg-background/55 p-3"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 text-xs"
            >
                <span
                    class="font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    Model availability impact
                </span>
                <span
                    class="rounded-full bg-muted px-2 py-1 font-medium text-foreground/80"
                >
                    {{ modelImpactLines.length }}
                    {{ modelImpactLines.length === 1 ? 'note' : 'notes' }}
                </span>
            </div>
            <div class="mt-2 space-y-1.5 text-sm text-foreground/90">
                <p v-for="line in modelImpactLines" :key="line">
                    {{ line }}
                </p>
            </div>
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-2">
            <section
                v-for="team in teams"
                :key="team.key"
                class="rounded-lg border border-border/60 bg-background/55 p-3"
            >
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-foreground/90">
                        {{ team.label }}
                    </p>
                    <span class="text-xs text-muted-foreground">
                        {{ team.injuries.length }} active
                    </span>
                </div>

                <ul v-if="team.injuries.length > 0" class="mt-3 space-y-2">
                    <li
                        v-for="injury in visibleInjuries(team)"
                        :key="`${team.key}-${injury.id}`"
                        class="rounded-md border border-border/50 bg-card/70 p-2.5"
                    >
                        <div class="flex items-start gap-2.5">
                            <img
                                v-if="injury.player_headshot"
                                :src="injury.player_headshot"
                                :alt="injury.player_name || 'Player'"
                                class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-border/70"
                            />
                            <div
                                v-else
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-foreground/80"
                            >
                                {{ initials(injury.player_name) }}
                            </div>

                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="truncate text-sm font-medium text-foreground"
                                        >
                                            {{ injury.player_name || 'Player' }}
                                        </p>
                                        <p
                                            class="mt-0.5 truncate text-xs text-muted-foreground"
                                        >
                                            {{ injuryNote(injury) }}
                                        </p>
                                    </div>
                                    <span
                                        :class="[
                                            'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                            statusClass(injury.status),
                                        ]"
                                    >
                                        {{ injury.status || 'Injury' }}
                                    </span>
                                </div>

                                <div
                                    class="mt-2 flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1',
                                            impactInfo(injury).chipClass,
                                        ]"
                                    >
                                        Impact {{ impactInfo(injury).label }}
                                    </span>
                                    <span
                                        v-if="hasModelEffect(injury)"
                                        class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                                    >
                                        Spr
                                        {{
                                            Number(
                                                injury.impact_spread || 0,
                                            ).toFixed(2)
                                        }}
                                    </span>
                                    <span
                                        v-if="hasModelEffect(injury)"
                                        class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                                    >
                                        Tot
                                        {{
                                            Number(
                                                injury.impact_total || 0,
                                            ).toFixed(2)
                                        }}
                                    </span>
                                    <span
                                        v-if="compactDate(injury.return_date)"
                                        class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                                    >
                                        Return
                                        {{ compactDate(injury.return_date) }}
                                    </span>
                                    <span
                                        v-else-if="
                                            compactDate(injury.injury_date)
                                        "
                                        class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                                    >
                                        Listed
                                        {{ compactDate(injury.injury_date) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>

                <button
                    v-if="hiddenCount(team) > 0"
                    type="button"
                    class="mt-3 w-full rounded-md border border-border/70 px-3 py-2 text-xs font-semibold text-foreground/80 transition hover:bg-muted"
                    @click="expandedTeams[team.key] = !expandedTeams[team.key]"
                >
                    {{
                        expandedTeams[team.key]
                            ? 'Show fewer injuries'
                            : `Show ${hiddenCount(team)} more`
                    }}
                </button>

                <p v-else class="mt-3 text-sm text-muted-foreground">
                    No active injuries listed.
                </p>
            </section>
        </div>
    </div>
</template>
