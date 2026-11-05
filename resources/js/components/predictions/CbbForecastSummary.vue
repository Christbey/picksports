<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

import { useCbbForecastContext } from '@/composables/useCbbForecast';
const {
    formatPct,
    formatAmericanOdds,
    formatTeam,
    actualFieldForecasts,
    regionalField,
    firstFourTeams,
    favorite,
} = useCbbForecastContext();
</script>
<template>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Tournament Teams
                </p>
                <p class="mt-2 text-3xl font-bold">
                    {{ actualFieldForecasts.length }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Forecast rows matched to the actual field
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Regions
                </p>
                <p class="mt-2 text-3xl font-bold">
                    {{
                        regionalField.filter((group) => group.teams.length > 0)
                            .length
                    }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    East, West, South, Midwest
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    First Four Teams
                </p>
                <p class="mt-2 text-3xl font-bold">
                    {{ firstFourTeams.length }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Play-in teams currently in the field
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Title Favorite
                </p>
                <p class="mt-2 text-lg font-bold">
                    {{
                        favorite
                            ? formatTeam(favorite.team, favorite.team_id)
                            : '-'
                    }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        favorite
                            ? formatPct(favorite.champion_probability, 2)
                            : '-'
                    }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Market
                    {{
                        favorite
                            ? formatAmericanOdds(favorite.market_odds?.price)
                            : '-'
                    }}
                </p>
            </CardContent>
        </Card>
    </div>
</template>
