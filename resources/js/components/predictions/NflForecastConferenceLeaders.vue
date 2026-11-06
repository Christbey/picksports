<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { useNflForecastContext } from '@/composables/useNflForecast';
const { conferenceLeaders, formatPct } = useNflForecastContext();
</script>
<template>
    <Card>
        <CardHeader>
            <div class="ui-kicker">Conference View</div>
            <CardTitle>Projected Conference Leaders</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="grid gap-4 md:grid-cols-2">
                <div
                    v-for="row in conferenceLeaders"
                    :key="row.conference"
                    class="border-l-2 border-primary/50 pl-4"
                >
                    <p class="ui-kicker">{{ row.conference }}</p>
                    <p class="mt-1 text-lg font-semibold">
                        {{ row.champion?.team_name }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            formatPct(
                                row.champion?.conference_champion_probability,
                            )
                        }}
                        conference title chance ·
                        {{ row.expectedPlayoffTeams.toFixed(1) }}
                        expected playoff teams
                    </p>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
