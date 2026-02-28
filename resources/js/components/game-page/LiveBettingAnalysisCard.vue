<script setup lang="ts">
import BettingAnalysisCard, { type BettingRecommendation, type LivePredictionData } from '@/components/BettingAnalysisCard.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    hasLivePrediction: boolean;
    bettingValue?: BettingRecommendation[];
    livePrediction?: LivePredictionData;
    sportsbookLabel?: string;
}>();
</script>

<template>
    <Card v-if="hasLivePrediction || (bettingValue && bettingValue.length > 0)">
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                <span v-if="hasLivePrediction" class="relative flex h-3 w-3">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex h-3 w-3 rounded-full bg-red-500"></span>
                </span>
                <span>{{ hasLivePrediction ? 'Live Analysis' : 'Betting Value Detected' }}</span>
                <span v-if="!hasLivePrediction && bettingValue?.length" class="text-sm font-normal text-muted-foreground">
                    ({{ sportsbookLabel || 'DraftKings' }})
                </span>
            </CardTitle>
        </CardHeader>
        <CardContent>
            <BettingAnalysisCard :betting-value="bettingValue" :live-prediction="livePrediction" />
        </CardContent>
    </Card>
</template>
