<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { Download, Plus, Trash2, Check, X } from 'lucide-vue-next';
import { ref, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Bet {
    id: number;
    prediction_id: number;
    prediction_type: string;
    bet_amount: number;
    odds: string;
    bet_type: string;
    result: 'pending' | 'won' | 'lost' | 'push';
    profit_loss: number | null;
    notes: string | null;
    placed_at: string;
    settled_at: string | null;
}

interface Statistics {
    total_bets: number;
    total_wagered: number;
    wins: number;
    losses: number;
    pushes: number;
    win_rate: number;
    total_profit: number;
    roi: number;
}

interface BetsData {
    data: Bet[];
    links: any[];
    current_page: number;
    last_page: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'My Bets',
        href: '/my-bets',
    },
];

const bets = ref<BetsData>({ data: [], links: [], current_page: 1, last_page: 1 });
const statistics = ref<Statistics>({
    total_bets: 0,
    total_wagered: 0,
    wins: 0,
    losses: 0,
    pushes: 0,
    win_rate: 0,
    total_profit: 0,
    roi: 0,
});
const loading = ref(true);
const showAddBetForm = ref(false);
const form = ref({
    prediction_id: '',
    prediction_type: 'App\\Models\\NBA\\Prediction',
    bet_amount: '',
    odds: '',
    bet_type: 'spread',
    notes: '',
});

async function fetchBets() {
    loading.value = true;
    try {
        const response = await axios.get('/api/v1/user-bets');
        bets.value = response.data.bets;
        statistics.value = response.data.statistics;
    } catch (error) {
        console.error('Failed to fetch bets:', error);
    } finally {
        loading.value = false;
    }
}

async function submitBet() {
    try {
        await axios.post('/api/v1/user-bets', form.value);
        showAddBetForm.value = false;
        form.value = {
            prediction_id: '',
            prediction_type: 'App\\Models\\NBA\\Prediction',
            bet_amount: '',
            odds: '',
            bet_type: 'spread',
            notes: '',
        };
        await fetchBets();
    } catch (error) {
        console.error('Failed to submit bet:', error);
    }
}

async function updateBetResult(betId: number, result: 'won' | 'lost' | 'push') {
    try {
        await axios.put(`/api/v1/user-bets/${betId}`, { result });
        await fetchBets();
    } catch (error) {
        console.error('Failed to update bet:', error);
    }
}

async function deleteBet(betId: number) {
    if (confirm('Are you sure you want to delete this bet?')) {
        try {
            await axios.delete(`/api/v1/user-bets/${betId}`);
            await fetchBets();
        } catch (error) {
            console.error('Failed to delete bet:', error);
        }
    }
}

function exportBets() {
    window.location.href = '/api/v1/user-bets/export';
}

