<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import type { TrainingOperations } from '@/types/training-operations';

const props = defineProps<{
    sport: 'nfl' | 'mlb';
    initial: TrainingOperations;
}>();
const data = ref(props.initial);
const refreshing = ref(false);
const unavailable = ref(false);
let interval: ReturnType<typeof setInterval> | undefined;
let controller: AbortController | undefined;
let mounted = false;
const label = (value: string | null) =>
    (value ?? 'Unavailable').replaceAll('_', ' ');
const bytes = (value: number | null | undefined) =>
    value === null || value === undefined
        ? 'Unavailable'
        : `${(value / 1024 ** 3).toFixed(2)} GiB`;
const time = (value: string | null) =>
    value ? new Date(value).toLocaleString() : 'Not reported';

async function refresh() {
    if (refreshing.value || document.hidden) return;
    refreshing.value = true;
    controller = new AbortController();
    const timeout = setTimeout(() => controller?.abort(), 10000);
    try {
        const response = await fetch(
            `/admin/training-operations/${props.sport}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            },
        );
        if (!response.ok) throw new Error('Monitoring unavailable');
        const next = (await response.json()) as TrainingOperations;
        if (mounted) {
            data.value = next;
            unavailable.value = false;
        }
    } catch {
        if (mounted) unavailable.value = true;
    } finally {
        clearTimeout(timeout);
        if (mounted) refreshing.value = false;
    }
}
const visible = () => {
    if (!document.hidden) void refresh();
};
onMounted(() => {
    mounted = true;
    interval = setInterval(() => {
        void refresh();
    }, 30000);
    document.addEventListener('visibilitychange', visible);
});
onUnmounted(() => {
    mounted = false;
    clearInterval(interval);
    controller?.abort();
    document.removeEventListener('visibilitychange', visible);
});
</script>

<template>
    <section
        class="min-w-0 space-y-4 rounded-xl border p-4 sm:p-6"
        aria-labelledby="training-operations-title"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2
                    id="training-operations-title"
                    class="text-lg font-semibold"
                >
                    Training operations
                </h2>
                <p class="text-sm text-muted-foreground">
                    Worker health, recent jobs, logs and registered results.
                </p>
            </div>
            <button
                class="rounded-md border px-3 py-2 text-sm disabled:opacity-50"
                :disabled="refreshing"
                @click="refresh"
            >
                {{ refreshing ? 'Refreshing…' : 'Refresh training status' }}
            </button>
        </div>
        <p v-if="!data.enabled" class="text-sm text-muted-foreground">
            Training monitoring is disabled.
        </p>
        <template v-else>
            <p
                v-if="unavailable"
                role="status"
                class="text-sm text-amber-700 dark:text-amber-400"
            >
                Current status unavailable. Showing the last received snapshot;
                worker reachability is unknown.
            </p>
            <p class="text-xs text-muted-foreground">
                Snapshot: {{ time(data.as_of) }} · Refreshes every 30 seconds
                while visible.
            </p>
            <p class="text-xs text-muted-foreground">
                A missed heartbeat means the worker is not reporting; it does
                not prove the Mac is off. Memory is the training process RSS,
                not system memory pressure.
            </p>
            <div class="grid gap-3 lg:grid-cols-2">
                <article
                    v-for="worker in data.workers"
                    :key="worker.id"
                    class="min-w-0 space-y-2 rounded-lg border p-4"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <h3 class="font-medium">{{ worker.name }}</h3>
                        <span class="text-sm"
                            >{{
                                unavailable
                                    ? 'Status unknown'
                                    : label(worker.connectivity)
                            }}
                            · {{ label(worker.credential_status) }}</span
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Last heartbeat: {{ time(worker.last_seen_at) }}
                    </p>
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Free disk</dt>
                            <dd>
                                {{ bytes(worker.metrics?.disk_free_bytes) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Training process RSS
                            </dt>
                            <dd>
                                {{ bytes(worker.metrics?.training_rss_bytes) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                CPU load (1 minute)
                            </dt>
                            <dd>
                                {{
                                    worker.metrics?.load_one?.toFixed(2) ??
                                    'Unavailable'
                                }}
                                / {{ worker.metrics?.cpu_count ?? '?' }} CPUs
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Worker version
                            </dt>
                            <dd>
                                {{ worker.metrics?.version ?? 'Not reported' }}
                            </dd>
                        </div>
                    </dl>
                </article>
                <p
                    v-if="!data.workers.length"
                    class="text-sm text-muted-foreground"
                >
                    No workers are scoped to this sport.
                </p>
            </div>
            <div class="space-y-3">
                <h3 class="font-medium">Recent training jobs</h3>
                <p
                    v-if="!data.jobs.length"
                    class="text-sm text-muted-foreground"
                >
                    No weekly training cycles recorded.
                </p>
                <details
                    v-for="job in data.jobs"
                    :key="job.id"
                    class="min-w-0 rounded-lg border p-4"
                >
                    <summary class="cursor-pointer text-sm break-words">
                        <strong class="capitalize">{{
                            label(job.status)
                        }}</strong>
                        · {{ label(job.stage) }} · {{ time(job.started_at) }}
                        <span class="block pt-1 text-xs text-muted-foreground"
                            >{{
                                job.worker_id
                                    ? (data.workers.find(
                                          (worker) =>
                                              worker.id === job.worker_id,
                                      )?.name ?? 'Assigned remote worker')
                                    : 'Application host'
                            }}
                            · {{ job.id }}</span
                        >
                    </summary>
                    <div class="mt-4 space-y-3 text-sm">
                        <p
                            v-if="job.status === 'lease_expired'"
                            class="text-amber-700 dark:text-amber-400"
                        >
                            Lease expired; recovery requires a new owned
                            attempt. Expiry does not prove the process stopped.
                        </p>
                        <p>
                            Received {{ job.files_received }} artifact files ({{
                                bytes(job.bytes_received)
                            }}). Last progress: {{ time(job.progress_at) }}.
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Training and evaluation have no measured percentage.
                            Heartbeats do not renew attempt leases.
                        </p>
                        <ul class="space-y-1">
                            <li
                                v-for="attempt in job.attempts"
                                :key="attempt.number"
                            >
                                Attempt {{ attempt.number }}:
                                {{ label(attempt.status) }} · lease
                                {{ time(attempt.lease_expires_at) }}
                            </li>
                        </ul>
                        <div v-if="job.result" class="rounded-md bg-muted p-3">
                            <p>
                                {{ job.result.model_version }} ·
                                {{ label(job.result.status) }}
                            </p>
                            <p>
                                Offline eligible markets:
                                {{
                                    job.result.eligible_markets.join(', ') ||
                                    'None recorded'
                                }}
                            </p>
                            <a
                                class="underline underline-offset-4"
                                :href="job.result.evaluation_url"
                                >View evaluation and model lineage</a
                            >
                        </div>
                        <div>
                            <h4 class="mb-2 font-medium">
                                Recent log output · last 8 KiB
                            </h4>
                            <pre
                                class="max-h-64 overflow-auto rounded-md bg-muted p-3 text-xs break-all whitespace-pre-wrap"
                                >{{
                                    job.log_tail ||
                                    'No output reported. Full logs remain on the worker.'
                                }}</pre
                            >
                        </div>
                    </div>
                </details>
            </div>
        </template>
    </section>
</template>
