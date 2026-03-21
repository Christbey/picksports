<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    rosterPlayers: any[];
    playerLink?: (id: number) => any;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Roster</CardTitle>
        </CardHeader>
        <CardContent>
            <div v-if="rosterPlayers.length > 0" class="space-y-2">
                <Link
                    v-for="player in rosterPlayers"
                    :key="player.id"
                    :href="playerLink ? playerLink(player.id) : '#'"
                    class="flex items-center gap-4 rounded-lg p-3 transition-colors hover:bg-muted/50"
                >
                    <img
                        v-if="player.headshot_url"
                        :src="player.headshot_url"
                        :alt="player.name"
                        class="h-10 w-10 rounded-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-muted text-xs font-bold text-muted-foreground"
                    >
                        {{ player.first_name?.[0] }}{{ player.last_name?.[0] }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">
                            {{ player.name }}
                        </div>
                        <div class="text-sm text-muted-foreground">
                            {{ player.position }}
                            <span v-if="player.height">
                                · {{ player.height }}</span
                            >
                        </div>
                    </div>
                    <div
                        v-if="player.jersey_number"
                        class="text-lg font-bold text-muted-foreground"
                    >
                        #{{ player.jersey_number }}
                    </div>
                </Link>
            </div>
            <div v-else class="py-8 text-center text-muted-foreground">
                <p>No roster data available.</p>
            </div>
        </CardContent>
    </Card>
</template>
