<script setup lang="ts">
import { Ban, CheckCircle2, Database } from 'lucide-vue-next';
import { TabsContent } from '@/components/ui/tabs';
import { formatDate, labelize } from '@/lib/nflMonitoringPresentation';
import { useNflMonitoringContext } from '@/composables/useNflModelMonitoring';
const { artifact } = useNflMonitoringContext();
</script>

<template>
    <TabsContent v-if="artifact" value="lineage" class="space-y-5">
        <section class="rounded-md border bg-card">
            <div class="flex items-center gap-2 border-b px-4 py-3">
                <Database class="h-4 w-4 text-sky-600" />
                <h2 class="text-sm font-semibold">Reproducibility</h2>
            </div>
            <dl class="grid gap-x-8 gap-y-0 px-4 sm:grid-cols-2">
                <div class="border-b py-3">
                    <dt class="text-xs text-muted-foreground">Artifact ID</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.id }}
                    </dd>
                </div>
                <div class="border-b py-3">
                    <dt class="text-xs text-muted-foreground">Training Run</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.training_run_id }}
                    </dd>
                </div>
                <div class="border-b py-3">
                    <dt class="text-xs text-muted-foreground">Config Hash</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.config_hash ?? 'N/A' }}
                    </dd>
                </div>
                <div class="border-b py-3">
                    <dt class="text-xs text-muted-foreground">Code Version</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.code_version ?? 'N/A' }}
                    </dd>
                </div>
                <div class="border-b py-3">
                    <dt class="text-xs text-muted-foreground">Artifact Hash</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.artifact_hash }}
                    </dd>
                </div>
                <div class="border-b py-3">
                    <dt class="text-xs text-muted-foreground">Dataset Hash</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.dataset_hash }}
                    </dd>
                </div>
                <div class="border-b py-3">
                    <dt class="text-xs text-muted-foreground">
                        Evaluation Report Hash
                    </dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.evaluation_report_hash ?? 'N/A' }}
                    </dd>
                </div>
                <div class="border-b py-3">
                    <dt class="text-xs text-muted-foreground">Promoted At</dt>
                    <dd class="mt-1 text-sm">
                        {{ formatDate(artifact.promoted_at) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="rounded-md border bg-card">
            <div class="border-b px-4 py-3">
                <h2 class="text-sm font-semibold">Promotion Gates</h2>
            </div>
            <div class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="(passed, gate) in artifact.promotion_checks"
                    :key="gate"
                    class="flex items-center gap-2 rounded-md border px-3 py-2"
                >
                    <CheckCircle2
                        v-if="passed"
                        class="h-4 w-4 text-emerald-600"
                    />
                    <Ban v-else class="h-4 w-4 text-rose-600" />
                    <span class="text-xs font-medium">
                        {{ labelize(String(gate)) }}
                    </span>
                </div>
            </div>
        </section>
    </TabsContent>
</template>
