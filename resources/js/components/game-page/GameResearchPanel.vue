<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { GameResearchData } from '@/types/game-research';

const props = defineProps<{ sport: string; gameId: number }>();
const api = useApiV2Client();
const research = ref<GameResearchData | null>(null);
const loading = ref(true);
const error = ref('');
const retrying = ref(false);
const retryMessage = ref('');
let request: AbortController | null = null;
let sequence = 0;
let poll: ReturnType<typeof setTimeout> | null = null;
let disposed = false;

async function retryAnalysis() {
    if (retrying.value || !research.value?.capabilities?.can_retry) return;
    retrying.value = true;
    retryMessage.value = '';
    const identity = `${props.sport}:${props.gameId}`;
    try {
        await api.games.retryAnalysis(props.sport, props.gameId);
        if (disposed || identity !== `${props.sport}:${props.gameId}`) return;
        retryMessage.value = 'Refresh queued. Saved results will update here.';
        let remaining = 6;
        const refresh = async () => {
            if (disposed || identity !== `${props.sport}:${props.gameId}`)
                return;
            await load();
            if (disposed || identity !== `${props.sport}:${props.gameId}`)
                return;
            if (
                --remaining > 0 &&
                research.value?.review.status !== 'current'
            ) {
                poll = setTimeout(refresh, 2000);
            } else {
                retrying.value = false;
            }
        };
        poll = setTimeout(refresh, 2000);
    } catch {
        retryMessage.value = 'Unable to queue a refresh right now.';
        retrying.value = false;
    }
}

async function load() {
    const current = ++sequence;
    request?.abort();
    request = new AbortController();
    loading.value = research.value === null;
    error.value = '';
    try {
        const response = await api.games.researchSummary(
            props.sport,
            props.gameId,
            {
                init: { signal: request.signal },
            },
        );
        if (current !== sequence) return;
        if (!response?.data) throw new Error('Research unavailable');
        research.value = response.data;
    } catch {
        if (current === sequence)
            error.value = 'Saved research is unavailable right now.';
    } finally {
        if (current === sequence) loading.value = false;
    }
}

onMounted(load);
watch(
    () => [props.sport, props.gameId],
    () => {
        if (poll) clearTimeout(poll);
        retrying.value = false;
        retryMessage.value = '';
        research.value = null;
        void load();
    },
);
onBeforeUnmount(() => {
    disposed = true;
    sequence++;
    request?.abort();
    if (poll) clearTimeout(poll);
});

const sections = computed(() =>
    [
        {
            title: 'Supporting evidence',
            findings: research.value?.supporting ?? [],
        },
        { title: 'Main concerns', findings: research.value?.concerns ?? [] },
        { title: 'Matchup facts', findings: research.value?.facts ?? [] },
        { title: 'Open questions', findings: research.value?.unresolved ?? [] },
    ].filter(({ findings }) => findings.length),
);

const statusLabel = computed(() => {
    if (research.value?.applicability === 'archived')
        return 'Archived pregame research';
    if (research.value?.applicability === 'expired')
        return 'Saved research has expired';
    if (research.value?.applicability === 'outdated')
        return 'Research needs reassessment';
    return research.value?.status === 'ready'
        ? 'Current research'
        : research.value?.status === 'partial'
          ? 'Partial evidence'
          : 'No current research available';
});

