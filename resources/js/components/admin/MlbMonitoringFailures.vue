<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next';
import { TabsContent } from '@/components/ui/tabs';
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const { formatDate, labelize, statusClass, inference_failures } =
    useMlbMonitoringContext();
</script>
<template>
    <TabsContent value="failures">
        <section class="overflow-hidden rounded-md border">
            <div class="flex items-center gap-2 border-b bg-muted/40 px-4 py-3">
                <AlertTriangle class="h-4 w-4 text-amber-600" />
                <h2 class="text-sm font-semibold">Recent Inference Failures</h2>
            </div>
            <div
                v-if="inference_failures.length === 0"
                class="px-6 py-12 text-center text-sm text-muted-foreground"
            >
                No failed MLB inference runs recorded.
            </div>
            <div v-else class="divide-y">
                <div
                    v-for="failure in inference_failures"
                    :key="failure.id"
                    class="grid gap-2 px-4 py-4 md:grid-cols-[12rem_12rem_minmax(0,1fr)]"
                >
                    <div>
                        <div class="text-sm font-medium">
                            {{ failure.model_version }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ formatDate(failure.started_at) }}
                        </div>
                    </div>
                    <div>
                        <span
                            class="rounded px-2 py-1 text-xs font-semibold"
                            :class="statusClass(failure.status)"
                        >
                            {{ labelize(failure.status) }}
                        </span>
                    </div>
                    <div
                        class="min-w-0 font-mono text-xs break-words text-red-700 dark:text-red-300"
                    >
                        {{ failure.error }}
                    </div>
                </div>
            </div>
        </section>
    </TabsContent>
</template>
