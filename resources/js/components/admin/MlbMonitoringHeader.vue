<script setup lang="ts">
import { ChartNoAxesCombined } from 'lucide-vue-next';
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const { selectArtifact, labelize, artifacts, artifact, data_health } =
    useMlbMonitoringContext();
</script>
<template>
    <header
        class="flex flex-col gap-4 border-b pb-5 sm:flex-row sm:items-end sm:justify-between"
    >
        <div class="min-w-0">
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <ChartNoAxesCombined class="h-4 w-4" />
                {{ data_health.season }} in-season model operations
            </div>
            <h1 class="mt-1 text-2xl font-semibold">MLB Model Monitor</h1>
            <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                Artifact promotion, pregame data readiness, live shadow
                evidence, and betting performance by market.
            </p>
        </div>

        <label class="w-full sm:w-80">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">
                Artifact
            </span>
            <select
                class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                :value="artifact?.id ?? ''"
                @change="selectArtifact"
            >
                <option v-if="artifacts.length === 0" value="">
                    No MLB artifacts registered
                </option>
                <option
                    v-for="option in artifacts"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.model_version }} · {{ labelize(option.status) }} ·
                    {{ labelize(option.market_type) }}
                </option>
            </select>
        </label>
    </header>
</template>
