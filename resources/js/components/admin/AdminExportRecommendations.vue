<script setup lang="ts">
import { TrendingDown, TrendingUp } from 'lucide-vue-next';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAdminExportsContext } from '@/composables/useAdminExports';
const {
    signalForProp,
    getConfidenceColor,
    getSignalBand,
    formatOdds,
    getInitials,
    buildStatRows,
    exportRecommendation,
    props,
} = useAdminExportsContext();
</script>

<template>
    <div
        v-if="props.recommendations.length === 0"
        class="rounded-xl border border-sidebar-border bg-background p-10 text-center text-muted-foreground dark:bg-sidebar"
    >
        No prop recommendations found for the selected filters.
    </div>

    <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article
            v-for="(rec, index) in props.recommendations"
            :key="rec.id"
            class="rounded-xl border border-sidebar-border bg-background p-4 shadow-sm dark:bg-sidebar"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <Avatar class="h-11 w-11 border-2 border-border">
                        <AvatarImage
                            :src="rec.player.headshot ?? ''"
                            :alt="rec.player.name"
                            class="object-cover"
                        />
                        <AvatarFallback>{{
                            getInitials(rec.player.name || 'Unknown')
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0">
                        <p
                            class="truncate text-base font-semibold text-foreground"
                        >
                            {{ rec.player.name }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ rec.player.position ?? 'N/A' }} •
                            {{ rec.player.team ?? 'N/A' }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ rec.game?.away_team }} @
                            {{ rec.game?.home_team }}
                        </p>
                    </div>
                </div>
                <Badge variant="outline" class="font-mono text-sm">{{
                    formatOdds(rec.odds)
                }}</Badge>
            </div>

            <div
                class="mt-3 flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2"
            >
                <div class="flex items-center gap-2">
                    <component
                        :is="
                            rec.recommendation === 'Over'
                                ? TrendingUp
                                : TrendingDown
                        "
                        :class="[
                            'h-4 w-4',
                            rec.recommendation === 'Over'
                                ? 'text-green-600'
                                : 'text-red-600',
                        ]"
                    />
                    <div>
                        <p class="text-sm font-semibold text-foreground">
                            {{ rec.recommendation }} {{ rec.line }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ rec.market }}
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm font-bold text-foreground">
                        {{ signalForProp(rec) }}%
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        {{ getSignalBand(signalForProp(rec)) }}
                    </p>
                </div>
            </div>

            <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full"
                    :class="getConfidenceColor(signalForProp(rec))"
                    :style="{ width: `${signalForProp(rec)}%` }"
                />
            </div>

            <div class="mt-3 overflow-hidden rounded-lg border border-border">
                <table class="w-full table-fixed">
                    <tbody>
                        <tr
                            v-for="row in buildStatRows(rec)"
                            :key="`${rec.id}-${row.key}`"
                            class="border-b border-border last:border-b-0"
                        >
                            <td
                                class="px-2 py-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ row.label }}
                            </td>
                            <td
                                class="px-2 py-1.5 text-right text-base font-extrabold text-foreground"
                            >
                                {{ row.value }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Button
                class="mt-3 w-full"
                size="sm"
                @click="exportRecommendation(rec, index)"
                >Export PNG</Button
            >
        </article>
    </div>
</template>