function timestamp(value: string) {
    return new Date(value).toLocaleString('en-US', {
        timeZone: 'America/Chicago',
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
const reviewLabels: Record<string, string> = {
    pending: 'Review pending',
    queued: 'Review queued',
    running: 'Review in progress',
    deferred: 'Review deferred',
    failed: 'Review unavailable',
    current: 'Current review',
    outdated: 'Review needs reassessment',
    expired: 'Review has expired',
    unavailable: 'No saved review available',
};
</script>

<template>
    <div class="space-y-5 pt-4">
        <p
            v-if="retryMessage"
            class="text-sm text-muted-foreground"
            role="status"
        >
            {{ retryMessage }}
        </p>
        <p v-if="loading" class="text-sm text-muted-foreground" role="status">
            Loading saved research…
        </p>
        <p
            v-else-if="error"
            class="text-sm text-muted-foreground"
            role="status"
        >
            {{ error }}
        </p>
        <template v-else-if="research">
            <button
                v-if="research.capabilities?.can_retry"
                type="button"
                :disabled="retrying"
                class="rounded-md border px-3 py-2 text-sm font-medium disabled:opacity-50"
                @click="retryAnalysis"
            >
                {{
                    retrying ? 'Refresh queued…' : 'Refresh research and review'
                }}
            </button>
            <p class="text-sm text-muted-foreground">
                {{ statusLabel }}
                <template v-if="research.assessed_at">
                    · Assessed
                    <time :datetime="research.assessed_at">{{
                        timestamp(research.assessed_at)
                    }}</time>
                    Central</template
                >
            </p>
            <p
                v-if="
                    research.applicability === 'outdated' ||
                    research.applicability === 'expired'
                "
                class="text-sm text-muted-foreground"
            >
                These saved findings may no longer describe the current matchup.
            </p>
            <section
                v-for="section in sections"
                :key="section.title"
                class="space-y-2"
            >
                <h3 class="font-medium">{{ section.title }}</h3>
                <ul class="space-y-2 text-sm leading-relaxed">
                    <li
                        v-for="(finding, index) in section.findings"
                        :key="index"
                        class="wrap-break-word"
                    >
                        <span
                            v-if="
                                finding.team_side &&
                                finding.team_side !== 'game'
                            "
                            class="text-xs font-medium text-muted-foreground"
                            >{{
                                finding.team_side === 'home'
                                    ? 'Home team'
                                    : 'Away team'
                            }}
                            ·
                        </span>
                        <strong class="font-medium">{{ finding.claim }}</strong>
                        <span
                            v-if="
                                finding.interpretation &&
                                finding.interpretation !== finding.claim
                            "
                        >
                            {{ finding.interpretation }}</span
                        >
                        <span
                            v-if="finding.evidence_kind === 'application'"
                            class="text-xs text-muted-foreground"
                        >
                            · PickSports stored evidence</span
                        >
                    </li>
                </ul>
            </section>
            <section
                v-if="
                    research.season_evidence &&
                    Object.keys(research.season_evidence).length
                "
                class="space-y-2 border-t pt-4"
            >
                <h3 class="font-medium">Stored performance</h3>
                <p
                    v-for="(sample, side) in research.season_evidence"
                    :key="side"
                    class="text-sm"
                >
                    <strong class="font-medium">{{
                        side === 'home' ? 'Home team' : 'Away team'
                    }}</strong>
                    ·
                    <template
                        v-if="
                            typeof sample?.wins === 'number' &&
                            typeof sample?.losses === 'number'
                        "
                        >{{ sample.wins }}–{{ sample.losses
                        }}<template v-if="sample.ties"
                            >–{{ sample.ties }}</template
                        ></template
                    >
                    <template v-else>Record unavailable</template>
                    in
                    {{ sample?.sample_games ?? sample?.games }} completed games
                    <template
                        v-if="
                            sample?.average_margin !== null &&
                            sample?.average_margin !== undefined
                        "
                    >
                        · {{ sample.average_margin > 0 ? '+' : ''
                        }}{{ sample.average_margin }} average scoring
                        margin</template
                    >
                </p>
            </section>
            <section
                v-if="research.sources.length"
                class="space-y-2 border-t pt-4"
            >
                <h3 class="font-medium">Sources</h3>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="source in research.sources"
                        :key="source.url"
                        class="wrap-break-word"
                    >
                        <a
                            :href="source.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline underline-offset-2"
                            >{{ source.title || source.url }}</a
                        >
                    </li>
                </ul>
            </section>
            <section v-if="research.review" class="space-y-2 border-t pt-4">
                <h3 class="font-medium">
                    {{
                        reviewLabels[research.review.status] ??
                        'No saved review available'
                    }}
                </h3>
                <p
                    v-if="
                        research.review.status === 'current' &&
                        research.review.summary
                    "
                    class="text-sm leading-relaxed"
                >
                    {{ research.review.summary }}
                </p>
                <p
                    v-if="
                        research.review.status === 'current' &&
                        research.review.context_summary
                    "
                    class="text-sm leading-relaxed"
                >
                    <span class="font-medium">AI interpretation:</span>
                    {{ research.review.context_summary }}
                </p>
                <p
                    v-if="research.review.reviewed_at"
                    class="text-xs text-muted-foreground"
                >
                    Reviewed
                    {{ timestamp(research.review.reviewed_at) }} Central
                </p>
            </section>
        </template>
    </div>
</template>
