<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useViewerAccess } from '@/composables/useViewerAccess';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { decisionLabel, researchReason } from '@/lib/researchDecision';

type Argument = {
    claim: string;
    interpretation: string;
    source_url: string | null;
    scope?: string;
    blocking?: boolean;
};
type Forecast = { predicted_spread: number; predicted_total: number };
type Revision = {
    id: number;
    created_at: string;
    baseline: Forecast;
    revised: Forecast;
    brief: {
        eligibility: { status: string; reasons: string[] };
        supporting: Argument[];
        opposing: Argument[];
        prop_angles: Argument[];
        unresolved: Argument[];
    };
};
const props = defineProps<{
    gameId: number;
    mobileCompact?: boolean;
    gameStatus?: string;
}>();
const { isAdmin } = useViewerAccess();
const api = useApiV2Client();
const revisions = ref<Revision[]>([]);
const error = ref('');
const loading = ref(true);
const openGroups = ref<string[]>([]);
const showHistory = ref(false);
function toggleGroup(group: string): void {
    openGroups.value = openGroups.value.includes(group)
        ? openGroups.value.filter((item) => item !== group)
        : [...openGroups.value, group];
}
const reasons = computed(() => [
    ...new Set(
        (revisions.value[0]?.brief.eligibility.reasons ?? []).map(
            researchReason,
        ),
    ),
]);
const sourceUrl = (url: string | null) =>
    url && /^https:\/\//i.test(url) ? url : undefined;
