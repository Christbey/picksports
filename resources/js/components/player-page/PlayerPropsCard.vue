<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { PlayerProp } from '@/types/player-page';
import { formatOdds } from '@/lib/playerPagePresentation';
defineProps<{ playerProps: PlayerProp[] }>();
</script>

<template>
    <Card v-if="playerProps.length > 0" class="mb-4">
        <CardHeader>
            <CardTitle>Upcoming Props</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="prop in playerProps"
                    :key="prop.id"
                    class="space-y-2 rounded-lg border p-4"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold">{{
                            prop.market
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >{{ prop.game.away_team }} @
                            {{ prop.game.home_team }}</span
                        >
                    </div>
                    <div class="flex items-center justify-center py-2">
                        <span class="text-2xl font-bold">{{ prop.line }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div class="rounded bg-muted p-2 text-center">
                            <div class="text-xs text-muted-foreground">
                                Over
                            </div>
                            <div class="font-mono font-semibold">
                                {{ formatOdds(prop.over_price) }}
                            </div>
                        </div>
                        <div class="rounded bg-muted p-2 text-center">
                            <div class="text-xs text-muted-foreground">
                                Under
                            </div>
                            <div class="font-mono font-semibold">
                                {{ formatOdds(prop.under_price) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
