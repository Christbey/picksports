<script setup lang="ts">
import { TrendingDown, TrendingUp } from 'lucide-vue-next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

import {
    formatPropOdds,
    formatPropTimestamp,
} from '@/lib/playerPropPresentation';
import PlayerPropIdentity from './PlayerPropIdentity.vue';
import PlayerPropResearch from './PlayerPropResearch.vue';
import type { Recommendation } from '@/lib/playerPropTypes';
import type { PlayerPropPresentation } from '@/composables/usePlayerPropPresentation';
defineProps<{
    rec: Recommendation;
    sport: string;
    isAdmin: boolean;
    presentation: PlayerPropPresentation;
    expanded: boolean;
}>();
const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <Card
        class="mb-5 inline-block w-full break-inside-avoid overflow-hidden transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
    >
        <PlayerPropIdentity
            :rec="rec"
            :sport="sport"
            :is-admin="isAdmin"
            :presentation="presentation"
        />

        <CardContent class="space-y-4">
            <div
                class="ui-surface-subtle flex items-center justify-between p-3"
            >
                <div class="flex items-center gap-2">
                    <component
                        :is="
                            rec.recommendation === 'Over'
                                ? TrendingUp
                                : TrendingDown
                        "
                        :class="[
                            'h-5 w-5',
                            rec.recommendation === 'Over'
                                ? 'text-green-500'
                                : 'text-red-500',
                        ]"
                    />
                    <div>
                        <p class="font-semibold">
                            {{ rec.recommendation }}
                            {{ rec.line }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ rec.market }}
                        </p>
                    </div>
                </div>
                <Badge variant="outline" class="font-mono">
                    {{ formatPropOdds(rec.odds) }}
                </Badge>
            </div>

            <div class="space-y-1 text-xs text-muted-foreground">
                <p>
                    Sportsbook:
                    {{ rec.bookmaker || 'Unknown' }}
                </p>
                <p>
                    Line updated:
                    {{ formatPropTimestamp(rec.fetched_at, rec.game.timezone) }}
                </p>
                <p
                    v-if="isAdmin"
                    :class="
                        presentation.quoteStatus(rec) === 'stale'
                            ? 'text-amber-700 dark:text-amber-400'
                            : ''
                    "
                >
                    {{
                        presentation.quoteStatus(rec) === 'fresh'
                            ? `Within ${rec.freshness_hours}-hour quote window`
                            : presentation.quoteStatus(rec) === 'stale'
                              ? 'Quote expired — verify the current line and price'
                              : 'Quote freshness unavailable'
                    }}
                </p>
            </div>

            <div class="flex items-center justify-between">
                <Badge
                    :variant="presentation.getResultStatus(rec).variant"
                    :class="presentation.getResultStatus(rec).className"
                >
                    {{ presentation.getResultStatus(rec).label }}
                </Badge>
                <span
                    v-if="
                        rec.actual_value !== null &&
                        rec.actual_value !== undefined
                    "
                    class="text-xs text-muted-foreground"
                >
                    Actual:
                    {{ Number(rec.actual_value).toFixed(1) }}
                </span>
            </div>

            <div class="space-y-1.5">
                <div class="flex justify-between text-xs text-muted-foreground">
                    <span
                        title="Model signal strength, not probability of winning"
                        >Signal Score (not win %)</span
                    >
                    <span>{{ rec.confidence }}/100</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full transition-all"
                        :class="presentation.getConfidenceColor(rec)"
                        :style="{
                            width: `${rec.confidence}%`,
                        }"
                    />
                </div>
            </div>

            <div class="space-y-2">
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs text-muted-foreground"
                    @click="emit('toggle')"
                >
                    {{ expanded ? 'Hide Stats' : 'Show Stats' }}
                </Button>

                <PlayerPropResearch
                    v-if="expanded"
                    :rec="rec"
                    :sport="sport"
                    :is-admin="isAdmin"
                    :presentation="presentation"
                />
            </div>
        </CardContent>
    </Card>
</template>
