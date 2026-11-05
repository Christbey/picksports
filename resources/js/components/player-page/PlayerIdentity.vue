<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Skeleton } from '@/components/ui/skeleton';
import type { Player, SportPlayerShellConfig } from '@/types/player-page';
defineProps<{ playerData: Player | null; config: SportPlayerShellConfig }>();
</script>

<template>
    <div v-if="playerData" class="ui-surface flex items-start gap-4 p-4 md:p-5">
        <img
            v-if="playerData.headshot_url"
            :src="playerData.headshot_url"
            :alt="playerData.name"
            class="h-24 w-24 rounded-xl object-cover"
        />
        <div
            v-else
            class="flex h-24 w-24 items-center justify-center rounded-xl bg-muted text-2xl font-bold text-muted-foreground"
        >
            {{ playerData.first_name?.[0] }}{{ playerData.last_name?.[0] }}
        </div>
        <div class="flex-1">
            <h1 class="text-3xl font-semibold tracking-tight">
                {{ playerData.name }}
            </h1>
            <div class="mt-1 flex items-center gap-2 text-muted-foreground">
                <span v-if="playerData.jersey_number" class="font-semibold"
                    >#{{ playerData.jersey_number }}</span
                >
                <span v-if="playerData.jersey_number && playerData.position"
                    >·</span
                >
                <span v-if="playerData.position">{{
                    playerData.position
                }}</span>
            </div>
            <div
                v-if="playerData.team && playerData.team.id != null"
                class="mt-1"
            >
                <Link
                    v-if="config.teamLink"
                    :href="config.teamLink(playerData.team.id)"
                    class="text-sm text-primary hover:underline"
                >
                    {{ playerData.team.name }}
                </Link>
                <span v-else class="text-sm text-muted-foreground">{{
                    playerData.team.name
                }}</span>
            </div>
            <div
                v-if="playerData.height || playerData.weight"
                class="mt-1 text-sm text-muted-foreground"
            >
                <span v-if="playerData.height">{{ playerData.height }}</span>
                <span v-if="playerData.height && playerData.weight"> · </span>
                <span v-if="playerData.weight"
                    >{{ playerData.weight }} lbs</span
                >
            </div>
        </div>
    </div>
    <Skeleton v-else class="ui-surface h-32 w-full" />
</template>
