<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

import { useNflForecastContext } from '@/composables/useNflForecast';
const { topChampion, playoffField, plusEvCount, formatPct } =
    useNflForecastContext();
</script>
<template>
    <div class="grid gap-4 md:grid-cols-3">
        <Card>
            <CardContent class="pt-5">
                <p class="ui-kicker">Super Bowl Favorite</p>
                <p class="mt-2 text-xl font-bold">
                    {{ topChampion?.team_name ?? '-' }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        formatPct(
                            topChampion?.super_bowl_champion_probability,
                            2,
                        )
                    }}
                    model probability
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p class="ui-kicker">Teams Favored to Qualify</p>
                <p class="mt-2 text-3xl font-bold">
                    {{ playoffField }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Teams at or above 50%
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p class="ui-kicker">Positive Model Differences</p>
                <p class="mt-2 text-3xl font-bold">
                    {{ plusEvCount }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Fresh stored prices only · not approved bets
                </p>
            </CardContent>
        </Card>
    </div>
</template>
