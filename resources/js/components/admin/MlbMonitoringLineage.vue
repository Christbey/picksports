<script setup lang="ts">
import { GitCompare, ShieldCheck } from 'lucide-vue-next';
import { TabsContent } from '@/components/ui/tabs';
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const { labelize, shortHash, artifact, lineage } = useMlbMonitoringContext();
</script>
<template>
    <TabsContent value="lineage" class="space-y-5">
        <section class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-md border p-4">
                <div class="flex items-center gap-2">
                    <ShieldCheck class="h-4 w-4 text-emerald-600" />
                    <h2 class="text-sm font-semibold">Active Artifact</h2>
                </div>
                <div
                    v-if="!lineage.active"
                    class="mt-6 text-sm text-muted-foreground"
                >
                    No promoted MLB artifact.
                </div>
                <dl v-else class="mt-4 grid gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">Model</dt>
                        <dd class="font-medium">
                            {{ lineage.active.model_version }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Run / config
                        </dt>
                        <dd class="font-mono text-xs">
                            {{ shortHash(lineage.active.training_run_id) }}
                            /
                            {{ shortHash(lineage.active.config_hash) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Artifact / dataset
                        </dt>
                        <dd class="font-mono text-xs">
                            {{ shortHash(lineage.active.artifact_hash) }}
                            /
                            {{ shortHash(lineage.active.dataset_hash) }}
                        </dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-md border p-4">
                <div class="flex items-center gap-2">
                    <GitCompare class="h-4 w-4 text-amber-600" />
                    <h2 class="text-sm font-semibold">Challenger Artifact</h2>
                </div>
                <div
                    v-if="!lineage.challenger"
                    class="mt-6 text-sm text-muted-foreground"
                >
                    No MLB challenger registered.
                </div>
                <dl v-else class="mt-4 grid gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">Model</dt>
                        <dd class="font-medium">
                            {{ lineage.challenger.model_version }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Status / market
                        </dt>
                        <dd>
                            {{ labelize(lineage.challenger.status) }}
                            ·
                            {{ labelize(lineage.challenger.market_type) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Artifact / dataset
                        </dt>
                        <dd class="font-mono text-xs">
                            {{ shortHash(lineage.challenger.artifact_hash) }}
                            /
                            {{ shortHash(lineage.challenger.dataset_hash) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <section v-if="artifact" class="overflow-hidden rounded-md border">
            <div class="border-b bg-muted/40 px-4 py-3">
                <h2 class="text-sm font-semibold">
                    Selected Artifact Provenance
                </h2>
            </div>
            <dl class="grid gap-x-6 gap-y-4 p-4 text-sm md:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">Artifact ID</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.id }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Training run ID
                    </dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.training_run_id }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Artifact hash</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.artifact_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Dataset hash</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.dataset_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Configuration hash
                    </dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.config_hash ?? 'N/A' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Code version</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ artifact.code_version ?? 'N/A' }}
                    </dd>
                </div>
            </dl>
        </section>
    </TabsContent>
</template>
