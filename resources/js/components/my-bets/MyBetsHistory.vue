<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Trash2, Check, X } from 'lucide-vue-next';
import { useMyBetsContext } from '@/composables/useMyBets';
const {
    bets,
    loading,
    error,
    hasLoaded,
    isBetPending,
    updateBetResult,
    deleteBet,
    formatCurrency,
    formatDate,
    getSportName,
    displaySelection,
    getResultColor,
} = useMyBetsContext();
</script>
<template>
    <Card>
        <CardHeader>
            <CardTitle>Bet History</CardTitle>
            <CardDescription>
                All your tracked bets and their results
            </CardDescription>
        </CardHeader>
        <CardContent>
            <div v-if="loading && !hasLoaded" class="py-12 text-center">
                <p class="text-muted-foreground">Loading...</p>
            </div>

            <div
                v-else-if="!error && bets.data.length === 0"
                class="py-12 text-center"
            >
                <p class="text-muted-foreground">
                    No bets tracked yet. Click "Log New Bet" to get started.
                </p>
            </div>

            <div v-else-if="bets.data.length > 0" class="space-y-4">
                <div
                    v-for="bet in bets.data"
                    :key="bet.id"
                    class="flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-sidebar-accent/30 p-4"
                >
                    <div class="flex flex-1 items-center gap-4">
                        <div class="min-w-16">
                            <div
                                :class="[
                                    'inline-flex rounded-full px-2 py-1 text-xs font-semibold',
                                    getResultColor(bet.result),
                                ]"
                            >
                                {{ bet.result.toUpperCase() }}
                            </div>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold">
                                    {{
                                        bet.prediction_sport
                                            ? getSportName(bet.prediction_sport)
                                            : 'Manual'
                                    }}
                                </span>
                                <span class="text-sm text-muted-foreground"
                                    >•</span
                                >
                                <span class="text-sm font-medium">
                                    {{ displaySelection(bet) }}
                                </span>
                                <span class="text-sm text-muted-foreground"
                                    >•</span
                                >
                                <span class="text-sm text-muted-foreground">
                                    {{
                                        bet.bet_type
                                            .replace('_', ' ')
                                            .toUpperCase()
                                    }}
                                </span>
                                <span class="text-sm text-muted-foreground"
                                    >•</span
                                >
                                <span class="text-sm font-medium">
                                    {{ formatCurrency(bet.bet_amount) }}
                                    @ {{ bet.odds }}
                                </span>
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ formatDate(bet.placed_at) }}
                                <span v-if="bet.notes"> • {{ bet.notes }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <div
                            v-if="bet.profit_loss !== null"
                            :class="[
                                'text-right font-bold',
                                bet.profit_loss >= 0
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-red-600 dark:text-red-400',
                            ]"
                        >
                            {{ bet.profit_loss >= 0 ? '+' : ''
                            }}{{ formatCurrency(bet.profit_loss) }}
                        </div>

                        <div v-if="bet.result === 'pending'" class="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="isBetPending(bet.id)"
                                aria-label="Mark bet won"
                                @click="updateBetResult(bet.id, 'won')"
                                class="h-8 w-8 p-0"
                            >
                                <Check class="h-4 w-4 text-green-600" />
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="isBetPending(bet.id)"
                                aria-label="Mark bet lost"
                                @click="updateBetResult(bet.id, 'lost')"
                                class="h-8 w-8 p-0"
                            >
                                <X class="h-4 w-4 text-red-600" />
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="isBetPending(bet.id)"
                                @click="updateBetResult(bet.id, 'push')"
                                class="h-8"
                            >
                                Push
                            </Button>
                        </div>

                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="isBetPending(bet.id)"
                            aria-label="Delete bet"
                            @click="deleteBet(bet.id)"
                            class="h-8 w-8 p-0 text-red-600"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
