<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

interface Rating {
    team_id: number;
    team: string | null;
    offense: number | null;
    defense: number | null;
    net: number | null;
    custom_rating_calculated_at: null;
}
defineProps<{
    season: number;
    quarantined: boolean;
    validation_status: string;
    source: string;
    ratings: Rating[];
}>();
const format = (value: number | null) =>
    value == null ? '—' : value.toFixed(3);
</script>

<template>
    <Head title="NFL experimental custom EP rating" />
    <AppLayout
        :breadcrumbs="[
            {
                title: 'NFL experimental ratings',
                href: '/admin/nfl-experimental-ratings',
            },
        ]"
    >
        <section class="space-y-5 p-6">
            <Link href="/admin/nfl-model-monitoring" class="text-sm underline"
                >NFL model monitor</Link
            >
            <h1 class="text-2xl font-semibold">
                Experimental custom EP rating · {{ season }}
            </h1>
            <div class="space-y-2 rounded-lg border border-amber-500 p-4">
                <p class="font-semibold">{{ validation_status }}</p>
                <p>
                    {{
                        quarantined
                            ? 'Quarantined: excluded from the custom EPA prediction blend.'
                            : 'Quarantine is disabled. Review configuration before interpreting these values.'
                    }}
                </p>
                <p>
                    {{ source }}. These values are research material, not
                    betting recommendations.
                </p>
                <p>
                    Calculation time is unavailable. Other team metrics may have
                    refreshed since these values were calculated. No automatic
                    refresh runs during quarantine.
                </p>
            </div>
            <p>
                Offense and net: higher is better. Defense: lower is better.
                Teams are listed by identity, not a validated power ranking.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th class="p-3">Team</th>
                            <th class="p-3">Custom offense EP / play</th>
                            <th class="p-3">Custom defense EP / play</th>
                            <th class="p-3">Custom net EP / play</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="rating in ratings"
                            :key="rating.team_id"
                            class="border-t"
                        >
                            <th class="p-3">
                                {{ rating.team ?? `Team ${rating.team_id}` }}
                            </th>
                            <td class="p-3 tabular-nums">
                                {{ format(rating.offense) }}
                            </td>
                            <td class="p-3 tabular-nums">
                                {{ format(rating.defense) }}
                            </td>
                            <td class="p-3 tabular-nums">
                                {{ format(rating.net) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!ratings.length">
                No stored regular-season ratings for this season.
            </p>
        </section>
    </AppLayout>
</template>
