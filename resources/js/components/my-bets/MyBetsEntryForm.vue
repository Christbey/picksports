<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useMyBetsContext } from '@/composables/useMyBets';
const { form, showAddBetForm, submitting, formError, submitBet } =
    useMyBetsContext();
</script>
<template>
    <Card v-if="showAddBetForm">
        <CardHeader>
            <CardTitle>Log a New Bet</CardTitle>
            <CardDescription>
                Track your bet to calculate your ROI and performance
            </CardDescription>
        </CardHeader>
        <CardContent>
            <form @submit.prevent="submitBet" class="grid gap-4 md:grid-cols-2">
                <p
                    v-if="formError"
                    role="alert"
                    class="text-sm text-destructive md:col-span-2"
                >
                    {{ formError }}
                </p>
                <div>
                    <Label for="prediction-type">Sport</Label>
                    <select
                        id="prediction-type"
                        v-model="form.prediction_sport"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="nba">NBA</option>
                        <option value="wnba">WNBA</option>
                        <option value="mlb">MLB</option>
                        <option value="nfl">NFL</option>
                        <option value="cbb">CBB</option>
                        <option value="wcbb">WCBB</option>
                        <option value="cfb">CFB</option>
                    </select>
                </div>

                <div>
                    <Label for="bet-type">Bet Type</Label>
                    <select
                        id="bet-type"
                        v-model="form.bet_type"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="spread">Spread</option>
                        <option value="moneyline">Moneyline</option>
                        <option value="total_over">Total Over</option>
                        <option value="total_under">Total Under</option>
                    </select>
                </div>

                <div>
                    <Label for="selection-side">Side</Label>
                    <select
                        id="selection-side"
                        v-model="form.selection_side"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="home">Home</option>
                        <option value="away">Away</option>
                        <option value="over">Over</option>
                        <option value="under">Under</option>
                    </select>
                </div>

                <div>
                    <Label for="selection-label">Selection Label</Label>
                    <Input
                        id="selection-label"
                        v-model="form.selection_label"
                        placeholder="Lakers +4.5"
                    />
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

                <div>
                    <Label for="line">Line</Label>
                    <Input
                        id="line"
                        v-model="form.line"
                        type="number"
                        step="0.5"
                        placeholder="4.5"
                    />
                </div>

                <div class="md:col-span-2">
                    <Label for="notes">Notes (Optional)</Label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        placeholder="Add any notes about this bet..."
                        rows="3"
                        class="flex min-h-[60px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>

                <div class="flex gap-2 md:col-span-2">
                    <Button type="submit" :disabled="submitting"
                        >Save Bet</Button
                    >
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
</template>
