<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    trendsData: Record<string, string[]> | null;
    lockedTrends: Record<string, string> | null;
    trendLabel: (key: string) => string;
}>();
</script>

<template>
    <div v-if="trendsData && Object.keys(trendsData).length > 0" class="space-y-4">
        <Card v-for="(insights, key) in trendsData" :key="key">
            <CardHeader>
                <CardTitle>{{ trendLabel(key as string) }}</CardTitle>
            </CardHeader>
            <CardContent>
                <ul class="space-y-2">
                    <li v-for="(insight, idx) in insights" :key="idx" class="flex items-start gap-2 text-sm">
                        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                        <span>{{ insight }}</span>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card v-if="lockedTrends && Object.keys(lockedTrends).length > 0" class="opacity-60">
            <CardHeader>
                <CardTitle>More Insights Available</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <div
                        v-for="(tier, key) in lockedTrends"
                        :key="key"
                        class="flex items-center justify-between p-3 border rounded-lg"
                    >
                        <span class="text-sm font-medium">{{ trendLabel(key as string) }}</span>
                        <span class="text-xs text-muted-foreground capitalize">{{ tier }}</span>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>

    <Card v-else>
        <CardContent class="py-8">
            <div class="text-center text-muted-foreground">
                <p>Not enough games played to calculate trends.</p>
            </div>
        </CardContent>
    </Card>
</template>
