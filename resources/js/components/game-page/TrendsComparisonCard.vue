<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { TeamTrendData } from '@/types';

defineProps<{
    title: string;
    subtitle?: string;
    trendsLoading: boolean;
    allTrendCategories: string[];
    formatCategoryName: (category: string) => string;
    isLockedCategory: (category: string) => boolean;
    formatTierName: (tier: string) => string;
    getRequiredTier: (category: string) => string;
    awayLabel?: string | null;
    homeLabel?: string | null;
    awayTrends?: TeamTrendData | null;
    homeTrends?: TeamTrendData | null;
    emptyText: string;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
            <p v-if="subtitle" class="text-sm text-muted-foreground">{{ subtitle }}</p>
        </CardHeader>
        <CardContent>
            <div v-if="trendsLoading" class="space-y-4">
                <Skeleton class="h-24 w-full" />
                <Skeleton class="h-24 w-full" />
                <Skeleton class="h-24 w-full" />
            </div>

            <div v-else-if="allTrendCategories.length > 0" class="space-y-6">
                <div v-for="category in allTrendCategories" :key="category" class="border-b pb-4 last:border-b-0">
                    <h4 class="font-medium mb-3">{{ formatCategoryName(category) }}</h4>

                    <div v-if="isLockedCategory(category)" class="text-center py-4 bg-muted/50 rounded-lg">
                        <div class="text-sm text-muted-foreground">
                            Upgrade to {{ formatTierName(getRequiredTier(category)) }} to unlock this trend
                        </div>
                    </div>

                    <div v-else class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm font-medium text-muted-foreground mb-2">{{ awayLabel }}</div>
                            <ul v-if="awayTrends?.trends?.[category]?.length" class="space-y-1 text-sm">
                                <li v-for="(trend, idx) in awayTrends.trends[category]" :key="idx" class="flex items-start gap-2">
                                    <span class="text-muted-foreground">•</span>
                                    <span>{{ trend }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">No trends available</p>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-muted-foreground mb-2">{{ homeLabel }}</div>
                            <ul v-if="homeTrends?.trends?.[category]?.length" class="space-y-1 text-sm">
                                <li v-for="(trend, idx) in homeTrends.trends[category]" :key="idx" class="flex items-start gap-2">
                                    <span class="text-muted-foreground">•</span>
                                    <span>{{ trend }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">No trends available</p>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="text-center py-8 text-muted-foreground">
                {{ emptyText }}
            </div>
        </CardContent>
    </Card>
</template>
