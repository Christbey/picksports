<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
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
const api = useApiV2Client();
const revisions = ref<Revision[]>([]);
const error = ref('');
const loading = ref(true);
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
    <section
        class="min-w-0 rounded-xl border bg-card p-4 text-card-foreground sm:p-5"
    >
        <h2 class="text-lg font-semibold">Research and prediction changes</h2>
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
            <p class="mt-2 text-sm">
                {{
                    gameStatus === 'STATUS_FINAL'
                        ? 'Archived spread assessment:'
                        : 'Latest spread assessment:'
                }}
                <strong>{{
                    decisionLabel(revisions[0].brief.eligibility.status)
                }}</strong>
            </p>
            <p class="text-sm text-muted-foreground">
                {{
                    gameStatus === 'STATUS_FINAL'
                        ? 'Pregame research retained for review, not a current recommendation.'
                        : 'Research approval and forecast values are separate. Any blocking evidence remains listed below.'
                }}
            </p>
            <p class="text-sm text-muted-foreground">
                Recorded
                {{ new Date(revisions[0].created_at).toLocaleString() }}.
                <span :class="mobileCompact ? 'hidden md:inline' : ''"
                    >Original forecasts are in Forecast history.</span
                >
            </p>
            <ul
                v-if="revisions[0].brief.eligibility.reasons.length"
                class="mt-3 list-inside list-disc text-sm"
            >
                <li v-for="reason in reasons" :key="reason">
                    {{ reason }}
                </li>
            </ul>
            <details
                v-if="revisions[0].brief.eligibility.reasons.length"
                class="mt-2 text-xs text-muted-foreground"
            >
                <summary class="min-h-11 cursor-pointer content-center">
                    Technical reason codes
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
                <details
                    v-for="group in [
                        'supporting',
                        'opposing',
                        'prop_angles',
                        'unresolved',
                    ] as const"
                    :key="group"
                    class="min-w-0 self-start rounded-lg border p-3"
                >
                    <summary
                        class="min-h-11 cursor-pointer content-center font-medium"
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
                        ({{ revisions[0].brief[group].length }})
                    </summary>
                    <p
                        v-if="!revisions[0].brief[group].length"
                        class="text-sm text-muted-foreground"
                    >
                        No verified entry.
                    </p>
                    <ul class="mt-2 space-y-3 text-sm">
                        <li
                            v-for="(item, index) in revisions[0].brief[group]"
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
                </details>
            </div>
            <details
                class="mt-4 text-sm"
                :class="mobileCompact ? 'hidden md:block' : ''"
            >
                <summary
                    class="min-h-11 cursor-pointer content-center font-medium"
                >
                    Forecast history (home minus away margin)
                </summary>
                <div class="overflow-x-auto">
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
            </details>
        </template>
    </section>
</template>
