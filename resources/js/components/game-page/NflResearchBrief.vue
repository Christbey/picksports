<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
const researchGroups = {
    supporting: 'Supporting evidence',
    opposing: 'Counterarguments',
    prop_angles: 'Player prop context',
    unresolved: 'Assumptions and unresolved questions',
} as const;
type ResearchGroup = keyof typeof researchGroups;
const activeView = ref<ResearchGroup | 'history' | null>(null);
const entryIndex = ref(0);
const activeEntries = computed(() =>
    activeView.value && activeView.value !== 'history'
        ? (revisions.value[0]?.brief[activeView.value] ?? [])
        : [],
);
const activeEntry = computed(() => activeEntries.value[entryIndex.value]);
function selectView(view: ResearchGroup | 'history'): void {
    activeView.value = activeView.value === view ? null : view;
    entryIndex.value = 0;
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
            <nav
                aria-label="Research views"
                class="mt-4 grid gap-2 sm:grid-cols-2"
                :class="{ 'hidden md:grid': mobileCompact }"
            >
                <Button
                    v-for="(label, group) in researchGroups"
                    :key="group"
                    type="button"
                    :variant="activeView === group ? 'default' : 'outline'"
                    :aria-pressed="activeView === group"
                    class="h-auto min-h-11 justify-between text-left whitespace-normal"
                    @click="selectView(group)"
                >
                    {{ label }}
                    <Badge variant="secondary">{{
                        revisions[0].brief[group].length
                    }}</Badge>
                </Button>
                <Button
                    type="button"
                    :variant="activeView === 'history' ? 'default' : 'outline'"
                    :aria-pressed="activeView === 'history'"
                    class="justify-start"
                    @click="selectView('history')"
                >
                    Forecast history
                </Button>
            </nav>
            <section
                v-if="activeView && activeView !== 'history'"
                :aria-label="researchGroups[activeView]"
                class="mt-4 rounded-xl border bg-background/40 p-3"
            >
                <p v-if="!activeEntry" class="text-sm text-muted-foreground">
                    No verified entry.
                </p>
                <div v-else class="space-y-2 text-sm">
                    <div class="font-medium">{{ activeEntry.claim }}</div>
                    <Badge v-if="activeView === 'unresolved'" variant="outline">
                        {{
                            activeEntry.blocking === false
                                ? 'Nonblocking assumption'
                                : 'Hold'
                        }}
                        ·
                        {{
                            activeEntry.scope === 'game' || !activeEntry.scope
                                ? 'Whole game'
                                : activeEntry.scope
                        }}
                    </Badge>
                    <div
                        tabindex="0"
                        role="region"
                        aria-label="Evidence interpretation"
                        class="max-h-52 overflow-y-auto rounded-md bg-muted/40 p-3 text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {{ activeEntry.interpretation }}
                    </div>
                    <a
                        v-if="sourceUrl(activeEntry.source_url)"
                        :href="sourceUrl(activeEntry.source_url)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-h-11 items-center underline"
                        >Source</a
                    >
                    <div
                        v-if="activeEntries.length > 1"
                        class="flex items-center justify-between gap-2 border-t pt-2"
                    >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="entryIndex === 0"
                            @click="entryIndex--"
                            >Previous</Button
                        >
                        <span aria-live="polite"
                            >{{ entryIndex + 1 }} of
                            {{ activeEntries.length }}</span
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="entryIndex === activeEntries.length - 1"
                            @click="entryIndex++"
                            >Next</Button
                        >
                    </div>
                </div>
            </section>
            <section
                v-if="activeView === 'history'"
                aria-label="Forecast history"
                class="mt-4 overflow-x-auto rounded-xl border bg-background/40 p-3 text-sm"
            >
                <p class="text-xs text-muted-foreground">
                    Margins are home minus away.
                </p>
                <table class="mt-2 w-full text-left">
                    <thead>
                        <tr>
                            <th>Observed</th>
                            <th>Original margin / total</th>
                            <th>Revised margin / total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="revision in revisions" :key="revision.id">
                            <td>
                                {{
                                    new Date(
                                        revision.created_at,
                                    ).toLocaleString()
                                }}
                            </td>
                            <td>
                                {{ revision.baseline.predicted_spread ?? '—' }}
                                /
                                {{ revision.baseline.predicted_total ?? '—' }}
                            </td>
                            <td>
                                {{ revision.revised.predicted_spread }} /
                                {{ revision.revised.predicted_total }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </form>
</template>
