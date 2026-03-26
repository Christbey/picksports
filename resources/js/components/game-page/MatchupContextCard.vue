<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { MatchupContextData } from '@/types';

defineProps<{
    title?: string;
    awayLabel?: string | null;
    homeLabel?: string | null;
    matchupContext: MatchupContextData | null;
}>();
</script>

<template>
    <Card v-if="matchupContext?.rows?.length">
        <CardHeader>
            <CardTitle class="tracking-tight">
                {{ title || 'Matchup Records' }}
            </CardTitle>
        </CardHeader>
        <CardContent>
            <div class="space-y-3 rounded-xl border border-border/70 p-3">
                <div
                    class="grid grid-cols-7 gap-2 border-b pb-2 text-sm font-semibold"
                >
                    <div class="col-span-2 text-right text-foreground/90">
                        {{ awayLabel }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">
                        Split
                    </div>
                    <div class="col-span-2 text-left text-foreground/90">
                        {{ homeLabel }}
                    </div>
                </div>

                <div
                    v-for="row in matchupContext.rows"
                    :key="row.key"
                    class="grid grid-cols-7 items-center gap-2"
                >
                    <div class="col-span-2 text-right font-medium">
                        {{ row.away.display }}
                    </div>
                    <div class="col-span-3 text-center">
                        <div class="text-sm text-foreground/90">
                            {{ row.label }}
                        </div>
                        <div
                            v-if="row.subtitle"
                            class="text-xs text-muted-foreground"
                        >
                            {{ row.subtitle }}
                        </div>
                    </div>
                    <div class="col-span-2 text-left font-medium">
                        {{ row.home.display }}
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
