<script setup lang="ts">
import type { CfbScoringModel } from '@/types/sports';
defineProps<{ model?: CfbScoringModel | null }>();
const points = (value: number | null) =>
    value === null ? 'Unavailable' : value.toFixed(1);
</script>

<template>
    <section
        v-if="model"
        class="rounded-xl border bg-card p-5"
        aria-label="College football scoring model"
    >
        <h2 class="text-lg font-semibold">Team scoring forecast</h2>
        <p class="mt-1 text-sm text-muted-foreground">
            {{
                model.promoted
                    ? 'Released scoring model'
                    : 'Scoring model under evaluation'
            }}
        </p>
        <dl v-if="model.state === 'ready'" class="mt-4 grid grid-cols-3 gap-4">
            <div>
                <dt class="text-sm text-muted-foreground">Away points</dt>
                <dd class="text-xl font-semibold">
                    {{ points(model.away_points) }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Home points</dt>
                <dd class="text-xl font-semibold">
                    {{ points(model.home_points) }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Total points</dt>
                <dd class="text-xl font-semibold">{{ points(model.total) }}</dd>
            </div>
        </dl>
        <p v-else class="mt-3 text-sm">
            This scoring forecast is awaiting verified data.
        </p>
        <p v-if="model.generated_at" class="mt-3 text-sm text-muted-foreground">
            Forecast saved {{ new Date(model.generated_at).toLocaleString() }}
        </p>
        <p class="mt-2 text-sm text-muted-foreground">
            Betting probabilities are awaiting validation.
        </p>
    </section>
</template>
