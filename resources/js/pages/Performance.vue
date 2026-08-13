<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';

interface OverallStats {
    total_predictions: number;
    winner_accuracy: number;
    avg_spread_error: number | null;
    avg_total_error: number | null;
    win_record: string;
    winner_sample_size: number;
    spread_sample_size: number;
    total_sample_size: number;
}

interface SportStats {
    label: string;
    total_graded: number;
    winner_correct: number;
    winner_accuracy: number;
    avg_spread_error: number | null;
    avg_total_error: number | null;
    win_record: string;
    winner_sample_size: number;
    spread_sample_size: number;
    total_sample_size: number;
}

interface ROIStats {
    total_bets: number;
    total_wins: number;
    total_losses: number;
    total_pushes: number;
    total_staked_units: number;
    total_wagered: number;
    total_profit: number;
    total_profit_units: number;
    roi_percentage: number | null;
    win_percentage: number | null;
    verified: boolean;
    methodology: string;
}

interface RecentPerformance {
    overall: OverallStats;
    by_sport: Record<string, SportStats>;
    roi: ROIStats;
}

interface SeasonSportStats {
    label: string;
    total_graded: number;
    winner_correct: number;
    winner_accuracy: number;
    win_record: string;
    winner_sample_size: number;
    season: number;
}

defineProps<{
    overall: OverallStats;
    by_sport: Record<string, SportStats>;
    recent: RecentPerformance;
    season_to_date: Record<string, SeasonSportStats>;
    roi: ROIStats;
}>();

const getAccuracyColor = (accuracy: number | null) => {
    if (accuracy === null) return 'text-muted-foreground';
    if (accuracy >= 55) return 'text-green-600 dark:text-green-400';
    if (accuracy >= 52) return 'text-blue-600 dark:text-blue-400';
    return 'text-orange-600 dark:text-orange-400';
};

const getROIColor = (roi: number | null) => {
    if (roi === null) return 'text-muted-foreground';
    if (roi > 0) return 'text-green-600 dark:text-green-400';
    if (roi === 0) return 'text-gray-600 dark:text-gray-400';
    return 'text-red-600 dark:text-red-400';
};

const formatPercent = (value: number | null, digits = 1) =>
    value === null ? 'Pending' : `${value.toFixed(digits)}%`;

const formatMetric = (value: number | null, suffix = '') =>
    value === null ? 'Pending' : `${value.toFixed(2)}${suffix}`;

const formatUnits = (value: number) =>
    `${value > 0 ? '+' : ''}${value.toFixed(2)}u`;
</script>

