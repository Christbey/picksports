<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';

type Argument = {
    claim: string;
    interpretation: string;
    source_url: string | null;
    scope?: string;
    blocking?: boolean;
    reason_code?: string;
    concern_type?: string;
    evidence_kind?: string;
    evidence_status?: string;
};
type RecentForm = {
    games: number;
    wins: number;
    losses: number;
    ties: number;
    points_per_game: number;
    points_allowed_per_game: number;
};
type Revision = {
    created_at: string;
    brief: {
        supporting: Argument[];
        opposing: Argument[];
        prop_angles: Argument[];
        unresolved: Argument[];
        season_evidence?: {
            teams: Record<
                string,
                { team: string | null; last_five: RecentForm }
            >;
        } | null;
    };
};
const props = defineProps<{
    gameId: number;
    gameStatus?: string;
}>();
const api = useApiV2Client();
const revisions = ref<Revision[]>([]);
const error = ref('');
const loading = ref(true);
const latest = computed(() => revisions.value[0]);
const recentForm = computed(() =>
    Object.entries(latest.value?.brief.season_evidence?.teams ?? {}).filter(
        ([, { team, last_five }]) => team && last_five?.games,
    ),
);
const sections = computed(() => {
    const brief = latest.value?.brief;
    return [
        { title: 'What supports the spread', entries: brief?.supporting ?? [] },
        { title: 'What could go against it', entries: brief?.opposing ?? [] },
        {
            title: 'Lineup risks',
            entries: (brief?.unresolved ?? []).filter(
                (entry) =>
                    entry.blocking === true &&
                    (entry.scope === 'game' || entry.scope === 'spread') &&
                    (entry.reason_code === 'material_availability' ||
                        entry.reason_code === 'model_input_conflict') &&
                    [
                        'injury',
                        'starter_change',
                        'competition',
                        'rotation',
                    ].includes(entry.concern_type ?? '') &&
                    entry.evidence_kind === 'document' &&
                    entry.evidence_status === 'reference_verified' &&
                    Boolean(sourceUrl(entry.source_url)),
            ),
        },
        { title: 'Player prop context', entries: brief?.prop_angles ?? [] },
    ].filter(({ entries }) => entries.length);
});
function sourceUrl(value: string | null): URL | undefined {
    if (!value || !/^https:\/\//i.test(value)) return;
    try {
        const url = new URL(value);
        if (!url.username && !url.password) return url;
    } catch {
        return;
    }
}

const references = computed(() => {
    const sources = new Map<
        string,
        { href: string; site: string; number: number }
    >();
    for (const { entries } of sections.value) {
        for (const entry of entries) {
            const url = sourceUrl(entry.source_url);
            if (url && !sources.has(url.href)) {
                sources.set(url.href, {
                    href: url.href,
                    site: url.hostname.replace(/^www\./, ''),
                    number: sources.size + 1,
                });
            }
        }
    }
    return sources;
});
const citation = (entry: Argument) =>
    references.value.get(sourceUrl(entry.source_url)?.href ?? '');
const savedAt = (value: string) =>
    new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

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
        aria-label="Matchup research"
        class="min-w-0 space-y-5 rounded-xl border bg-card p-4 text-card-foreground sm:p-6"
    >
        <h2 class="text-lg font-semibold">Matchup research</h2>
        <p v-if="loading" class="text-sm text-muted-foreground" role="status">
            Loading research…
        </p>
        <p v-else-if="error" class="text-sm text-muted-foreground">
            {{ error }}
        </p>
        <p v-else-if="!latest" class="text-sm text-muted-foreground">
            No saved research is available yet.
        </p>
        <template v-else>
            <p class="border-b pb-4 text-sm text-muted-foreground">
                {{
                    gameStatus === 'STATUS_FINAL'
                        ? 'Archived pregame research'
                        : 'Pregame research'
                }}
                · Saved
                <time :datetime="latest.created_at">{{
                    savedAt(latest.created_at)
                }}</time>
            </p>

            <div
                v-for="{ title, entries } in sections"
                :key="title"
                class="space-y-3"
            >
                <h3 class="font-semibold">{{ title }}</h3>
                <p
                    v-for="(entry, index) in entries"
                    :key="index"
                    class="text-sm leading-relaxed wrap-break-word"
                >
                    <strong class="font-medium">{{ entry.claim }}</strong>
                    <span
                        v-if="
                            entry.interpretation &&
                            entry.interpretation !== entry.claim
                        "
                    >
                        {{ ' ' + entry.interpretation }}
                    </span>
                    <template v-if="citation(entry)">
                        {{ ' '
                        }}<a
                            :href="citation(entry)?.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline underline-offset-2"
                            >[{{ citation(entry)?.number }}]
                            {{ citation(entry)?.site }}</a
                        >
                    </template>
                </p>
            </div>
            <p
                v-if="
                    !latest.brief.supporting?.length &&
                    !latest.brief.opposing?.length
                "
                class="text-sm text-muted-foreground"
            >
                No supporting or opposing evidence was saved with this
                assessment.
            </p>

            <div v-if="recentForm.length" class="space-y-2 border-t pt-4">
                <h3 class="font-semibold">Recent form</h3>
                <p class="text-sm text-muted-foreground">
                    PickSports results from up to five completed regular-season
                    games before this matchup.
                </p>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="[
                            side,
                            { team, last_five: sample },
                        ] in recentForm"
                        :key="side"
                    >
                        <strong class="font-medium">{{ team }}</strong>
                        ·
                        {{
                            [
                                sample.wins,
                                sample.losses,
                                ...(sample.ties ? [sample.ties] : []),
                            ].join('–')
                        }}
                        in {{ sample.games }}
                        {{ sample.games === 1 ? 'game' : 'games' }} ·
                        {{ sample.points_per_game }} scored and
                        {{ sample.points_allowed_per_game }} allowed per game
                    </li>
                </ul>
            </div>

            <div v-if="references.size" class="space-y-2 border-t pt-4">
                <h3 class="font-semibold">References</h3>
                <ol class="list-decimal space-y-2 pl-5 text-sm">
                    <li
                        v-for="source in references.values()"
                        :key="source.href"
                        class="wrap-break-word"
                    >
                        <a
                            :href="source.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline underline-offset-2"
                        >
                            {{ source.href.replace(/^https:\/\//, '') }}
                        </a>
                    </li>
                </ol>
            </div>
        </template>
    </section>
</template>
