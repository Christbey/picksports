<script setup lang="ts">
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
}

defineProps<{
    awayTeamAbbr?: string | null;
    homeTeamAbbr?: string | null;
    awayInjuries: InjuryItem[];
    homeInjuries: InjuryItem[];
}>();

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
): { label: string; className: string } => {
    if (injury.impact_label) {
        const normalized = injury.impact_label.toLowerCase();
        if (normalized === 'high') {
            return {
                label: `High${injury.impact_score ? ` (${injury.impact_score})` : ''}`,
                className: 'text-red-600 dark:text-red-300',
            };
        }
        if (normalized === 'medium') {
            return {
                label: `Medium${injury.impact_score ? ` (${injury.impact_score})` : ''}`,
                className: 'text-orange-600 dark:text-orange-300',
            };
        }

        return {
            label: `Low${injury.impact_score ? ` (${injury.impact_score})` : ''}`,
            className: 'text-zinc-600 dark:text-zinc-300',
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
            label: 'High impact',
            className: 'text-red-600 dark:text-red-300',
        };
    }
    if (status.includes('out') || status.includes('doubt')) {
        return {
            label: 'Medium impact',
            className: 'text-orange-600 dark:text-orange-300',
        };
    }
    if (status.includes('question') || moderate) {
        return {
            label: 'Low-medium impact',
            className: 'text-amber-600 dark:text-amber-300',
        };
    }

    return {
        label: 'Low impact',
        className: 'text-zinc-600 dark:text-zinc-300',
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
</script>

<template>
    <div class="ui-surface p-5 md:p-6">
        <h3 class="ui-kicker">Injury Report</h3>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div>
                <p class="text-sm font-semibold text-foreground/90">
                    {{ awayTeamAbbr || 'Away' }}
                </p>
                <ul v-if="awayInjuries.length > 0" class="mt-2 space-y-2">
                    <li
                        v-for="injury in awayInjuries"
                        :key="`away-${injury.id}`"
                        class="ui-surface-subtle p-2"
                    >
                        <div class="flex items-start gap-3">
                            <img
                                v-if="injury.player_headshot"
                                :src="injury.player_headshot"
                                :alt="injury.player_name || 'Player'"
                                class="h-10 w-10 rounded-full object-cover ring-1 ring-border/70"
                            />
                            <div
                                v-else
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-foreground/80"
                            >
                                {{ initials(injury.player_name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-medium text-foreground">
                                        {{ injury.player_name || 'Player' }}
                                    </p>
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                            statusClass(injury.status),
                                        ]"
                                    >
                                        {{ injury.status || 'Injury' }}
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        injury.detail ||
                                        injury.type ||
                                        'No details'
                                    }}
                                </p>
                                <p
                                    class="text-xs font-medium"
                                    :class="impactInfo(injury).className"
                                >
                                    Potential game impact:
                                    {{ impactInfo(injury).label }}
                                </p>
                                <p
                                    v-if="
                                        injury.impact_spread ||
                                        injury.impact_total
                                    "
                                    class="text-[11px] text-muted-foreground"
                                >
                                    Model effect:
                                    {{
                                        Number(
                                            injury.impact_spread || 0,
                                        ).toFixed(2)
                                    }}
                                    spread pts,
                                    {{
                                        Number(
                                            injury.impact_total || 0,
                                        ).toFixed(2)
                                    }}
                                    total pts
                                </p>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    No active injuries listed.
                </p>
            </div>
            <div>
                <p class="text-sm font-semibold text-foreground/90">
                    {{ homeTeamAbbr || 'Home' }}
                </p>
                <ul v-if="homeInjuries.length > 0" class="mt-2 space-y-2">
                    <li
                        v-for="injury in homeInjuries"
                        :key="`home-${injury.id}`"
                        class="ui-surface-subtle p-2"
                    >
                        <div class="flex items-start gap-3">
                            <img
                                v-if="injury.player_headshot"
                                :src="injury.player_headshot"
                                :alt="injury.player_name || 'Player'"
                                class="h-10 w-10 rounded-full object-cover ring-1 ring-border/70"
                            />
                            <div
                                v-else
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-foreground/80"
                            >
                                {{ initials(injury.player_name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-medium text-foreground">
                                        {{ injury.player_name || 'Player' }}
                                    </p>
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                            statusClass(injury.status),
                                        ]"
                                    >
                                        {{ injury.status || 'Injury' }}
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        injury.detail ||
                                        injury.type ||
                                        'No details'
                                    }}
                                </p>
                                <p
                                    class="text-xs font-medium"
                                    :class="impactInfo(injury).className"
                                >
                                    Potential game impact:
                                    {{ impactInfo(injury).label }}
                                </p>
                                <p
                                    v-if="
                                        injury.impact_spread ||
                                        injury.impact_total
                                    "
                                    class="text-[11px] text-muted-foreground"
                                >
                                    Model effect:
                                    {{
                                        Number(
                                            injury.impact_spread || 0,
                                        ).toFixed(2)
                                    }}
                                    spread pts,
                                    {{
                                        Number(
                                            injury.impact_total || 0,
                                        ).toFixed(2)
                                    }}
                                    total pts
                                </p>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    No active injuries listed.
                </p>
            </div>
        </div>
    </div>
</template>
