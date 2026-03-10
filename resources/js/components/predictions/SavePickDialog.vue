<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface SavePickOption {
    betType: 'spread' | 'moneyline' | 'total_over' | 'total_under';
    selectionSide: 'home' | 'away' | 'over' | 'under';
    teamLabel: string;
    title: string;
    defaultLine: number | null;
}

interface ExistingBet {
    id: number;
    bet_amount: number;
    odds: string;
    bet_type: SavePickOption['betType'];
    selection_side: SavePickOption['selectionSide'] | null;
    selection_label: string | null;
    line: number | null;
    notes: string | null;
}

interface PublicConsensus {
    summary: string;
    detail: string;
}

const props = defineProps<{
    open: boolean;
    predictionId: number;
    predictionType: string;
    option: SavePickOption | null;
    existingBet?: ExistingBet | null;
    publicConsensus?: PublicConsensus | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'saved'): void;
}>();

const form = ref({
    bet_amount: '',
    odds: '',
    line: '',
    notes: '',
});
const saving = ref(false);
const errorMessage = ref<string | null>(null);

const wagerAmount = computed(() => {
    const amount = Number(form.value.bet_amount);

    return Number.isFinite(amount) && amount > 0 ? amount : null;
});

const americanOdds = computed(() => {
    const value = Number.parseInt(form.value.odds.trim(), 10);

    if (!Number.isFinite(value) || value === 0) {
        return null;
    }

    return value;
});

const potentialProfit = computed(() => {
    if (wagerAmount.value === null || americanOdds.value === null) {
        return null;
    }

    if (americanOdds.value > 0) {
        return Number(((wagerAmount.value * americanOdds.value) / 100).toFixed(2));
    }

    return Number(((wagerAmount.value * 100) / Math.abs(americanOdds.value)).toFixed(2));
});

const totalPayout = computed(() => {
    if (wagerAmount.value === null || potentialProfit.value === null) {
        return null;
    }

    return Number((wagerAmount.value + potentialProfit.value).toFixed(2));
});

const selectionLabel = computed(() => {
    if (!props.option) {
        return '';
    }

    const lineValue = form.value.line || (props.option.defaultLine ?? '');

    if (props.option.betType === 'moneyline') {
        return `${props.option.teamLabel} ML`;
    }

    if (props.option.betType === 'total_over' || props.option.betType === 'total_under') {
        return `${props.option.selectionSide === 'over' ? 'Over' : 'Under'} ${lineValue}`.trim();
    }

    const prefix = props.option.teamLabel;
    const numericLine = typeof lineValue === 'number' ? lineValue : Number(lineValue);
    const formattedLine = Number.isFinite(numericLine) && numericLine > 0 ? `+${lineValue}` : `${lineValue}`;

    return `${prefix} ${formattedLine}`.trim();
});

const isEditing = computed(() => props.existingBet !== null && props.existingBet !== undefined);

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function syncForm(): void {
    if (!props.option) {
        form.value = {
            bet_amount: '',
            odds: '',
            line: '',
            notes: '',
        };
        errorMessage.value = null;

        return;
    }

    form.value = {
        bet_amount: props.existingBet ? String(props.existingBet.bet_amount ?? '') : '',
        odds: props.existingBet?.odds ?? '',
        line: props.existingBet?.line !== null && props.existingBet?.line !== undefined
            ? String(props.existingBet.line)
            : props.option.defaultLine !== null
                ? String(props.option.defaultLine)
                : '',
        notes: props.existingBet?.notes ?? '',
    };
    errorMessage.value = null;
}

watch(
    () => [props.open, props.option, props.existingBet],
    () => {
        if (props.open) {
            syncForm();
        }
    },
    { immediate: true },
);

async function submit(): Promise<void> {
    if (!props.option || saving.value) {
        return;
    }

    saving.value = true;
    errorMessage.value = null;

    const payload = {
        prediction_id: props.predictionId,
        prediction_type: props.predictionType,
        bet_type: props.option.betType,
        selection_side: props.option.selectionSide,
        selection_label: selectionLabel.value,
        line: form.value.line === '' ? null : Number(form.value.line),
        odds: form.value.odds,
        bet_amount: form.value.bet_amount === '' ? null : Number(form.value.bet_amount),
        notes: form.value.notes || null,
    };

    try {
        if (props.existingBet) {
            await axios.put(`/api/v1/user-bets/${props.existingBet.id}`, payload);
        } else {
            await axios.post('/api/v1/user-bets', payload);
        }

        emit('saved');
        emit('update:open', false);
    } catch (error: unknown) {
        errorMessage.value = 'Unable to save this pick right now.';

        if (axios.isAxiosError(error) && error.response?.data?.message) {
            errorMessage.value = error.response.data.message as string;
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent v-if="option" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'Edit tracked pick' : 'Track this pick' }}</DialogTitle>
                <DialogDescription>
                    {{ option.title }}
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="rounded-lg border border-sidebar-border/70 bg-sidebar/20 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Selection</div>
                    <div class="mt-1 text-sm font-semibold text-foreground">{{ selectionLabel || option.title }}</div>
                    <div
                        v-if="publicConsensus"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        <span class="font-medium text-foreground/80">{{ publicConsensus.summary }}</span>
                        <span class="ml-1">{{ publicConsensus.detail }}</span>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="bet-amount">Wager</Label>
                        <Input
                            id="bet-amount"
                            v-model="form.bet_amount"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="25"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="bet-odds">Odds</Label>
                        <Input
                            id="bet-odds"
                            v-model="form.odds"
                            type="text"
                            placeholder="-110"
                        />
                    </div>
                </div>

                <div
                    v-if="option.betType !== 'moneyline'"
                    class="space-y-2"
                >
                    <Label for="bet-line">Line</Label>
                    <Input
                        id="bet-line"
                        v-model="form.line"
                        type="number"
                        step="0.5"
                        placeholder="4.5"
                    />
                </div>

                <div class="space-y-2">
                    <Label for="bet-notes">Notes</Label>
                    <textarea
                        id="bet-notes"
                        v-model="form.notes"
                        rows="3"
                        class="flex min-h-[88px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none transition-[color,box-shadow] placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder="Optional notes"
                    />
                </div>

                <div
                    v-if="wagerAmount !== null || americanOdds !== null"
                    class="grid gap-3 rounded-lg border border-sidebar-border/70 bg-sidebar/20 p-3 sm:grid-cols-3"
                >
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Stake</div>
                        <div class="mt-1 text-sm font-semibold text-foreground">
                            {{ wagerAmount !== null ? formatCurrency(wagerAmount) : '--' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Potential Profit</div>
                        <div class="mt-1 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                            {{ potentialProfit !== null ? formatCurrency(potentialProfit) : '--' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Return</div>
                        <div class="mt-1 text-sm font-semibold text-foreground">
                            {{ totalPayout !== null ? formatCurrency(totalPayout) : '--' }}
                        </div>
                    </div>
                </div>

                <p
                    v-if="errorMessage"
                    class="text-sm text-red-600 dark:text-red-400"
                >
                    {{ errorMessage }}
                </p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" @click="emit('update:open', false)">
                    Cancel
                </Button>
                <Button type="button" :disabled="saving" @click="submit">
                    {{ saving ? 'Saving...' : (isEditing ? 'Update Pick' : 'Save Pick') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
