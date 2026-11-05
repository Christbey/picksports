<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';

import { CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import type { Recommendation } from '@/lib/playerPropTypes';
import type { PlayerPropPresentation } from '@/composables/usePlayerPropPresentation';
defineProps<{
    rec: Recommendation;
    sport: string;
    isAdmin: boolean;
    presentation: PlayerPropPresentation;
}>();
</script>

<template>
    <CardHeader>
        <div class="flex items-start gap-3">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <template v-if="rec.player?.url">
                    <Link :href="rec.player.url" class="shrink-0">
                        <Avatar
                            class="h-12 w-12 border-2 border-border transition-colors hover:border-primary"
                        >
                            <AvatarImage
                                v-if="rec.player.headshot"
                                :src="rec.player.headshot"
                                :alt="rec.player.name"
                                loading="lazy"
                                decoding="async"
                                class="object-cover"
                            />
                            <AvatarFallback>{{
                                presentation.getInitials(
                                    rec.player.name || 'Unknown',
                                )
                            }}</AvatarFallback>
                        </Avatar>
                    </Link>
                </template>
                <template v-else>
                    <Avatar class="h-12 w-12 border-2 border-border">
                        <AvatarImage
                            v-if="rec.player.headshot"
                            :src="rec.player.headshot"
                            :alt="rec.player.name"
                            loading="lazy"
                            decoding="async"
                            class="object-cover"
                        />
                        <AvatarFallback>{{
                            presentation.getInitials(
                                rec.player.name || 'Unknown',
                            )
                        }}</AvatarFallback>
                    </Avatar>
                </template>

                <div class="min-w-0 flex-1 space-y-1">
                    <template v-if="rec.player?.url">
                        <Link :href="rec.player.url" class="hover:underline">
                            <CardTitle
                                class="truncate text-lg tracking-tight"
                                >{{ rec.player.name }}</CardTitle
                            >
                        </Link>
                    </template>
                    <template v-else>
                        <CardTitle class="truncate text-lg tracking-tight">{{
                            rec.player.name
                        }}</CardTitle>
                    </template>

                    <div class="flex flex-wrap items-center gap-2">
                        <CardDescription
                            v-if="rec.player.position && rec.player.team"
                            class="shrink-0"
                        >
                            {{ rec.player.position }} •
                            {{ rec.player.team }}
                        </CardDescription>
                        <Badge
                            :variant="presentation.getSignalBandVariant(rec)"
                            class="max-w-full shrink text-[10px]"
                        >
                            {{ presentation.getSignalBand(rec) }}
                        </Badge>
                        <Badge
                            v-if="rec.streak && rec.streak.count >= 2"
                            :variant="
                                rec.streak.status === 'hot'
                                    ? 'default'
                                    : 'secondary'
                            "
                            class="shrink-0 text-xs"
                        >
                            {{ rec.streak.status === 'hot' ? '🔥' : '❄️' }}
                            {{ rec.streak.count }}
                        </Badge>
                    </div>
                    <CardDescription class="truncate text-xs">
                        {{ rec.game?.away_team }} @
                        {{ rec.game?.home_team }}
                    </CardDescription>
                    <CardDescription
                        v-if="rec.game?.kickoff_label"
                        class="text-xs"
                    >
                        <time :datetime="rec.game.starts_at ?? undefined">
                            {{ rec.game.kickoff_label }}
                        </time>
                    </CardDescription>
                </div>
            </div>
        </div>
    </CardHeader>
</template>