<template>
    <AppLayout>
        <Head title="Performance Dashboard">
            <meta
                head-key="description"
                name="description"
                content="Track PickSports prediction accuracy, ROI, and sport-by-sport performance with transparent historical results."
            />
            <meta
                head-key="og:title"
                property="og:title"
                content="Performance Dashboard"
            />
            <meta
                head-key="og:description"
                property="og:description"
                content="View prediction accuracy, win record, and ROI performance across all supported sports."
            />
            <meta
                head-key="twitter:title"
                name="twitter:title"
                content="Performance Dashboard"
            />
            <meta
                head-key="twitter:description"
                name="twitter:description"
                content="View prediction accuracy, win record, and ROI performance across all supported sports."
            />
        </Head>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <!-- Header -->
                <div>
                    <h1 class="text-3xl font-bold">Performance Dashboard</h1>
                    <p class="mt-2 text-muted-foreground">
                        Auditable model accuracy and verified settled-decision
                        performance
                    </p>
                </div>

                <!-- Overall Stats -->
                <Card>
                    <CardHeader>
                        <CardTitle>Overall Performance</CardTitle>
                        <CardDescription
                            >All-time prediction accuracy across all
                            sports</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Winner Decisions
                                </div>
                                <div class="text-2xl font-bold">
                                    {{ overall.total_predictions }}
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Win Record
                                </div>
                                <div class="text-2xl font-bold">
                                    {{ overall.win_record }}
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Winner Accuracy
                                </div>
                                <div
                                    class="text-2xl font-bold"
                                    :class="
                                        getAccuracyColor(
                                            overall.winner_accuracy,
                                        )
                                    "
                                >
                                    {{ overall.winner_accuracy }}%
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Spread MAE
                                </div>
                                <div class="text-2xl font-bold">
                                    {{
                                        formatMetric(
                                            overall.avg_spread_error,
                                            ' pts',
                                        )
                                    }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{ overall.spread_sample_size }} targets
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Total MAE
                                </div>
                                <div class="text-2xl font-bold">
                                    {{
                                        formatMetric(
                                            overall.avg_total_error,
                                            ' pts',
                                        )
                                    }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{ overall.total_sample_size }} targets
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- ROI Tracking -->
                <Card>
                    <CardHeader>
                        <CardTitle>Return on Investment (ROI)</CardTitle>
                        <CardDescription>
                            Verified results from settled, pregame-safe bet
                            decisions only
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Settled Stake
                                </div>
                                <div class="text-2xl font-bold">
                                    {{ roi.total_staked_units.toFixed(2) }}u
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{ roi.total_bets }} decisions
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Net Profit
                                </div>
                                <div
                                    class="text-2xl font-bold"
                                    :class="getROIColor(roi.roi_percentage)"
                                >
                                    {{ formatUnits(roi.total_profit_units) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    ROI Percentage
                                </div>
                                <div
                                    class="text-2xl font-bold"
                                    :class="getROIColor(roi.roi_percentage)"
                                >
                                    {{
                                        roi.roi_percentage === null
                                            ? 'Pending'
                                            : `${roi.roi_percentage > 0 ? '+' : ''}${roi.roi_percentage.toFixed(2)}%`
                                    }}
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Win Rate
                                </div>
                                <div
                                    class="text-2xl font-bold"
                                    :class="
                                        getAccuracyColor(roi.win_percentage)
                                    "
                                >
                                    {{ formatPercent(roi.win_percentage) }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{ roi.total_wins }}-{{ roi.total_losses
                                    }}<template v-if="roi.total_pushes > 0"
                                        >-{{ roi.total_pushes }}</template
                                    >
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-sm text-muted-foreground">
                            <strong>Method:</strong> One unit is staked per
                            qualifying decision. Profit comes from the recorded
                            settlement price; pushes retain stake but do not
                            enter the win-rate denominator. No qualifying sample
                            is shown as Pending.
                        </div>
                    </CardContent>
                </Card>

                <!-- Recent Performance (Last 30 Days) -->
                <Card>
                    <CardHeader>
                        <CardTitle>Last 30 Days</CardTitle>
                        <CardDescription
                            >Recent prediction performance</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Predictions
                                </div>
                                <div class="text-xl font-bold">
                                    {{ recent.overall.total_predictions }}
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Win Record
                                </div>
                                <div class="text-xl font-bold">
                                    {{ recent.overall.win_record }}
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    Accuracy
                                </div>
                                <div
                                    class="text-xl font-bold"
                                    :class="
                                        getAccuracyColor(
                                            recent.overall.winner_accuracy,
                                        )
                                    "
                                >
                                    {{ recent.overall.winner_accuracy }}%
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-muted-foreground">
                                    30-Day ROI
                                </div>
                                <div
                                    class="text-xl font-bold"
                                    :class="
                                        getROIColor(recent.roi.roi_percentage)
                                    "
                                >
                                    {{
                                        recent.roi.roi_percentage === null
                                            ? 'Pending'
                                            : `${recent.roi.roi_percentage > 0 ? '+' : ''}${recent.roi.roi_percentage.toFixed(2)}%`
                                    }}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Performance by Sport -->
                <Card>
                    <CardHeader>
                        <CardTitle>Performance by Sport</CardTitle>
                        <CardDescription
                            >Breakdown of accuracy across all supported
                            sports</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div
                                v-for="(stats, sport) in by_sport"
                                :key="sport"
                                class="border-b pb-4 last:border-0"
                            >
                                <div
                                    class="mb-2 flex items-center justify-between"
                                >
                                    <h3 class="text-lg font-semibold">
                                        {{ stats.label }}
                                    </h3>
                                    <Badge>{{ stats.win_record }}</Badge>
                                </div>
                                <div
                                    class="grid grid-cols-2 gap-4 md:grid-cols-4"
                                >
                                    <div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            Total Graded
                                        </div>
                                        <div class="text-lg font-semibold">
                                            {{ stats.total_graded }}
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            Winner Accuracy
                                        </div>
                                        <div
                                            class="text-lg font-semibold"
                                            :class="
                                                getAccuracyColor(
                                                    stats.winner_accuracy,
                                                )
                                            "
                                        >
                                            {{ stats.winner_accuracy }}%
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            Spread MAE
                                        </div>
                                        <div class="text-lg font-semibold">
                                            {{
                                                formatMetric(
                                                    stats.avg_spread_error,
                                                    ' pts',
                                                )
                                            }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ stats.spread_sample_size }}
                                            targets
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            Total MAE
                                        </div>
                                        <div class="text-lg font-semibold">
                                            {{
                                                formatMetric(
                                                    stats.avg_total_error,
                                                    ' pts',
                                                )
                                            }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ stats.total_sample_size }}
                                            targets
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Season-to-Date Stats -->
                <Card>
                    <CardHeader>
                        <CardTitle>Latest Graded Season</CardTitle>
                        <CardDescription
                            >Most recent season with graded outcomes by
                            sport</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <div
                            class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
                        >
                            <div
                                v-for="(stats, sport) in season_to_date"
                                :key="sport"
                                class="rounded-lg border p-4"
                            >
                                <h4 class="mb-2 font-semibold">
                                    {{ stats.label }} · {{ stats.season }}
                                </h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span
                                            class="text-sm text-muted-foreground"
                                            >Record:</span
                                        >
                                        <span class="font-semibold">{{
                                            stats.win_record
                                        }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span
                                            class="text-sm text-muted-foreground"
                                            >Accuracy:</span
                                        >
                                        <span
                                            class="font-semibold"
                                            :class="
                                                getAccuracyColor(
                                                    stats.winner_accuracy,
                                                )
                                            "
                                        >
                                            {{ stats.winner_accuracy }}%
                                        </span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span
                                            class="text-sm text-muted-foreground"
                                            >Total:</span
                                        >
                                        <span class="font-semibold">{{
                                            stats.total_graded
                                        }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Methodology Transparency -->
                <Card>
                    <CardHeader>
                        <CardTitle>Our Methodology</CardTitle>
                        <CardDescription
                            >What each metric measures</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm">
                            Models combine sport-specific ratings, efficiency,
                            availability, and contextual features. Prediction
                            accuracy is reported separately from betting ROI: an
                            accurate winner forecast is not automatically a
                            wager without a playable market price and passing
                            risk checks.
                        </p>
                        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <h4 class="text-sm font-semibold">
                                    What We Track:
                                </h4>
                                <ul
                                    class="list-inside list-disc space-y-1 text-sm text-muted-foreground"
                                >
                                    <li>Winner prediction accuracy</li>
                                    <li>Spread mean absolute error (MAE)</li>
                                    <li>Total mean absolute error (MAE)</li>
                                    <li>Verified settled-decision ROI</li>
                                </ul>
                            </div>
                            <div class="space-y-2">
                                <h4 class="text-sm font-semibold">
                                    Reading the Record:
                                </h4>
                                <ul
                                    class="list-inside list-disc space-y-1 text-sm text-muted-foreground"
                                >
                                    <li>52.38% win rate needed at -110 odds</li>
                                    <li>
                                        50% breaks even only at even-money odds
                                    </li>
                                    <li>
                                        Required win rate changes with price
                                    </li>
                                    <li>ROI uses recorded settlement profit</li>
                                </ul>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Disclaimer -->
                <div
                    class="rounded-lg border p-4 text-center text-sm text-muted-foreground"
                >
                    <strong>Important Disclaimer:</strong> These predictions are
                    for entertainment purposes only. Past performance does not
                    guarantee future results. ROI reflects tracked model
                    decisions and settlements, not a claim of user wagering or
                    future returns. Always gamble responsibly.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
