<script setup lang="ts">
import { computed, type UnwrapNestedRefs } from 'vue';
import { storedTimestamp } from '@/lib/dataFreshness';
import type { usePredictionCardRecommendation } from '@/composables/usePredictionCardRecommendation';
import { Globe2, ExternalLink } from 'lucide-vue-next';
const props = defineProps<{
    context: UnwrapNestedRefs<
        ReturnType<typeof usePredictionCardRecommendation>
    >;
}>();
const sources = computed(() =>
    (props.context.externalGameContext()?.sources ?? [])
        .filter((source) => {
            try {
                if (
                    /[\u0000-\u0020\u007f]/.test(source.url) ||
                    /^[a-z][a-z\d+.-]*:\/\/[^/?#]*@/i.test(source.url)
                )
                    return false;
                const url = new URL(source.url);
                return (
                    url.protocol === 'https:' && !url.username && !url.password
                );
            } catch {
                return false;
            }
        })
        .slice(0, 3),
);
const evidenceTiming = computed(() => {
    const context = props.context.externalGameContext();
    const expiry = context?.expires_at ? Date.parse(context.expires_at) : NaN;
    const label = Number.isFinite(expiry)
        ? Date.now() > expiry
            ? 'Expired stored context'
            : 'Stored expiry'
        : 'Expiry unavailable';
    return `Researched ${storedTimestamp(context?.researched_at)} · ${label}${Number.isFinite(expiry) ? ` · ${storedTimestamp(context?.expires_at)}` : ''}`;
});
</script>
<template>
    <section
        v-if="context.hasExternalGameContext()"
        class="rounded-xl border border-violet-500/20 bg-violet-500/[0.04] p-3"
        aria-label="Sourced game context"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div
                class="flex items-center gap-2 text-xs font-semibold tracking-wide text-violet-700 uppercase dark:text-violet-300"
            >
                <Globe2 class="h-3.5 w-3.5" />
                Stored web context
            </div>
            <span
                v-if="context.externalContextConfidenceLabel()"
                class="rounded-full border border-violet-500/20 px-2 py-0.5 text-[11px] font-semibold text-violet-700 dark:text-violet-300"
            >
                {{ context.externalContextConfidenceLabel() }}
            </span>
        </div>
        <p class="mt-2 text-sm text-foreground/90">
            {{ context.externalGameContext()?.summary }}
        </p>
        <p
            v-if="context.externalContextAdjustmentLabel()"
            class="mt-1 text-xs font-medium text-muted-foreground"
        >
            Bounded model adjustment:
            {{ context.externalContextAdjustmentLabel() }}
        </p>
        <p class="mt-1 text-xs text-muted-foreground">
            {{ evidenceTiming }}. Historical evidence; current applicability is
            unverified.
        </p>
        <div v-if="sources.length" class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
            <a
                v-for="source in sources"
                :key="source.url"
                :href="source.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-xs font-medium text-violet-700 hover:underline dark:text-violet-300"
            >
                {{ source.publisher }}
                <ExternalLink class="h-3 w-3" />
            </a>
        </div>
    </section>
</template>
