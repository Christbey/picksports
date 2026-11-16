<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';

type Argument = {
    claim: string;
    interpretation: string;
    source_url: string | null;
    scope?: string;
    blocking?: boolean;
};
type Revision = {
    created_at: string;
    brief: {
        eligibility: { status: string };
        supporting: Argument[];
        opposing: Argument[];
        prop_angles: Argument[];
        unresolved: Argument[];
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
const sections = computed(() => {
    const brief = latest.value?.brief;
    return [
        { title: 'What supports the spread', entries: brief?.supporting ?? [] },
        { title: 'What could go against it', entries: brief?.opposing ?? [] },
        { title: 'Open questions', entries: brief?.unresolved ?? [] },
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
            <div class="space-y-1 border-b pb-4">
                <p class="text-sm text-muted-foreground">
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
                <p
                    v-if="
                        ['hold', 'pass'].includes(
                            latest.brief.eligibility.status,
                        )
                    "
                    class="text-sm font-medium"
                >
                    No approved spread pick in this assessment.
                </p>
            </div>

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
                    <span
                        v-if="title === 'Open questions'"
                        class="text-muted-foreground"
                    >
                        {{
                            entry.blocking === false
                                ? ' (Assumption'
                                : ' (Unconfirmed'
                        }}{{
                            entry.scope && entry.scope !== 'game'
                                ? ' · ' + entry.scope
                                : ''
                        }}{{ ')' }}
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
