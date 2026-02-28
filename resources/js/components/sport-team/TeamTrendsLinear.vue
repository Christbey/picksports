<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

defineProps<{
    loading: boolean;
    allTrendCategories: string[];
    trendsData: Record<string, string[]> | null;
    lockedTrends: Record<string, string> | null;
    trendLabel: (key: string) => string;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Team Trends</CardTitle>
        </CardHeader>
        <CardContent>
            <div v-if="loading" class="space-y-4">
                <Skeleton class="h-24 w-full" />
                <Skeleton class="h-24 w-full" />
            </div>

            <div v-else-if="allTrendCategories.length > 0" class="space-y-6">
                <div v-for="category in allTrendCategories" :key="category" class="border-b pb-4 last:border-b-0">
                    <h4 class="font-medium mb-3">{{ trendLabel(category) }}</h4>

                    <div v-if="lockedTrends && lockedTrends[category]" class="text-center py-4 bg-muted/50 rounded-lg">
                        <div class="text-sm text-muted-foreground">
                            Upgrade to {{ lockedTrends[category].charAt(0).toUpperCase() + lockedTrends[category].slice(1) }} to unlock this trend
                        </div>
                    </div>

                    <ul v-else-if="trendsData?.[category]?.length" class="space-y-1 text-sm">
                        <li v-for="(trend, idx) in trendsData[category]" :key="idx" class="flex items-start gap-2">
                            <span class="text-muted-foreground">&bull;</span>
                            <span>{{ trend }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">No trends available</p>
                </div>
            </div>

            <div v-else class="text-center py-8 text-muted-foreground">
                No trends available for this team
            </div>
        </CardContent>
    </Card>
</template>