onMounted(async () => {
    try {
        const response = await api.games.research<{
            game_id: number;
            revisions: Revision[];
        }>('nfl', props.gameId);
        revisions.value = response?.data.revisions ?? [];
    } catch {
        error.value = 'Research is unavailable right now.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <form
        aria-label="Matchup research controls"
        class="min-w-0 rounded-xl border bg-card p-4 text-card-foreground sm:p-5"
        @submit.prevent
    >
        <h2 class="text-lg font-semibold">Matchup research</h2>
        <p v-if="loading" class="mt-2 text-sm text-muted-foreground">
            Loading research…
        </p>
        <p v-else-if="error" class="mt-2 text-sm text-muted-foreground">
            {{ error }}
        </p>
        <p
            v-else-if="!revisions.length"
            class="mt-2 text-sm text-muted-foreground"
        >
            No researched revision is available yet.
        </p>
        <template v-else>
            <dl
                class="mt-3 grid gap-3 rounded-lg bg-muted/30 p-3 sm:grid-cols-2"
            >
                <div>
                    <dt class="text-xs text-muted-foreground">
                        {{
                            gameStatus === 'STATUS_FINAL'
                                ? 'Archived spread assessment'
                                : 'Latest spread assessment'
                        }}
                    </dt>
                    <dd class="mt-1 text-sm font-semibold">
                        {{
                            decisionLabel(revisions[0].brief.eligibility.status)
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Recorded</dt>
                    <dd class="mt-1 text-sm">
                        {{ new Date(revisions[0].created_at).toLocaleString() }}
                    </dd>
                </div>
            </dl>
            <div class="mt-3 text-xs text-muted-foreground">
                {{
                    gameStatus === 'STATUS_FINAL'
                        ? 'Archived pregame assessment'
                        : 'Spread assessment only · separate from the moneyline pick'
                }}
            </div>
            <table
                v-if="reasons.length"
                class="mt-3 w-full text-left text-sm"
                aria-label="Assessment checks"
            >
                <caption class="sr-only">
                    Evidence requiring review
                </caption>
                <tbody class="divide-y divide-border/60">
                    <tr v-for="reason in reasons" :key="reason">
                        <th
                            scope="row"
                            class="w-16 py-2 pr-3 align-top text-xs font-medium text-muted-foreground"
                        >
                            Review
                        </th>
                        <td class="py-2">{{ reason }}</td>
                    </tr>
                </tbody>
            </table>
            <details
                v-if="isAdmin && revisions[0].brief.eligibility.reasons.length"
                class="mt-2 text-xs text-muted-foreground"
            >
                <summary class="min-h-11 cursor-pointer content-center">
                    Admin · technical reason codes
                </summary>
                <ul class="break-words">
                    <li
                        v-for="reason in revisions[0].brief.eligibility.reasons"
                        :key="reason"
                    >
                        {{ reason }}
                    </li>
                </ul>
            </details>
            <div
                class="mt-4 gap-3 md:grid-cols-2"
                :class="mobileCompact ? 'hidden md:grid' : 'grid'"
            >
                <div
                    v-for="group in [
                        'supporting',
                        'opposing',
                        'prop_angles',
                        'unresolved',
                    ] as const"
                    :key="group"
                    class="min-w-0 self-start rounded-lg border p-3"
                >
                    <button
                        type="button"
                        class="flex min-h-11 w-full items-center justify-between gap-3 text-left font-medium"
                        :aria-expanded="openGroups.includes(group)"
                        :aria-controls="`research-${gameId}-${group}`"
                        @click="toggleGroup(group)"
                    >
                        {{
                            {
                                supporting: 'Supporting evidence',
                                opposing: 'Counterarguments',
                                prop_angles: 'Player prop context',
                                unresolved:
                                    'Assumptions and unresolved questions',
                            }[group]
                        }}
                        <span class="text-xs text-muted-foreground"
                            >{{ revisions[0].brief[group].length }} ·
                            {{
                                openGroups.includes(group) ? 'Hide' : 'View'
                            }}</span
                        >
                    </button>
                    <div
                        v-if="openGroups.includes(group)"
                        :id="`research-${gameId}-${group}`"
                    >
                        <p
                            v-if="!revisions[0].brief[group].length"
                            class="text-sm text-muted-foreground"
                        >
                            No verified entry.
                        </p>
                        <ul class="mt-2 space-y-3 text-sm">
                            <li
                                v-for="(item, index) in revisions[0].brief[
                                    group
                                ]"
                                :key="index"
                            >
                                <p>{{ item.claim }}</p>
                                <p
                                    v-if="group === 'unresolved'"
                                    class="font-medium"
                                >
                                    {{
                                        item.blocking === false
                                            ? 'Nonblocking assumption'
                                            : 'Hold'
                                    }}
                                    ·
                                    {{
                                        item.scope === 'game' || !item.scope
                                            ? 'Whole game'
                                            : item.scope
                                    }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ item.interpretation }}
                                </p>
                                <a
                                    v-if="sourceUrl(item.source_url)"
                                    :href="sourceUrl(item.source_url)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex min-h-11 items-center underline"
                                    >Source</a
                                >
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div
                class="mt-4 text-sm"
                :class="mobileCompact ? 'hidden md:block' : ''"
            >
                <button
                    type="button"
                    class="min-h-11 text-left font-medium"
                    :aria-expanded="showHistory"
                    :aria-controls="`research-${gameId}-history`"
                    @click="showHistory = !showHistory"
                >
                    Forecast history (home minus away margin)
                </button>
                <div
                    v-if="showHistory"
                    :id="`research-${gameId}-history`"
                    class="overflow-x-auto"
                >
                    <table class="mt-2 w-full text-left">
                        <thead>
                            <tr>
                                <th>Observed</th>
                                <th>Original margin / total</th>
                                <th>Revised margin / total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="revision in revisions"
                                :key="revision.id"
                            >
                                <td>
                                    {{
                                        new Date(
                                            revision.created_at,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td>
                                    {{
                                        revision.baseline.predicted_spread ??
                                        '—'
                                    }}
                                    /
                                    {{
                                        revision.baseline.predicted_total ?? '—'
                                    }}
                                </td>
                                <td>
                                    {{ revision.revised.predicted_spread }} /
                                    {{ revision.revised.predicted_total }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </form>
</template>
