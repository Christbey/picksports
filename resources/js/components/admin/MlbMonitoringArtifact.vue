<script setup lang="ts">
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const { labelize, shortHash, statusClass, artifact } =
    useMlbMonitoringContext();
</script>
<template>
    <section
        v-if="artifact"
        class="grid gap-4 border-b pb-6 lg:grid-cols-[minmax(0,1.5fr)_repeat(3,minmax(0,1fr))]"
    >
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded px-2 py-1 text-xs font-semibold"
                    :class="statusClass(artifact.status)"
                >
                    {{ labelize(artifact.status) }}
                </span>
                <span class="text-xs text-muted-foreground">
                    {{ labelize(artifact.market_type) }}
                </span>
            </div>
            <div class="mt-3 truncate text-lg font-semibold">
                {{ artifact.model_version }}
            </div>
            <div class="mt-1 text-sm text-muted-foreground">
                {{ labelize(artifact.model_type) }}
            </div>
        </div>
        <div>
            <div class="text-xs text-muted-foreground">Promotion</div>
            <div class="mt-1 text-sm font-medium">
                {{
                    artifact.status === 'promoted'
                        ? 'Active model'
                        : 'Challenger only'
                }}
            </div>
            <div class="mt-1 text-xs text-muted-foreground">
                {{ artifact.promoted_markets.length }} approved market(s)
            </div>
        </div>
        <div>
            <div class="text-xs text-muted-foreground">Training run</div>
            <div class="mt-1 truncate font-mono text-xs">
                {{ shortHash(artifact.training_run_id) }}
            </div>
            <div class="mt-1 text-xs text-muted-foreground">
                {{ artifact.run_type ?? 'Unknown run type' }}
            </div>
        </div>
        <div>
            <div class="text-xs text-muted-foreground">Public output</div>
            <div
                class="mt-1 text-sm font-medium"
                :class="
                    artifact.public_output_changed
                        ? 'text-amber-700 dark:text-amber-300'
                        : 'text-emerald-700 dark:text-emerald-300'
                "
            >
                {{
                    artifact.public_output_changed
                        ? 'Model output active'
                        : 'Baseline unchanged'
                }}
            </div>
        </div>
    </section>
</template>
