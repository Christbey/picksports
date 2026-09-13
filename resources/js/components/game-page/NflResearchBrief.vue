<script setup lang="ts">
import { onMounted, ref } from 'vue';

type Argument = { claim: string; interpretation: string; source_url: string };
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
const props = defineProps<{ gameId: number }>();
const revisions = ref<Revision[]>([]);
const error = ref('');
const loading = ref(true);
const sourceUrl = (url: string) => (/^https:\/\//i.test(url) ? url : undefined);
onMounted(async () => {
    try {
        const response = await fetch(
            `/api/v1/nfl/games/${props.gameId}/research`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );
        if (!response.ok) throw new Error('Research is unavailable.');
        revisions.value = (await response.json()).revisions;
    } catch {
        error.value = 'Research is unavailable right now.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="rounded-xl border bg-card p-5 text-card-foreground">
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
                Latest assessment:
                <strong>{{ revisions[0].brief.eligibility.status }}</strong>
            </p>
            <p class="text-sm text-muted-foreground">
                {{ new Date(revisions[0].created_at).toLocaleString() }} ·
                Original forecasts remain available below.
            </p>
            <ul
                v-if="revisions[0].brief.eligibility.reasons.length"
                class="mt-3 list-inside list-disc text-sm"
            >
                <li
                    v-for="reason in revisions[0].brief.eligibility.reasons"
                    :key="reason"
                >
                    {{ reason.replaceAll('_', ' ') }}
                </li>
            </ul>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div
                    v-for="group in [
                        'supporting',
                        'opposing',
                        'prop_angles',
                        'unresolved',
                    ] as const"
                    :key="group"
                >
                    <h3 class="font-medium">
                        {{
                            {
                                supporting: 'Supporting evidence',
                                opposing: 'Counterarguments',
                                prop_angles: 'Player prop context',
                                unresolved: 'Still uncertain',
                            }[group]
                        }}
                    </h3>
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
                            <p class="text-muted-foreground">
                                {{ item.interpretation }}
                            </p>
                            <a
                                :href="sourceUrl(item.source_url)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline"
                                >Source</a
                            >
                        </li>
                    </ul>
                </div>
            </div>
            <details class="mt-4 text-sm">
                <summary class="cursor-pointer font-medium">
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
