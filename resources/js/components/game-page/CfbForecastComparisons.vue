<script setup lang="ts">
import { computed } from 'vue';
import type { CfbComparisonForecasts } from '@/types';

const props = defineProps<{ forecasts?: CfbComparisonForecasts | null }>();
const labels: Record<string, string> = {
    independent: 'Published model',
    opponent_adjusted_total: 'Opponent-adjusted scoring',
    result_rating: 'Results-based model',
    market_half_blend: 'Model + market (50/50)',
    market: 'Stored market',
};
const rows = computed(() => Object.entries(props.forecasts?.models ?? {}));
const line = (value: number | null | undefined) =>
    typeof value !== 'number'
        ? 'Unavailable'
        : value === 0
          ? 'PK'
          : `${value > 0 ? '+' : ''}${value.toFixed(1)}`;
const total = (value: number | null | undefined) =>
    typeof value === 'number' ? value.toFixed(1) : 'Unavailable';
</script>

<template>
    <section v-if="rows.length" class="ui-surface space-y-3 p-4">
        <div>
            <h3 class="font-semibold">Experimental forecast comparisons</h3>
            <p class="text-sm text-muted-foreground">
                These comparisons are being evaluated. They are not approved
                bets. The market blend includes stored sportsbook lines.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="py-2">Forecast</th>
                        <th class="py-2">Home spread</th>
                        <th class="py-2">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="[key, forecast] in rows"
                        :key="key"
                        class="border-b last:border-0"
                    >
                        <td class="py-2">{{ labels[key] ?? key }}</td>
                        <td class="py-2">{{ line(forecast.home_line) }}</td>
                        <td class="py-2">{{ total(forecast.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