function formatCurrency(amount: number) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function formatDate(date: string) {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function getSportName(predictionType: string) {
    return predictionType.split('\\').slice(-2, -1)[0];
}

function getResultColor(result: string) {
    const colors = {
        won: 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/50',
        lost: 'text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900/50',
        push: 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-900/50',
        pending: 'text-yellow-600 dark:text-yellow-400 bg-yellow-100 dark:bg-yellow-900/50',
    };
    return colors[result as keyof typeof colors] || colors.pending;
}

onMounted(() => {
    fetchBets();
});
</script>

<template>
    <Head title="My Bets" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
            <!-- Statistics Cards -->
            <div class="grid auto-rows-min gap-4 md:grid-cols-4">
                <!-- Total Bets -->
                <div
                    class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 bg-gradient-to-br from-blue-500 to-blue-700 p-6 dark:border-sidebar-border"
                >
                    <div class="flex h-full flex-col justify-between">
                        <div class="text-sm font-medium text-white/80">Total Bets</div>
                        <div class="text-5xl font-bold text-white">
                            {{ statistics.total_bets }}
                        </div>
                        <div class="text-xs text-white/60">All time</div>
                    </div>
                </div>

                <!-- Win Rate -->
                <div
                    class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 bg-gradient-to-br from-green-500 to-green-700 p-6 dark:border-sidebar-border"
                >
                    <div class="flex h-full flex-col justify-between">
                        <div class="text-sm font-medium text-white/80">Win Rate</div>
                        <div class="text-5xl font-bold text-white">
                            {{ statistics.win_rate }}%
                        </div>
                        <div class="text-xs text-white/60">
                            {{ statistics.wins }}W - {{ statistics.losses }}L
                        </div>
                    </div>
                </div>

                <!-- Total Profit -->
                <div
                    :class="[
                        'relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border',
                        statistics.total_profit >= 0
                            ? 'bg-gradient-to-br from-emerald-500 to-emerald-700'
                            : 'bg-gradient-to-br from-red-500 to-red-700',
                    ]"
                >
                    <div class="flex h-full flex-col justify-between">
                        <div class="text-sm font-medium text-white/80">Total Profit</div>
                        <div class="text-4xl font-bold text-white">
                            {{ formatCurrency(statistics.total_profit) }}
                        </div>
                        <div class="text-xs text-white/60">Net return</div>
                    </div>
                </div>

                <!-- ROI -->
                <div
                    :class="[
                        'relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border',
                        statistics.roi >= 0
                            ? 'bg-gradient-to-br from-purple-500 to-purple-700'
                            : 'bg-gradient-to-br from-orange-500 to-orange-700',
                    ]"
                >
                    <div class="flex h-full flex-col justify-between">
                        <div class="text-sm font-medium text-white/80">ROI</div>
                        <div class="text-5xl font-bold text-white">
                            {{ statistics.roi }}%
                        </div>
                        <div class="text-xs text-white/60">
                            {{ formatCurrency(statistics.total_wagered) }} wagered
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions Bar -->
            <div class="flex items-center justify-between gap-4">
                <Button @click="showAddBetForm = !showAddBetForm" class="gap-2">
                    <Plus class="h-4 w-4" />
                    Log New Bet
                </Button>
                <Button variant="outline" @click="exportBets" class="gap-2">
                    <Download class="h-4 w-4" />
                    Export to CSV
                </Button>
            </div>

            <!-- Add Bet Form -->
            <Card v-if="showAddBetForm">
                <CardHeader>
                    <CardTitle>Log a New Bet</CardTitle>
                    <CardDescription>
                        Track your bet to calculate your ROI and performance
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submitBet" class="grid gap-4 md:grid-cols-2">
                        <div>
                            <Label for="prediction-type">Sport</Label>
                            <select
                                id="prediction-type"
                                v-model="form.prediction_type"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                <option value="App\Models\NBA\Prediction">NBA</option>
                                <option value="App\Models\NFL\Prediction">NFL</option>
                                <option value="App\Models\CBB\Prediction">CBB</option>
                                <option value="App\Models\WCBB\Prediction">WCBB</option>
                                <option value="App\Models\MLB\Prediction">MLB</option>
                                <option value="App\Models\CFB\Prediction">CFB</option>
                                <option value="App\Models\WNBA\Prediction">WNBA</option>
                            </select>
                        </div>

                        <div>
                            <Label for="bet-type">Bet Type</Label>
                            <select
                                id="bet-type"
                                v-model="form.bet_type"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                <option value="spread">Spread</option>
                                <option value="moneyline">Moneyline</option>
                                <option value="total_over">Total Over</option>
                                <option value="total_under">Total Under</option>
                            </select>
                        </div>

                        <div>
                            <Label for="bet-amount">Bet Amount</Label>
                            <Input
                                id="bet-amount"
                                v-model="form.bet_amount"
                                type="number"
                                step="0.01"
                                placeholder="100.00"
                                required
                            />
                        </div>

                        <div>
                            <Label for="odds">Odds</Label>
                            <Input
                                id="odds"
                                v-model="form.odds"
                                placeholder="-110"
                                required
                            />
                        </div>

                        <div class="md:col-span-2">
                            <Label for="notes">Notes (Optional)</Label>
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                placeholder="Add any notes about this bet..."
                                rows="3"
                                class="flex min-h-[60px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            />
                        </div>

                        <div class="flex gap-2 md:col-span-2">
                            <Button type="submit">Save Bet</Button>
                            <Button
                                type="button"
                                variant="outline"
                                @click="showAddBetForm = false"
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Bets List -->
            <Card>
                <CardHeader>
                    <CardTitle>Bet History</CardTitle>
                    <CardDescription>
                        All your tracked bets and their results
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="loading" class="py-12 text-center">
                        <p class="text-muted-foreground">Loading...</p>
                    </div>

                    <div v-else-if="bets.data.length === 0" class="py-12 text-center">
                        <p class="text-muted-foreground">
                            No bets tracked yet. Click "Log New Bet" to get started.
                        </p>
                    </div>

                    <div v-else class="space-y-4">
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
                                            {{ getSportName(bet.prediction_type) }}
                                        </span>
                                        <span class="text-sm text-muted-foreground">•</span>
                                        <span class="text-sm text-muted-foreground">
                                            {{ bet.bet_type.replace('_', ' ').toUpperCase() }}
                                        </span>
                                        <span class="text-sm text-muted-foreground">•</span>
                                        <span class="text-sm font-medium">
                                            {{ formatCurrency(bet.bet_amount) }} @ {{ bet.odds }}
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
                                    {{ bet.profit_loss >= 0 ? '+' : '' }}{{ formatCurrency(bet.profit_loss) }}
                                </div>

                                <div v-if="bet.result === 'pending'" class="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="updateBetResult(bet.id, 'won')"
                                        class="h-8 w-8 p-0"
                                    >
                                        <Check class="h-4 w-4 text-green-600" />
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="updateBetResult(bet.id, 'lost')"
                                        class="h-8 w-8 p-0"
                                    >
                                        <X class="h-4 w-4 text-red-600" />
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="updateBetResult(bet.id, 'push')"
                                        class="h-8"
                                    >
                                        Push
                                    </Button>
                                </div>

                                <Button
                                    size="sm"
                                    variant="ghost"
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
        </div>
    </AppLayout>
</template>
