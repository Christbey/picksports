<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    STANDARD_SHEET_CELL_HEIGHT,
    STANDARD_SHEET_CELL_WIDTH,
    STANDARD_SHEET_COLUMNS,
    STANDARD_SHEET_GAP,
    STANDARD_SHEET_PAD,
    createStandardSheetCanvas,
    downloadCanvasPng,
    drawCenteredText,
    drawRoundRect,
    drawStatRow,
    fitFontSizeForWidth,
    fitTextToWidth,
} from '@/composables/useExportSheets';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';

type Recommendation = {
    id: number;
    player: {
        name: string;
        position: string | null;
        team: string | null;
        headshot: string | null;
    };
    market: string;
    line: number;
    recommendation: 'Over' | 'Under';
    odds: number;
    confidence: number;
    edge: number;
    stats: {
        season_avg: number;
        recent_avg: number;
        last5_avg: number;
        times_covered_last5: { hits: number; games: number } | null;
        times_covered_season: { hits: number; games: number } | null;
        vs_opponent_avg: number | null;
        consistency: {
            std_dev: number;
            level: string;
            min: number;
            max: number;
        } | null;
    };
    model_over_probability?: number | null;
    market_over_probability?: number | null;
    game: {
        home_team: string | null;
        away_team: string | null;
        date: string | null;
        time: string | null;
    };
    bookmaker: string | null;
};

type Option = {
    value: string;
    label: string;
};

type GameOption = {
    id: number;
    label: string;
    date: string;
    time: string;
};

type ExportPreset = {
    id: 'instagram' | 'facebook';
    label: string;
    width: number;
    height: number;
};

type StatRow = {
    key: string;
    label: string;
    value: string;
};

type PredictionExport = {
    id: number;
    game_id: number;
    home_team: string;
    away_team: string;
    game: {
        home_team: string | null;
        away_team: string | null;
        date: string | null;
        time: string | null;
    };
    predicted_spread: number | null;
    predicted_total: number | null;
    win_probability: number;
    confidence: number;
    home_elo: number | null;
    away_elo: number | null;
    pick_side: 'Home' | 'Away';
    pick_team: string;
};

type FuturesExport = {
    id: number;
    team: string;
    season: number;
    projected_seed: number | null;
    playoff_make_probability: number;
    champion_probability: number;
    conference_or_league: string | null;
    conference_finals_probability: number | null;
    nba_finals_probability: number | null;
    world_series_probability: number | null;
    league_championship_probability: number | null;
    sport: 'NBA' | 'MLB';
};

type TournamentExport = {
    id: number;
    team: string;
    season: number;
    conference: string | null;
    projected_seed: number | null;
    tournament_make_probability: number;
    champion_probability: number;
    auto_bid_probability: number;
    at_large_probability: number;
    first_four_probability: number;
    bid_thief_probability: number;
};

const props = defineProps<{
    sport: 'NBA' | 'NFL' | 'MLB' | 'CBB';
    activeTab: 'props' | 'predictions' | 'futures' | 'tournament';
    recommendations: Recommendation[];
    predictions: PredictionExport[];
    futures: FuturesExport[];
    tournaments: TournamentExport[];
    dates: Option[];
    games: GameOption[];
    markets: Option[];
    predictionDates: Option[];
    predictionGames: GameOption[];
    futuresSeasons: Option[];
    tournamentSeasons: Option[];
    filters: {
        sport: 'NBA' | 'NFL' | 'MLB' | 'CBB';
        date: string | null;
        game: number | null;
        market: string | null;
        tab: 'props' | 'predictions' | 'futures' | 'tournament';
        season: number | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Exports', href: '/settings/prop-exports' },
];

const activeTab = ref<'props' | 'predictions' | 'futures' | 'tournament'>(
    props.activeTab ?? 'props',
);
const selectedSport = ref(props.filters.sport ?? 'NBA');
const selectedDate = ref(props.filters.date ?? '');
const selectedGame = ref(props.filters.game ? String(props.filters.game) : '');
const selectedMarket = ref(props.filters.market ?? '');
const selectedSeason = ref(
    props.filters.season ? String(props.filters.season) : '',
);
const selectedPreset = ref<ExportPreset['id']>('instagram');
const adSafeMode = ref(false);
const exportingAll = ref(false);

const presets: ExportPreset[] = [
    {
        id: 'instagram',
        label: 'Instagram Portrait (1080x1350)',
        width: 1080,
        height: 1350,
    },
    {
        id: 'facebook',
        label: 'Facebook Feed (1200x630)',
        width: 1200,
        height: 630,
    },
];

const activePreset = computed(
    () =>
        presets.find((preset) => preset.id === selectedPreset.value) ??
        presets[0],
);
const activeRowsCount = computed(() => {
    if (activeTab.value === 'props') return props.recommendations.length;
    if (activeTab.value === 'predictions') return props.predictions.length;
    if (activeTab.value === 'futures') return props.futures.length;
    return props.tournaments.length;
});

const filteredGames = computed(() => {
    if (activeTab.value === 'futures' || activeTab.value === 'tournament') {
        return [] as GameOption[];
    }

    const pool =
        activeTab.value === 'props' ? props.games : props.predictionGames;

    if (!selectedDate.value) {
        return pool;
    }

    return pool.filter((game) => game.date === selectedDate.value);
});

const filteredDates = computed(() => {
    if (activeTab.value === 'props') return props.dates;
    if (activeTab.value === 'predictions') return props.predictionDates;
    return [] as Option[];
});

const seasonOptions = computed(() =>
    activeTab.value === 'futures'
        ? props.futuresSeasons
        : props.tournamentSeasons,
);

const hasActiveFilters = computed(
    () =>
        selectedSport.value !== 'NBA' ||
        selectedDate.value !== '' ||
        selectedGame.value !== '' ||
        (activeTab.value === 'props' && selectedMarket.value !== '') ||
        ((activeTab.value === 'futures' || activeTab.value === 'tournament') &&
            selectedSeason.value !== '') ||
        activeTab.value !== 'props',
);

const applyFilters = () => {
    const query: Record<string, string> = {
        sport: selectedSport.value,
        tab: activeTab.value,
    };

    if (activeTab.value === 'props' || activeTab.value === 'predictions') {
        if (selectedDate.value) query.date = selectedDate.value;
        if (selectedGame.value) query.game = selectedGame.value;
    }
    if (activeTab.value === 'props' && selectedMarket.value)
        query.market = selectedMarket.value;
    if (
        (activeTab.value === 'futures' || activeTab.value === 'tournament') &&
        selectedSeason.value
    )
        query.season = selectedSeason.value;

    router.get('/settings/prop-exports', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearFilters = () => {
    selectedSport.value = 'NBA';
    selectedDate.value = '';
    selectedGame.value = '';
    selectedMarket.value = '';
    selectedSeason.value = '';
    activeTab.value = 'props';
    applyFilters();
};

const onDateChange = () => {
    selectedGame.value = '';
    applyFilters();
};

const setTab = (tab: 'props' | 'predictions' | 'futures' | 'tournament') => {
    if (activeTab.value === tab) {
        return;
    }

    activeTab.value = tab;
    selectedDate.value = '';
    selectedGame.value = '';
    selectedMarket.value = '';
    selectedSeason.value = '';
    applyFilters();
};

const getConfidenceColor = (confidence: number) => {
    if (confidence >= 88) return 'bg-green-500';
    if (confidence >= 76) return 'bg-emerald-500';
    if (confidence >= 64) return 'bg-yellow-500';
    return 'bg-gray-500';
};

const getSignalBand = (confidence: number) => {
    if (confidence >= 97) return 'Outlier';
    if (confidence >= 88) return 'Very Strong';
    if (confidence >= 76) return 'Strong';
    if (confidence >= 64) return 'Lean';
    return 'Low';
};

const formatOdds = (odds: number) => (odds > 0 ? `+${odds}` : odds.toString());

const getInitials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

const recommendationHitCount = (
    recommendation: 'Over' | 'Under',
    values: { hits: number; games: number } | null,
) => {
    if (!values) {
        return 'N/A';
    }

    const hits =
        recommendation === 'Under' ? values.games - values.hits : values.hits;

    return `${hits}/${values.games}`;
};

const shortMarket = (market: string) =>
    market.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());

const sanitizeFilename = (value: string) =>
    value
        .replace(/[^a-z0-9_-]+/gi, '-')
        .replace(/-+/g, '-')
        .replace(/(^-|-$)/g, '');

const formatStatNumber = (value: number | null | undefined) => {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'N/A';
    }

    return Number(value).toFixed(1);
};

function buildRelativeSignalMap(
    items: Array<{ id: number; value: number }>,
): Record<number, number> {
    const out: Record<number, number> = {};
    if (items.length === 0) return out;
    if (items.length === 1) {
        out[items[0].id] = Math.round(items[0].value);
        return out;
    }

    const values = items.map((item) => Number(item.value) || 0);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min;
    const mean = values.reduce((sum, value) => sum + value, 0) / values.length;
    const variance =
        values.reduce((sum, value) => sum + (value - mean) ** 2, 0) /
        values.length;
    const stdDev = Math.sqrt(Math.max(variance, 0.0001));
    const ranked = [...items].sort((a, b) => a.value - b.value);
    const rankMap = ranked.reduce<Record<number, number>>((acc, item, idx) => {
        acc[item.id] = idx;
        return acc;
    }, {});
    const denominator = Math.max(items.length - 1, 1);

    items.forEach((item) => {
        const rankPct = (rankMap[item.id] ?? 0) / denominator;
        const zScore = (item.value - mean) / stdDev;

        if (range < 6) {
            let score = 58 + rankPct * 34;
            if (zScore >= 1.2) score += 4;
            if (zScore >= 1.8) score += 4;
            out[item.id] = Math.round(Math.max(45, Math.min(99, score)));
            return;
        }

        const normalized = (item.value - min) / range;
        let score = 48 + normalized * 40 + rankPct * 8;
        if (zScore >= 1.2) score += 5;
        if (zScore >= 1.8) score += 5;
        out[item.id] = Math.round(Math.max(40, Math.min(99, score)));
    });
    return out;
}

const propSignalMap = computed(() =>
    buildRelativeSignalMap(
        props.recommendations.map((rec) => ({
            id: rec.id,
            value: Math.round(rec.confidence),
        })),
    ),
);

const predictionSignalMap = computed(() =>
    buildRelativeSignalMap(
        props.predictions.map((prediction) => ({
            id: prediction.id,
            value: Math.round(normalizePercent(prediction.confidence)),
        })),
    ),
);

const futuresSignalMap = computed(() =>
    buildRelativeSignalMap(
        props.futures.map((row) => ({
            id: row.id,
            value: Math.round(row.playoff_make_probability),
        })),
    ),
);

const tournamentSignalMap = computed(() =>
    buildRelativeSignalMap(
        props.tournaments.map((row) => ({
            id: row.id,
            value: Math.round(row.tournament_make_probability),
        })),
    ),
);

const signalForProp = (rec: Recommendation) =>
    propSignalMap.value[rec.id] ?? Math.round(rec.confidence);
const signalForPrediction = (prediction: PredictionExport) =>
    predictionSignalMap.value[prediction.id] ??
    Math.round(normalizePercent(prediction.confidence));
const signalForFutures = (row: FuturesExport) =>
    futuresSignalMap.value[row.id] ?? Math.round(row.playoff_make_probability);
const signalForTournament = (row: TournamentExport) =>
    tournamentSignalMap.value[row.id] ??
    Math.round(row.tournament_make_probability);

function buildStatRows(rec: Recommendation): StatRow[] {
    return [
        {
            key: 'season',
            label: 'SEASON AVG',
            value: formatStatNumber(rec.stats?.season_avg),
        },
        {
            key: 'last10',
            label: 'LAST 10',
            value: formatStatNumber(rec.stats?.recent_avg),
        },
        {
            key: 'last5',
            label: 'LAST 5',
            value: formatStatNumber(rec.stats?.last5_avg),
        },
        {
            key: 'opponent',
            label: 'VS OPPONENT',
            value: formatStatNumber(rec.stats?.vs_opponent_avg),
        },
        {
            key: 'hit5',
            label: `HIT ${rec.recommendation.toUpperCase()} (L5)`,
            value: recommendationHitCount(
                rec.recommendation,
                rec.stats?.times_covered_last5 ?? null,
            ),
        },
        {
            key: 'hitSeason',
            label: `HIT ${rec.recommendation.toUpperCase()} (SEASON)`,
            value: recommendationHitCount(
                rec.recommendation,
                rec.stats?.times_covered_season ?? null,
            ),
        },
        {
            key: 'consistency',
            label: 'CONSISTENCY',
            value: rec.stats?.consistency
                ? `${rec.stats.consistency.level} (±${formatStatNumber(rec.stats.consistency.std_dev)})`
                : 'N/A',
        },
        {
            key: 'edge',
            label: 'EDGE VS LINE',
            value: `${(rec.edge ?? 0) > 0 ? '+' : ''}${formatStatNumber(rec.edge)}`,
        },
        {
            key: 'model',
            label: 'MODEL VS MARKET',
            value:
                rec.model_over_probability !== null &&
                rec.model_over_probability !== undefined &&
                rec.market_over_probability !== null &&
                rec.market_over_probability !== undefined
                    ? `${rec.model_over_probability.toFixed(1)}% vs ${rec.market_over_probability.toFixed(1)}%`
                    : 'N/A',
        },
    ];
}

const normalizePercent = (value: number | null | undefined) => {
    const numeric = Number(value ?? 0);
    if (Number.isNaN(numeric)) {
        return 0;
    }

    return numeric <= 1 ? numeric * 100 : numeric;
};

function buildPredictionRows(prediction: PredictionExport): StatRow[] {
    return [
        {
            key: 'win-prob',
            label: 'WIN PROBABILITY',
            value: `${formatStatNumber(prediction.win_probability)}%`,
        },
        {
            key: 'pred-spread',
            label: 'PREDICTED SPREAD',
            value: formatStatNumber(prediction.predicted_spread),
        },
        {
            key: 'pred-total',
            label: 'PREDICTED TOTAL',
            value: formatStatNumber(prediction.predicted_total),
        },
        {
            key: 'home-elo',
            label: 'HOME ELO',
            value: formatStatNumber(prediction.home_elo),
        },
        {
            key: 'away-elo',
            label: 'AWAY ELO',
            value: formatStatNumber(prediction.away_elo),
        },
        {
            key: 'pick-side',
            label: 'MODEL PICK SIDE',
            value: prediction.pick_side,
        },
        {
            key: 'pick-team',
            label: 'MODEL PICK TEAM',
            value: prediction.pick_team || 'N/A',
        },
        {
            key: 'confidence',
            label: 'CONFIDENCE',
            value: `${formatStatNumber(normalizePercent(prediction.confidence))}%`,
        },
        {
            key: 'matchup',
            label: 'MATCHUP',
            value: `${prediction.away_team} @ ${prediction.home_team}`,
        },
    ];
}

function buildFuturesRows(row: FuturesExport): StatRow[] {
    return [
        { key: 'season', label: 'SEASON', value: `${row.season}` },
        {
            key: 'seed',
            label: 'PROJECTED SEED',
            value: row.projected_seed ? `${row.projected_seed}` : 'N/A',
        },
        {
            key: 'make',
            label: 'PLAYOFF MAKE',
            value: `${formatStatNumber(row.playoff_make_probability)}%`,
        },
        {
            key: 'title',
            label: 'TITLE ODDS',
            value: `${formatStatNumber(row.champion_probability)}%`,
        },
        {
            key: 'group',
            label: 'CONFERENCE / LEAGUE',
            value: row.conference_or_league ?? 'N/A',
        },
        {
            key: 'conf-finals',
            label: 'CONF FINALS',
            value:
                row.conference_finals_probability !== null
                    ? `${formatStatNumber(row.conference_finals_probability)}%`
                    : 'N/A',
        },
        {
            key: 'finals',
            label: 'FINALS / WS',
            value: `${formatStatNumber(row.nba_finals_probability ?? row.world_series_probability)}%`,
        },
        {
            key: 'lcs',
            label: 'LCS ODDS',
            value:
                row.league_championship_probability !== null
                    ? `${formatStatNumber(row.league_championship_probability)}%`
                    : 'N/A',
        },
        { key: 'team', label: 'TEAM', value: row.team },
    ];
}

function buildTournamentRows(row: TournamentExport): StatRow[] {
    return [
        { key: 'season', label: 'SEASON', value: `${row.season}` },
        {
            key: 'seed',
            label: 'PROJECTED SEED',
            value: row.projected_seed ? `${row.projected_seed}` : 'N/A',
        },
        {
            key: 'make',
            label: 'TOURNAMENT MAKE',
            value: `${formatStatNumber(row.tournament_make_probability)}%`,
        },
        {
            key: 'title',
            label: 'TITLE ODDS',
            value: `${formatStatNumber(row.champion_probability)}%`,
        },
        {
            key: 'auto',
            label: 'AUTO BID',
            value: `${formatStatNumber(row.auto_bid_probability)}%`,
        },
        {
            key: 'at-large',
            label: 'AT LARGE',
            value: `${formatStatNumber(row.at_large_probability)}%`,
        },
        {
            key: 'first-four',
            label: 'FIRST FOUR',
            value: `${formatStatNumber(row.first_four_probability)}%`,
        },
        {
            key: 'bid-thief',
            label: 'BID THIEF',
            value: `${formatStatNumber(row.bid_thief_probability)}%`,
        },
        {
            key: 'conference',
            label: 'CONFERENCE',
            value: row.conference ?? 'N/A',
        },
    ];
}

function selectRowsForAdMode(rows: StatRow[]): StatRow[] {
    if (!adSafeMode.value) {
        return rows;
    }

    return rows.slice(0, 6);
}

async function exportRecommendation(
    rec: Recommendation,
    index: number,
): Promise<void> {
    const preset = activePreset.value;
    const scale = preset.id === 'instagram' ? 1 : 0.64;
    const canvas = document.createElement('canvas');
    canvas.width = preset.width;
    canvas.height = preset.height;
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    const signal = signalForProp(rec);
    const bg = ctx.createLinearGradient(0, 0, preset.width, preset.height);
    bg.addColorStop(0, '#020617');
    bg.addColorStop(1, '#0f172a');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, preset.width, preset.height);

    const outerPad = 56 * scale;
    const cardX = outerPad;
    const cardY = outerPad;
    const cardW = preset.width - outerPad * 2;
    const cardH = preset.height - outerPad * 2;

    drawRoundRect(ctx, cardX, cardY, cardW, cardH, 28 * scale);
    ctx.fillStyle = '#f8fafc';
    ctx.fill();

    const inset = 38 * scale;
    const contentX = cardX + inset;
    const contentW = cardW - inset * 2;
    let y = cardY + inset;

    ctx.fillStyle = '#0f172a';
    ctx.font = `700 ${(adSafeMode.value ? 46 : 40) * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    const maxNameW = contentW - 170 * scale;
    ctx.fillText(
        fitTextToWidth(ctx, rec.player.name, maxNameW),
        contentX,
        y + 10 * scale,
    );

    y += 48 * scale;
    ctx.fillStyle = '#64748b';
    ctx.font = `600 ${(adSafeMode.value ? 26 : 24) * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    const metaText = `${rec.player.position ?? 'N/A'} • ${rec.player.team ?? 'N/A'}`;
    ctx.fillText(
        fitTextToWidth(ctx, metaText, contentW - 170 * scale),
        contentX,
        y,
    );

    y += 32 * scale;
    const matchupText = `${rec.game.away_team ?? 'Away'} @ ${rec.game.home_team ?? 'Home'}`;
    ctx.fillText(
        fitTextToWidth(ctx, matchupText, contentW - 170 * scale),
        contentX,
        y,
    );

    const pctBoxW = 150 * scale;
    const pctBoxH = 66 * scale;
    const pctX = contentX + contentW - pctBoxW;
    const pctY = cardY + inset;

    drawRoundRect(ctx, pctX, pctY, pctBoxW, pctBoxH, 14 * scale);
    ctx.fillStyle = '#16a34a';
    ctx.fill();
    drawCenteredText(
        ctx,
        `${signal}%`,
        pctX + pctBoxW / 2,
        pctY + pctBoxH / 2,
        `800 ${40 * scale}px "Instrument Sans"`,
        '#ffffff',
    );

    ctx.fillStyle = '#334155';
    ctx.font = `700 ${18 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(getSignalBand(signal), pctX, pctY + pctBoxH + 22 * scale);

    y += 28 * scale;
    drawRoundRect(ctx, contentX, y, contentW, 114 * scale, 16 * scale);
    ctx.fillStyle = '#eaeefc';
    ctx.fill();

    ctx.fillStyle = rec.recommendation === 'Over' ? '#15803d' : '#b91c1c';
    ctx.font = `700 ${(adSafeMode.value ? 48 : 44) * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(
        `${rec.recommendation} ${rec.line}`,
        contentX + 20 * scale,
        y + 50 * scale,
    );

    ctx.fillStyle = '#475569';
    ctx.font = `600 ${23 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(
        fitTextToWidth(ctx, shortMarket(rec.market), contentW - 220 * scale),
        contentX + 20 * scale,
        y + 84 * scale,
    );

    ctx.fillStyle = '#0f172a';
    ctx.font = `700 ${28 * scale}px monospace`;
    const odds = formatOdds(rec.odds);
    const oddsWidth = ctx.measureText(odds).width;
    ctx.fillText(
        odds,
        contentX + contentW - oddsWidth - 20 * scale,
        y + 73 * scale,
    );

    y += 136 * scale;
    drawRoundRect(ctx, contentX, y, contentW, 64 * scale, 14 * scale);
    ctx.fillStyle = '#edf2ff';
    ctx.fill();

    ctx.fillStyle = '#334155';
    ctx.font = `600 ${20 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText('Signal Strength', contentX + 18 * scale, y + 24 * scale);
    const pctText = `${signal}%`;
    const pctTextW = ctx.measureText(pctText).width;
    ctx.fillText(
        pctText,
        contentX + contentW - pctTextW - 18 * scale,
        y + 24 * scale,
    );

    const barX = contentX + 18 * scale;
    const barY = y + 34 * scale;
    const barW = contentW - 36 * scale;
    const barH = 14 * scale;
    drawRoundRect(ctx, barX, barY, barW, barH, 8 * scale);
    ctx.fillStyle = '#dbeafe';
    ctx.fill();
    drawRoundRect(
        ctx,
        barX,
        barY,
        barW * (Math.max(0, Math.min(signal, 100)) / 100),
        barH,
        8 * scale,
    );
    ctx.fillStyle =
        signal >= 88 ? '#22c55e' : signal >= 76 ? '#34d399' : '#eab308';
    ctx.fill();

    y += 76 * scale;
    const footerH = 54 * scale;
    const tableBottom = cardY + cardH - inset - footerH - 10 * scale;
    const tableH = tableBottom - y;
    drawRoundRect(ctx, contentX, y, contentW, tableH, 12 * scale);
    ctx.fillStyle = '#ffffff';
    ctx.fill();

    const rows = selectRowsForAdMode(buildStatRows(rec));
    const headerH = 32 * scale;
    const rowH = (tableH - headerH) / rows.length;

    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(
        contentX + 1 * scale,
        y + 1 * scale,
        contentW - 2 * scale,
        headerH - 1 * scale,
    );
    ctx.beginPath();
    ctx.moveTo(contentX + 12 * scale, y + headerH);
    ctx.lineTo(contentX + contentW - 12 * scale, y + headerH);
    ctx.strokeStyle = '#cbd5e1';
    ctx.lineWidth = 1;
    ctx.stroke();

    ctx.fillStyle = '#475569';
    ctx.font = `700 ${13 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText('METRIC', contentX + 18 * scale, y + headerH / 2 + 4 * scale);
    const valueHeader = 'VALUE';
    const valueHeaderW = ctx.measureText(valueHeader).width;
    ctx.fillText(
        valueHeader,
        contentX + contentW - valueHeaderW - 18 * scale,
        y + headerH / 2 + 4 * scale,
    );

    rows.forEach((row, rowIndex) => {
        const ry = y + headerH + rowIndex * rowH;
        const labelX = contentX + 18 * scale;
        const valueRightX = contentX + contentW - 30 * scale;
        const dividerX = contentX + contentW * 0.58;

        if (rowIndex >= 0) {
            ctx.beginPath();
            ctx.moveTo(contentX + 12 * scale, ry);
            ctx.lineTo(contentX + contentW - 12 * scale, ry);
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;
            ctx.stroke();
        }

        ctx.fillStyle = '#64748b';
        ctx.font = `700 ${14 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        const label = fitTextToWidth(
            ctx,
            row.label,
            dividerX - labelX - 12 * scale,
        );
        ctx.fillText(label, labelX, ry + rowH / 2 + 4 * scale);

        const valueMaxWidth = valueRightX - dividerX - 14 * scale;
        const valueFontPx = fitFontSizeForWidth(
            ctx,
            row.value,
            valueMaxWidth,
            23 * scale,
            16 * scale,
        );
        ctx.fillStyle = '#0f172a';
        ctx.font = `800 ${valueFontPx}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        const value = fitTextToWidth(ctx, row.value, valueMaxWidth);
        const valueWidth = ctx.measureText(value).width;
        ctx.fillText(
            value,
            valueRightX - valueWidth,
            ry + rowH / 2 + 6 * scale,
        );
    });

    const footerY = cardY + cardH - footerH;
    drawRoundRect(ctx, cardX, footerY, cardW, footerH, 0);
    ctx.fillStyle = '#0f172a';
    ctx.fill();
    drawCenteredText(
        ctx,
        'PICKSPORTS • DATA-DRIVEN PLAYER PROPS',
        cardX + cardW / 2,
        footerY + footerH / 2,
        `700 ${16 * scale}px "Instrument Sans"`,
        '#f8fafc',
    );

    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob((data) => resolve(data), 'image/png'),
    );
    if (!blob) {
        return;
    }

    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.download = `${sanitizeFilename(`${selectedSport.value}-${rec.player.name}-${rec.market}-${index + 1}`)}-${preset.width}x${preset.height}-${Date.now()}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

async function exportPrediction(
    prediction: PredictionExport,
    index: number,
): Promise<void> {
    await exportGenericTableCard(
        `${prediction.away_team} @ ${prediction.home_team}`,
        `${prediction.game.date ?? 'N/A'} • ${prediction.game.time ?? 'TBD'}`,
        `${prediction.pick_side} • ${prediction.pick_team}`,
        `Spread ${formatStatNumber(prediction.predicted_spread)} • Total ${formatStatNumber(prediction.predicted_total)}`,
        signalForPrediction(prediction),
        buildPredictionRows(prediction),
        'PICKSPORTS • DATA-DRIVEN GAME PREDICTIONS',
        `${selectedSport.value}-prediction-${prediction.away_team}-${prediction.home_team}-${index + 1}`,
    );
}

async function exportGenericTableCard(
    title: string,
    subtitle: string,
    headline: string,
    subheadline: string,
    confidence: number,
    rows: StatRow[],
    footer: string,
    filename: string,
): Promise<void> {
    const preset = activePreset.value;
    const scale = preset.id === 'instagram' ? 1 : 0.64;
    const canvas = document.createElement('canvas');
    canvas.width = preset.width;
    canvas.height = preset.height;
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    const confidencePct = Math.round(normalizePercent(confidence));
    const bg = ctx.createLinearGradient(0, 0, preset.width, preset.height);
    bg.addColorStop(0, '#020617');
    bg.addColorStop(1, '#0f172a');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, preset.width, preset.height);

    const outerPad = 56 * scale;
    const cardX = outerPad;
    const cardY = outerPad;
    const cardW = preset.width - outerPad * 2;
    const cardH = preset.height - outerPad * 2;
    drawRoundRect(ctx, cardX, cardY, cardW, cardH, 28 * scale);
    ctx.fillStyle = '#f8fafc';
    ctx.fill();
    ctx.strokeStyle = '#cbd5e1';
    ctx.lineWidth = 1.2 * scale;
    ctx.stroke();

    const insetX = 38 * scale;
    const insetY = 30 * scale;
    const contentX = cardX + insetX;
    const contentW = cardW - insetX * 2;
    let y = cardY + insetY;
    const pctBoxW = 150 * scale;
    const pctBoxH = 66 * scale;
    const headerRightReserve = pctBoxW + 22 * scale;

    // Header block
    ctx.fillStyle = '#0f172a';
    ctx.font = `700 ${38 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(
        fitTextToWidth(ctx, title, contentW - headerRightReserve),
        contentX,
        y + 22 * scale,
    );

    y += 46 * scale;
    ctx.fillStyle = '#64748b';
    ctx.font = `600 ${21 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(
        fitTextToWidth(ctx, subtitle, contentW - headerRightReserve),
        contentX,
        y + 6 * scale,
    );

    const pctX = contentX + contentW - pctBoxW;
    const pctY = cardY + insetY;
    drawRoundRect(ctx, pctX, pctY, pctBoxW, pctBoxH, 14 * scale);
    ctx.fillStyle = '#16a34a';
    ctx.fill();
    drawCenteredText(
        ctx,
        `${confidencePct}%`,
        pctX + pctBoxW / 2,
        pctY + pctBoxH / 2,
        `800 ${40 * scale}px "Instrument Sans"`,
        '#ffffff',
    );

    ctx.fillStyle = '#334155';
    ctx.font = `700 ${18 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(
        getSignalBand(confidencePct),
        pctX,
        pctY + pctBoxH + 22 * scale,
    );

    // Pick block
    y += 26 * scale;
    const pickH = 114 * scale;
    drawRoundRect(ctx, contentX, y, contentW, pickH, 16 * scale);
    ctx.fillStyle = '#eaeefc';
    ctx.fill();

    ctx.fillStyle = '#0f172a';
    ctx.font = `700 ${40 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(
        fitTextToWidth(ctx, headline, contentW - 44 * scale),
        contentX + 20 * scale,
        y + 52 * scale,
    );
    ctx.fillStyle = '#475569';
    ctx.font = `700 ${22 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(
        fitTextToWidth(ctx, subheadline, contentW - 44 * scale),
        contentX + 20 * scale,
        y + 86 * scale,
    );

    // Signal block
    y += pickH + 16 * scale;
    const signalH = 64 * scale;
    drawRoundRect(ctx, contentX, y, contentW, signalH, 14 * scale);
    ctx.fillStyle = '#edf2ff';
    ctx.fill();
    ctx.fillStyle = '#334155';
    ctx.font = `600 ${20 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText('Signal Strength', contentX + 18 * scale, y + 24 * scale);
    const pctText = `${confidencePct}%`;
    const pctTextW = ctx.measureText(pctText).width;
    ctx.fillText(
        pctText,
        contentX + contentW - pctTextW - 18 * scale,
        y + 24 * scale,
    );

    const barX = contentX + 18 * scale;
    const barY = y + 34 * scale;
    const barW = contentW - 36 * scale;
    const barH = 14 * scale;
    drawRoundRect(ctx, barX, barY, barW, barH, 8 * scale);
    ctx.fillStyle = '#dbeafe';
    ctx.fill();
    drawRoundRect(
        ctx,
        barX,
        barY,
        barW * (Math.max(0, Math.min(confidencePct, 100)) / 100),
        barH,
        8 * scale,
    );
    ctx.fillStyle =
        confidencePct >= 80
            ? '#22c55e'
            : confidencePct >= 70
              ? '#34d399'
              : '#eab308';
    ctx.fill();

    // Stats table block
    y += signalH + 12 * scale;
    const footerH = 54 * scale;
    const tableBottom = cardY + cardH - insetY - footerH - 10 * scale;
    const tableH = tableBottom - y;
    drawRoundRect(ctx, contentX, y, contentW, tableH, 12 * scale);
    ctx.fillStyle = '#ffffff';
    ctx.fill();

    const visibleRows = selectRowsForAdMode(rows);
    const headerH = 32 * scale;
    const rowH = (tableH - headerH) / visibleRows.length;
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(
        contentX + 1 * scale,
        y + 1 * scale,
        contentW - 2 * scale,
        headerH - 1 * scale,
    );
    ctx.beginPath();
    ctx.moveTo(contentX + 12 * scale, y + headerH);
    ctx.lineTo(contentX + contentW - 12 * scale, y + headerH);
    ctx.strokeStyle = '#cbd5e1';
    ctx.lineWidth = 1;
    ctx.stroke();

    ctx.fillStyle = '#475569';
    ctx.font = `700 ${13 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText('METRIC', contentX + 18 * scale, y + headerH / 2 + 4 * scale);
    const valueHeader = 'VALUE';
    const valueHeaderW = ctx.measureText(valueHeader).width;
    ctx.fillText(
        valueHeader,
        contentX + contentW - valueHeaderW - 18 * scale,
        y + headerH / 2 + 4 * scale,
    );

    visibleRows.forEach((row, rowIndex) => {
        const ry = y + headerH + rowIndex * rowH;
        const labelX = contentX + 18 * scale;
        const valueRightX = contentX + contentW - 30 * scale;
        const dividerX = contentX + contentW * 0.58;

        ctx.beginPath();
        ctx.moveTo(contentX + 12 * scale, ry);
        ctx.lineTo(contentX + contentW - 12 * scale, ry);
        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = 1;
        ctx.stroke();

        ctx.fillStyle = '#64748b';
        ctx.font = `700 ${14 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            fitTextToWidth(ctx, row.label, dividerX - labelX - 12 * scale),
            labelX,
            ry + rowH / 2 + 4 * scale,
        );

        const valueMaxWidth = valueRightX - dividerX - 14 * scale;
        const valueFontPx = fitFontSizeForWidth(
            ctx,
            row.value,
            valueMaxWidth,
            22 * scale,
            16 * scale,
        );
        ctx.fillStyle = '#0f172a';
        ctx.font = `800 ${valueFontPx}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        const value = fitTextToWidth(ctx, row.value, valueMaxWidth);
        const valueWidth = ctx.measureText(value).width;
        ctx.fillText(
            value,
            valueRightX - valueWidth,
            ry + rowH / 2 + 6 * scale,
        );
    });

    const footerY = cardY + cardH - footerH;
    drawRoundRect(ctx, cardX, footerY, cardW, footerH, 0);
    ctx.fillStyle = '#0f172a';
    ctx.fill();
    drawCenteredText(
        ctx,
        `${footer} • PICKSPORTS.APP`,
        cardX + cardW / 2,
        footerY + footerH / 2,
        `700 ${14 * scale}px "Instrument Sans"`,
        '#f8fafc',
    );

    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob((data) => resolve(data), 'image/png'),
    );
    if (!blob) {
        return;
    }

    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.download = `${sanitizeFilename(filename)}-${preset.width}x${preset.height}-${Date.now()}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

async function exportFuturesRow(
    row: FuturesExport,
    index: number,
): Promise<void> {
    const headline =
        `${row.team} ${row.projected_seed ? `• Seed ${row.projected_seed}` : ''}`.trim();
    const subheadline = `Playoff ${formatStatNumber(row.playoff_make_probability)}% • Title ${formatStatNumber(row.champion_probability)}%`;
    await exportGenericTableCard(
        `${selectedSport.value} Futures`,
        `${row.conference_or_league ?? 'All'} • Season ${row.season}`,
        headline,
        subheadline,
        signalForFutures(row),
        buildFuturesRows(row),
        'PICKSPORTS • DATA-DRIVEN FUTURES',
        `${selectedSport.value}-futures-${row.team}-${index + 1}`,
    );
}

async function exportTournamentRow(
    row: TournamentExport,
    index: number,
): Promise<void> {
    const headline =
        `${row.team} ${row.projected_seed ? `• Seed ${row.projected_seed}` : ''}`.trim();
    const subheadline = `Make ${formatStatNumber(row.tournament_make_probability)}% • Title ${formatStatNumber(row.champion_probability)}%`;
    await exportGenericTableCard(
        `${selectedSport.value} Tournament`,
        `${row.conference ?? 'All'} • Season ${row.season}`,
        headline,
        subheadline,
        signalForTournament(row),
        buildTournamentRows(row),
        'PICKSPORTS • DATA-DRIVEN TOURNAMENT FORECAST',
        `${selectedSport.value}-tournament-${row.team}-${index + 1}`,
    );
}

async function exportRecommendationsTable(): Promise<void> {
    const total = props.recommendations.length;
    const columns = STANDARD_SHEET_COLUMNS;
    const cellW = STANDARD_SHEET_CELL_WIDTH;
    const cellH = STANDARD_SHEET_CELL_HEIGHT;
    const gap = STANDARD_SHEET_GAP;
    const pad = STANDARD_SHEET_PAD;
    const sheet = createStandardSheetCanvas(total);

    if (!sheet) {
        return;
    }
    const { canvas, ctx } = sheet;

    props.recommendations.forEach((rec, index) => {
        const signal = signalForProp(rec);
        const col = index % columns;
        const row = Math.floor(index / columns);
        const x = pad + col * (cellW + gap);
        const y = pad + row * (cellH + gap);

        drawRoundRect(ctx, x, y, cellW, cellH, 18);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1;
        ctx.stroke();

        const inner = 24;
        const contentX = x + inner;
        const contentW = cellW - inner * 2;
        let cursorY = y + 42;

        ctx.fillStyle = '#0f172a';
        ctx.font =
            '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        const playerName =
            rec.player.name.length > 26
                ? `${rec.player.name.slice(0, 26)}...`
                : rec.player.name;
        ctx.fillText(playerName, contentX, cursorY);

        cursorY += 30;
        ctx.fillStyle = '#64748b';
        ctx.font =
            '500 18px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            fitTextToWidth(
                ctx,
                `${rec.player.position ?? 'N/A'} • ${rec.player.team ?? 'N/A'}`,
                contentW - 18,
            ),
            contentX,
            cursorY,
        );

        cursorY += 26;
        const matchup = `${rec.game.away_team ?? 'Away'} @ ${rec.game.home_team ?? 'Home'}`;
        const shortMatchup =
            matchup.length > 42 ? `${matchup.slice(0, 42)}...` : matchup;
        ctx.fillText(
            fitTextToWidth(ctx, shortMatchup, contentW - 18),
            contentX,
            cursorY,
        );

        const pctW = 110;
        const pctH = 52;
        const pctX = x + cellW - inner - pctW;
        const pctY = y + 20;
        drawRoundRect(ctx, pctX, pctY, pctW, pctH, 12);
        ctx.fillStyle = '#16a34a';
        ctx.fill();
        drawCenteredText(
            ctx,
            `${signal}%`,
            pctX + pctW / 2,
            pctY + pctH / 2,
            '800 30px "Instrument Sans"',
            '#ffffff',
        );

        cursorY += 26;
        drawRoundRect(ctx, contentX, cursorY, contentW, 72, 12);
        ctx.fillStyle = '#eef2ff';
        ctx.fill();

        ctx.fillStyle = rec.recommendation === 'Over' ? '#15803d' : '#b91c1c';
        ctx.font =
            '700 34px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            `${rec.recommendation} ${rec.line}`,
            contentX + 14,
            cursorY + 32,
        );

        ctx.fillStyle = '#475569';
        ctx.font =
            '600 17px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            fitTextToWidth(ctx, shortMarket(rec.market), contentW - 130),
            contentX + 14,
            cursorY + 57,
        );
        ctx.fillStyle = '#0f172a';
        ctx.font = '700 22px monospace';
        const odds = formatOdds(rec.odds);
        const oddsW = ctx.measureText(odds).width;
        ctx.fillText(odds, contentX + contentW - oddsW - 14, cursorY + 43);

        cursorY += 100;
        const propSheetRows: Array<[string, string]> = [
            ['Season Avg', `${rec.stats?.season_avg ?? 0}`],
            ['Last 5 Games', `${rec.stats?.last5_avg ?? 0}`],
            [
                'Edge vs Line',
                `${(rec.edge ?? 0) > 0 ? '+' : ''}${rec.edge ?? 0}`,
            ],
        ];
        const visiblePropSheetRows = adSafeMode.value
            ? [propSheetRows[0], propSheetRows[2]]
            : propSheetRows;
        visiblePropSheetRows.forEach(([label, value], rowIndex) => {
            drawStatRow(
                ctx,
                label,
                value,
                contentX,
                cursorY + rowIndex * 24,
                contentW,
                adSafeMode.value ? 15 : 15,
            );
        });

        ctx.fillStyle = '#334155';
        ctx.font =
            '700 12px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText('picksports.app', x + cellW - 122, y + cellH - 16);
    });

    await downloadCanvasPng(
        canvas,
        `${sanitizeFilename(`${selectedSport.value}-props-table-${total}`)}.png`,
    );
}

async function exportPredictionsTable(): Promise<void> {
    const total = props.predictions.length;
    const columns = STANDARD_SHEET_COLUMNS;
    const cellW = STANDARD_SHEET_CELL_WIDTH;
    const cellH = STANDARD_SHEET_CELL_HEIGHT;
    const gap = STANDARD_SHEET_GAP;
    const pad = STANDARD_SHEET_PAD;
    const sheet = createStandardSheetCanvas(total);
    if (!sheet) return;
    const { canvas, ctx } = sheet;

    props.predictions.forEach((prediction, index) => {
        const signal = signalForPrediction(prediction);
        const col = index % columns;
        const gridRow = Math.floor(index / columns);
        const x = pad + col * (cellW + gap);
        const y = pad + gridRow * (cellH + gap);

        drawRoundRect(ctx, x, y, cellW, cellH, 18);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1;
        ctx.stroke();

        const inner = 24;
        const contentX = x + inner;
        const contentW = cellW - inner * 2;
        let cursorY = y + 42;

        ctx.fillStyle = '#0f172a';
        ctx.font =
            '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            fitTextToWidth(
                ctx,
                `${prediction.away_team} @ ${prediction.home_team}`,
                contentW - 170,
            ),
            contentX,
            cursorY,
        );

        cursorY += 28;
        ctx.fillStyle = '#64748b';
        ctx.font =
            '500 18px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            fitTextToWidth(
                ctx,
                `${prediction.game.date ?? 'N/A'} • ${prediction.game.time ?? 'TBD'}`,
                contentW - 18,
            ),
            contentX,
            cursorY,
        );

        const pct = signal;
        const pctW = 110;
        const pctH = 52;
        const pctX = x + cellW - inner - pctW;
        const pctY = y + 20;
        drawRoundRect(ctx, pctX, pctY, pctW, pctH, 12);
        ctx.fillStyle = '#16a34a';
        ctx.fill();
        drawCenteredText(
            ctx,
            `${pct}%`,
            pctX + pctW / 2,
            pctY + pctH / 2,
            '800 30px "Instrument Sans"',
            '#ffffff',
        );

        cursorY += 26;
        drawRoundRect(ctx, contentX, cursorY, contentW, 72, 12);
        ctx.fillStyle = '#eef2ff';
        ctx.fill();
        ctx.fillStyle = '#0f172a';
        ctx.font =
            '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            fitTextToWidth(
                ctx,
                `${prediction.pick_side} • ${prediction.pick_team}`,
                contentW - 28,
            ),
            contentX + 14,
            cursorY + 33,
        );
        ctx.fillStyle = '#475569';
        ctx.font =
            '600 17px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            fitTextToWidth(
                ctx,
                `Spread ${formatStatNumber(prediction.predicted_spread)} • Total ${formatStatNumber(prediction.predicted_total)}`,
                contentW - 130,
            ),
            contentX + 14,
            cursorY + 57,
        );

        cursorY += 100;
        const predictionSheetRows: Array<[string, string]> = [
            [
                'Win Probability',
                `${formatStatNumber(prediction.win_probability)}%`,
            ],
            ['Home Elo', `${formatStatNumber(prediction.home_elo)}`],
            ['Away Elo', `${formatStatNumber(prediction.away_elo)}`],
        ];
        const visiblePredictionSheetRows = adSafeMode.value
            ? [predictionSheetRows[0], predictionSheetRows[1]]
            : predictionSheetRows;
        visiblePredictionSheetRows.forEach(([label, value], rowIndex) => {
            drawStatRow(
                ctx,
                label,
                value,
                contentX,
                cursorY + rowIndex * 24,
                contentW,
                adSafeMode.value ? 15 : 15,
            );
        });

        ctx.fillStyle = '#334155';
        ctx.font =
            '700 12px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText('picksports.app', x + cellW - 122, y + cellH - 16);
    });

    await downloadCanvasPng(
        canvas,
        `${sanitizeFilename(`${selectedSport.value}-predictions-table-${total}`)}.png`,
    );
}

async function exportFuturesTable(): Promise<void> {
    const total = props.futures.length;
    const columns = STANDARD_SHEET_COLUMNS;
    const cellW = STANDARD_SHEET_CELL_WIDTH;
    const cellH = STANDARD_SHEET_CELL_HEIGHT;
    const gap = STANDARD_SHEET_GAP;
    const pad = STANDARD_SHEET_PAD;
    const sheet = createStandardSheetCanvas(total);
    if (!sheet) return;
    const { canvas, ctx } = sheet;

    props.futures.forEach((row, index) => {
        const signal = signalForFutures(row);
        const col = index % columns;
        const gridRow = Math.floor(index / columns);
        const x = pad + col * (cellW + gap);
        const y = pad + gridRow * (cellH + gap);

        drawRoundRect(ctx, x, y, cellW, cellH, 18);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1;
        ctx.stroke();

        const inner = 24;
        const contentX = x + inner;
        const contentW = cellW - inner * 2;
        let cursorY = y + 42;

        ctx.fillStyle = '#0f172a';
        ctx.font =
            '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            fitTextToWidth(ctx, row.team, contentW - 170),
            contentX,
            cursorY,
        );

        cursorY += 28;
        ctx.fillStyle = '#64748b';
        ctx.font =
            '500 18px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            fitTextToWidth(
                ctx,
                `${row.conference_or_league ?? 'N/A'} • Season ${row.season}`,
                contentW - 18,
            ),
            contentX,
            cursorY,
        );

        const pct = signal;
        const pctW = 110;
        const pctH = 52;
        const pctX = x + cellW - inner - pctW;
        const pctY = y + 20;
        drawRoundRect(ctx, pctX, pctY, pctW, pctH, 12);
        ctx.fillStyle = '#16a34a';
        ctx.fill();
        drawCenteredText(
            ctx,
            `${pct}%`,
            pctX + pctW / 2,
            pctY + pctH / 2,
            '800 30px "Instrument Sans"',
            '#ffffff',
        );

        cursorY += 26;
        drawRoundRect(ctx, contentX, cursorY, contentW, 72, 12);
        ctx.fillStyle = '#eef2ff';
        ctx.fill();
        ctx.fillStyle = '#0f172a';
        ctx.font =
            '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            `Seed ${row.projected_seed ?? 'N/A'}`,
            contentX + 14,
            cursorY + 33,
        );
        ctx.fillStyle = '#475569';
        ctx.font =
            '600 17px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            `Title ${formatStatNumber(row.champion_probability)}%`,
            contentX + 14,
            cursorY + 57,
        );

        cursorY += 100;
        const futuresSheetRows: Array<[string, string]> = [
            [
                'Playoff Make',
                `${formatStatNumber(row.playoff_make_probability)}%`,
            ],
            [
                'Conf/LCS',
                `${formatStatNumber(row.conference_finals_probability ?? row.league_championship_probability)}%`,
            ],
            [
                'Finals/WS',
                `${formatStatNumber(row.nba_finals_probability ?? row.world_series_probability)}%`,
            ],
        ];
        const visibleFuturesSheetRows = adSafeMode.value
            ? [futuresSheetRows[0], futuresSheetRows[2]]
            : futuresSheetRows;
        visibleFuturesSheetRows.forEach(([label, value], rowIndex) => {
            drawStatRow(
                ctx,
                label,
                value,
                contentX,
                cursorY + rowIndex * 24,
                contentW,
                adSafeMode.value ? 15 : 15,
            );
        });

        ctx.fillStyle = '#334155';
        ctx.font =
            '700 12px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText('picksports.app', x + cellW - 122, y + cellH - 16);
    });

    await downloadCanvasPng(
        canvas,
        `${sanitizeFilename(`${selectedSport.value}-futures-table-${total}`)}.png`,
    );
}

async function exportAllRecommendations(): Promise<void> {
    exportingAll.value = true;

    try {
        await exportRecommendationsTable();
    } finally {
        exportingAll.value = false;
    }
}

async function exportAllPredictions(): Promise<void> {
    exportingAll.value = true;

    try {
        await exportPredictionsTable();
    } finally {
        exportingAll.value = false;
    }
}

async function exportAllFutures(): Promise<void> {
    exportingAll.value = true;
    try {
        await exportFuturesTable();
    } finally {
        exportingAll.value = false;
    }
}

async function exportAllTournaments(): Promise<void> {
    exportingAll.value = true;
    try {
        const total = props.tournaments.length;
        const pad = 32;
        const titleH = 52;
        const headerH = 44;
        const rowH = 42;
        const width = 2200;
        const height = pad * 2 + titleH + headerH + total * rowH + 8;
        const col = {
            team: 380,
            conference: 180,
            season: 120,
            seed: 120,
            make: 180,
            title: 180,
            autoBid: 180,
            atLarge: 180,
            firstFour: 180,
            bidThief: 180,
        };

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(0, 0, width, height);

        ctx.fillStyle = '#0f172a';
        ctx.font =
            '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            `${selectedSport.value} Tournament Forecast Export`,
            pad,
            pad + 34,
        );
        ctx.font =
            '500 18px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillStyle = '#475569';
        ctx.fillText(`Rows: ${total}`, width - 150, pad + 34);

        let x = pad;
        const headerY = pad + titleH;
        ctx.fillStyle = '#e2e8f0';
        ctx.fillRect(pad, headerY, width - pad * 2, headerH);
        ctx.fillStyle = '#334155';
        ctx.font =
            '700 15px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        const headers = [
            ['TEAM', col.team],
            ['CONF', col.conference],
            ['SEASON', col.season],
            ['SEED', col.seed],
            ['MAKE %', col.make],
            ['TITLE %', col.title],
            ['AUTO %', col.autoBid],
            ['AT LARGE %', col.atLarge],
            ['FIRST FOUR %', col.firstFour],
            ['BID THIEF %', col.bidThief],
        ] as const;
        headers.forEach(([label, w]) => {
            ctx.fillText(label, x + 10, headerY + 28);
            x += w;
        });

        props.tournaments.forEach((row, index) => {
            const y = headerY + headerH + index * rowH;
            ctx.fillStyle = index % 2 === 0 ? '#ffffff' : '#f8fafc';
            ctx.fillRect(pad, y, width - pad * 2, rowH);
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(pad, y + rowH);
            ctx.lineTo(width - pad, y + rowH);
            ctx.stroke();

            ctx.fillStyle = '#0f172a';
            ctx.font =
                '600 15px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            let cx = pad;
            const values = [
                fitTextToWidth(ctx, row.team, col.team - 16),
                row.conference ?? 'N/A',
                `${row.season}`,
                `${row.projected_seed ?? 'N/A'}`,
                formatStatNumber(row.tournament_make_probability),
                formatStatNumber(row.champion_probability),
                formatStatNumber(row.auto_bid_probability),
                formatStatNumber(row.at_large_probability),
                formatStatNumber(row.first_four_probability),
                formatStatNumber(row.bid_thief_probability),
            ];
            const widths = [
                col.team,
                col.conference,
                col.season,
                col.seed,
                col.make,
                col.title,
                col.autoBid,
                col.atLarge,
                col.firstFour,
                col.bidThief,
            ];
            values.forEach((value, valueIndex) => {
                ctx.fillText(value, cx + 10, y + 27);
                cx += widths[valueIndex];
            });
        });

        await downloadCanvasPng(
            canvas,
            `${sanitizeFilename(`${selectedSport.value}-tournament-table-${total}`)}.png`,
        );
    } finally {
        exportingAll.value = false;
    }
}

const exportAllCurrent = () => {
    if (activeTab.value === 'props') {
        return exportAllRecommendations();
    }
    if (activeTab.value === 'predictions') {
        return exportAllPredictions();
    }
    if (activeTab.value === 'futures') {
        return exportAllFutures();
    }
    return exportAllTournaments();
};

const tabMeta: Record<
    'props' | 'predictions' | 'futures' | 'tournament',
    { label: string; description: string }
> = {
    props: {
        label: 'Props',
        description: 'Player prop recommendations and social exports',
    },
    predictions: {
        label: 'Predictions',
        description: 'Game picks with model confidence and market context',
    },
    futures: {
        label: 'Futures',
        description: 'Season-long playoff and title outlook exports',
    },
    tournament: {
        label: 'Tournament',
        description: 'Tournament field and champion probability exports',
    },
};

const activeTabMeta = computed(() => tabMeta[activeTab.value]);
</script>

<template>
    <Head title="Admin Exports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :full-width="true">
            <div
                class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
            >
                <div
                    class="rounded-xl border border-sidebar-border bg-background p-5 dark:bg-sidebar"
                >
                    <h1 class="text-2xl font-bold">Admin Exports</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ activeTabMeta.description }}
                    </p>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <Button
                            :variant="
                                activeTab === 'props' ? 'default' : 'outline'
                            "
                            class="h-auto justify-start px-3 py-2 text-left"
                            @click="setTab('props')"
                        >
                            <span class="font-semibold">Props</span>
                        </Button>
                        <Button
                            :variant="
                                activeTab === 'predictions'
                                    ? 'default'
                                    : 'outline'
                            "
                            class="h-auto justify-start px-3 py-2 text-left"
                            @click="setTab('predictions')"
                        >
                            <span class="font-semibold">Predictions</span>
                        </Button>
                        <Button
                            :variant="
                                activeTab === 'futures' ? 'default' : 'outline'
                            "
                            class="h-auto justify-start px-3 py-2 text-left"
                            @click="setTab('futures')"
                        >
                            <span class="font-semibold">Futures</span>
                        </Button>
                        <Button
                            :variant="
                                activeTab === 'tournament'
                                    ? 'default'
                                    : 'outline'
                            "
                            class="h-auto justify-start px-3 py-2 text-left"
                            @click="setTab('tournament')"
                        >
                            <span class="font-semibold">Tournament</span>
                        </Button>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-sidebar-border bg-background p-4 shadow-sm dark:bg-sidebar"
                >
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="space-y-2">
                            <Label for="sport">Sport</Label>
                            <select
                                id="sport"
                                v-model="selectedSport"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                                @change="applyFilters"
                            >
                                <option value="NBA">NBA</option>
                                <option value="NFL">NFL</option>
                                <option value="MLB">MLB</option>
                                <option value="CBB">CBB</option>
                            </select>
                        </div>

                        <div
                            v-if="
                                activeTab === 'props' ||
                                activeTab === 'predictions'
                            "
                            class="space-y-2"
                        >
                            <Label for="date">Date</Label>
                            <select
                                id="date"
                                v-model="selectedDate"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                                @change="onDateChange"
                            >
                                <option value="">All dates</option>
                                <option
                                    v-for="date in filteredDates"
                                    :key="date.value"
                                    :value="date.value"
                                >
                                    {{ date.label }}
                                </option>
                            </select>
                        </div>

                        <div
                            v-if="
                                activeTab === 'props' ||
                                activeTab === 'predictions'
                            "
                            class="space-y-2"
                        >
                            <Label for="game">Game</Label>
                            <select
                                id="game"
                                v-model="selectedGame"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                                @change="applyFilters"
                            >
                                <option value="">All games</option>
                                <option
                                    v-for="game in filteredGames"
                                    :key="game.id"
                                    :value="game.id.toString()"
                                >
                                    {{ game.label }}
                                </option>
                            </select>
                        </div>

                        <div
                            v-if="
                                activeTab === 'futures' ||
                                activeTab === 'tournament'
                            "
                            class="space-y-2"
                        >
                            <Label for="season">Season</Label>
                            <select
                                id="season"
                                v-model="selectedSeason"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                                @change="applyFilters"
                            >
                                <option value="">Latest season</option>
                                <option
                                    v-for="season in seasonOptions"
                                    :key="season.value"
                                    :value="season.value"
                                >
                                    {{ season.label }}
                                </option>
                            </select>
                        </div>

                        <div v-if="activeTab === 'props'" class="space-y-2">
                            <Label for="market">Market</Label>
                            <select
                                id="market"
                                v-model="selectedMarket"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                                @change="applyFilters"
                            >
                                <option value="">All markets</option>
                                <option
                                    v-for="market in markets"
                                    :key="market.value"
                                    :value="market.value"
                                >
                                    {{ market.label }}
                                </option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <Label for="preset">Export Size</Label>
                            <select
                                id="preset"
                                v-model="selectedPreset"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
                            >
                                <option
                                    v-for="preset in presets"
                                    :key="preset.id"
                                    :value="preset.id"
                                >
                                    {{ preset.label }}
                                </option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <Label for="ad-safe">Ad-safe Preset</Label>
                            <label
                                class="flex h-9 items-center gap-2 rounded-md border border-input px-3 text-sm"
                            >
                                <input
                                    id="ad-safe"
                                    v-model="adSafeMode"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-input"
                                />
                                <span>{{ adSafeMode ? 'On' : 'Off' }}</span>
                            </label>
                        </div>
                    </div>
                    <div
                        class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ activeTabMeta.label }} •
                            {{ activeRowsCount }} rows loaded
                        </p>
                        <div class="flex items-center gap-2">
                            <Button
                                v-if="hasActiveFilters"
                                variant="outline"
                                size="sm"
                                @click="clearFilters"
                                >Clear Filters</Button
                            >
                            <Button
                                :disabled="
                                    activeRowsCount === 0 || exportingAll
                                "
                                size="sm"
                                @click="exportAllCurrent"
                            >
                                {{
                                    exportingAll
                                        ? 'Exporting...'
                                        : 'Export All Shown'
                                }}
                            </Button>
                        </div>
                    </div>
                </div>

                <div
                    v-if="
                        activeTab === 'props' &&
                        props.recommendations.length === 0
                    "
                    class="rounded-xl border border-sidebar-border bg-background p-10 text-center text-muted-foreground dark:bg-sidebar"
                >
                    No prop recommendations found for the selected filters.
                </div>

                <div
                    v-else-if="activeTab === 'props'"
                    class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <article
                        v-for="(rec, index) in props.recommendations"
                        :key="rec.id"
                        class="rounded-xl border border-sidebar-border bg-background p-4 shadow-sm dark:bg-sidebar"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 flex-1 items-start gap-3">
                                <Avatar
                                    class="h-11 w-11 border-2 border-border"
                                >
                                    <AvatarImage
                                        :src="rec.player.headshot ?? ''"
                                        :alt="rec.player.name"
                                        class="object-cover"
                                    />
                                    <AvatarFallback>{{
                                        getInitials(
                                            rec.player.name || 'Unknown',
                                        )
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
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ rec.game?.away_team }} @
                                        {{ rec.game?.home_team }}
                                    </p>
                                </div>
                            </div>
                            <Badge
                                variant="outline"
                                class="font-mono text-sm"
                                >{{ formatOdds(rec.odds) }}</Badge
                            >
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
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
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

                        <div
                            class="mt-3 h-2.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full"
                                :class="getConfidenceColor(signalForProp(rec))"
                                :style="{ width: `${signalForProp(rec)}%` }"
                            />
                        </div>

                        <div
                            class="mt-3 overflow-hidden rounded-lg border border-border"
                        >
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

                <div
                    v-else-if="
                        activeTab === 'predictions' &&
                        props.predictions.length === 0
                    "
                    class="rounded-xl border border-sidebar-border bg-background p-10 text-center text-muted-foreground dark:bg-sidebar"
                >
                    No game predictions found for the selected filters.
                </div>

                <div
                    v-else-if="activeTab === 'predictions'"
                    class="overflow-x-auto rounded-xl border border-sidebar-border bg-background dark:bg-sidebar"
                >
                    <table class="w-full min-w-[1120px] table-auto">
                        <thead class="sticky top-0 z-10 bg-muted">
                            <tr>
                                <th
                                    class="w-[220px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Matchup
                                </th>
                                <th
                                    class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Model Pick
                                </th>
                                <th
                                    class="w-[180px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Spread / Total
                                </th>
                                <th
                                    class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Signal
                                </th>
                                <th
                                    class="min-w-[360px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Insights
                                </th>
                                <th
                                    class="w-[110px] px-4 py-3 text-right text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Export
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(prediction, index) in props.predictions"
                                :key="prediction.id"
                                class="border-t border-border align-top hover:bg-muted/40"
                            >
                                <td class="px-4 py-4">
                                    <p
                                        class="text-base font-semibold text-foreground"
                                    >
                                        {{ prediction.away_team }} @
                                        {{ prediction.home_team }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ prediction.game.date ?? 'N/A' }} •
                                        {{ prediction.game.time ?? 'TBD' }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{ prediction.pick_side }} •
                                        {{ prediction.pick_team }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Win Prob
                                        {{
                                            formatStatNumber(
                                                prediction.win_probability,
                                            )
                                        }}%
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Spread
                                        {{
                                            formatStatNumber(
                                                prediction.predicted_spread,
                                            )
                                        }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Total
                                        {{
                                            formatStatNumber(
                                                prediction.predicted_total,
                                            )
                                        }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="space-y-1">
                                        <div
                                            class="flex items-center justify-between text-xs text-muted-foreground"
                                        >
                                            <span>Signal</span>
                                            <span
                                                class="font-semibold text-foreground"
                                                >{{
                                                    signalForPrediction(
                                                        prediction,
                                                    )
                                                }}%</span
                                            >
                                        </div>
                                        <div
                                            class="h-2.5 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                class="h-full"
                                                :class="
                                                    getConfidenceColor(
                                                        signalForPrediction(
                                                            prediction,
                                                        ),
                                                    )
                                                "
                                                :style="{
                                                    width: `${signalForPrediction(prediction)}%`,
                                                }"
                                            />
                                        </div>
                                        <p
                                            class="text-xs font-medium text-muted-foreground"
                                        >
                                            {{
                                                getSignalBand(
                                                    signalForPrediction(
                                                        prediction,
                                                    ),
                                                )
                                            }}
                                        </p>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="grid grid-cols-2 gap-1.5">
                                        <div
                                            v-for="row in buildPredictionRows(
                                                prediction,
                                            ).slice(0, 6)"
                                            :key="`${prediction.id}-${row.key}`"
                                            class="rounded-md border border-border bg-muted/40 px-2 py-1.5"
                                        >
                                            <p
                                                class="text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                                            >
                                                {{ row.label }}
                                            </p>
                                            <p
                                                class="truncate text-xs font-semibold text-foreground"
                                            >
                                                {{ row.value }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <Button
                                        size="sm"
                                        @click="
                                            exportPrediction(prediction, index)
                                        "
                                        >Export PNG</Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-else-if="
                        activeTab === 'futures' && props.futures.length === 0
                    "
                    class="rounded-xl border border-sidebar-border bg-background p-10 text-center text-muted-foreground dark:bg-sidebar"
                >
                    No futures forecasts found for the selected sport/season.
                </div>

                <div
                    v-else-if="activeTab === 'futures'"
                    class="overflow-x-auto rounded-xl border border-sidebar-border bg-background dark:bg-sidebar"
                >
                    <table class="w-full min-w-[1120px] table-auto">
                        <thead class="sticky top-0 z-10 bg-muted">
                            <tr>
                                <th
                                    class="w-[200px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Team
                                </th>
                                <th
                                    class="w-[140px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Season/Seed
                                </th>
                                <th
                                    class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Playoff/Title
                                </th>
                                <th
                                    class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Signal
                                </th>
                                <th
                                    class="min-w-[360px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Insights
                                </th>
                                <th
                                    class="w-[110px] px-4 py-3 text-right text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Export
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in props.futures"
                                :key="row.id"
                                class="border-t border-border align-top hover:bg-muted/40"
                            >
                                <td class="px-4 py-4">
                                    <p
                                        class="text-base font-semibold text-foreground"
                                    >
                                        {{ row.team }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ row.conference_or_league ?? 'N/A' }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Season {{ row.season }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Seed {{ row.projected_seed ?? 'N/A' }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Make
                                        {{
                                            formatStatNumber(
                                                row.playoff_make_probability,
                                            )
                                        }}%
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Title
                                        {{
                                            formatStatNumber(
                                                row.champion_probability,
                                            )
                                        }}%
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="space-y-1">
                                        <div
                                            class="flex items-center justify-between text-xs text-muted-foreground"
                                        >
                                            <span>Signal</span>
                                            <span
                                                class="font-semibold text-foreground"
                                                >{{
                                                    signalForFutures(row)
                                                }}%</span
                                            >
                                        </div>
                                        <div
                                            class="h-2.5 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                class="h-full"
                                                :class="
                                                    getConfidenceColor(
                                                        signalForFutures(row),
                                                    )
                                                "
                                                :style="{
                                                    width: `${signalForFutures(row)}%`,
                                                }"
                                            />
                                        </div>
                                        <p
                                            class="text-xs font-medium text-muted-foreground"
                                        >
                                            {{
                                                getSignalBand(
                                                    signalForFutures(row),
                                                )
                                            }}
                                        </p>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="grid grid-cols-2 gap-1.5">
                                        <div
                                            v-for="stat in buildFuturesRows(
                                                row,
                                            ).slice(0, 6)"
                                            :key="`${row.id}-${stat.key}`"
                                            class="rounded-md border border-border bg-muted/40 px-2 py-1.5"
                                        >
                                            <p
                                                class="text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                                            >
                                                {{ stat.label }}
                                            </p>
                                            <p
                                                class="truncate text-xs font-semibold text-foreground"
                                            >
                                                {{ stat.value }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <Button
                                        size="sm"
                                        @click="exportFuturesRow(row, index)"
                                        >Export PNG</Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-else-if="
                        activeTab === 'tournament' &&
                        props.tournaments.length === 0
                    "
                    class="rounded-xl border border-sidebar-border bg-background p-10 text-center text-muted-foreground dark:bg-sidebar"
                >
                    No tournament forecasts found for the selected sport/season.
                </div>

                <div
                    v-else-if="activeTab === 'tournament'"
                    class="overflow-x-auto rounded-xl border border-sidebar-border bg-background dark:bg-sidebar"
                >
                    <table class="w-full min-w-[1120px] table-auto">
                        <thead class="sticky top-0 z-10 bg-muted">
                            <tr>
                                <th
                                    class="w-[220px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Team
                                </th>
                                <th
                                    class="w-[150px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Season/Seed
                                </th>
                                <th
                                    class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Make/Title
                                </th>
                                <th
                                    class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Signal
                                </th>
                                <th
                                    class="min-w-[360px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Insights
                                </th>
                                <th
                                    class="w-[110px] px-4 py-3 text-right text-xs font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Export
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in props.tournaments"
                                :key="row.id"
                                class="border-t border-border align-top hover:bg-muted/40"
                            >
                                <td class="px-4 py-4">
                                    <p
                                        class="text-base font-semibold text-foreground"
                                    >
                                        {{ row.team }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ row.conference ?? 'N/A' }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Season {{ row.season }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Seed {{ row.projected_seed ?? 'N/A' }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Make
                                        {{
                                            formatStatNumber(
                                                row.tournament_make_probability,
                                            )
                                        }}%
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Title
                                        {{
                                            formatStatNumber(
                                                row.champion_probability,
                                            )
                                        }}%
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="space-y-1">
                                        <div
                                            class="flex items-center justify-between text-xs text-muted-foreground"
                                        >
                                            <span>Signal</span>
                                            <span
                                                class="font-semibold text-foreground"
                                                >{{
                                                    signalForTournament(row)
                                                }}%</span
                                            >
                                        </div>
                                        <div
                                            class="h-2.5 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                class="h-full"
                                                :class="
                                                    getConfidenceColor(
                                                        signalForTournament(
                                                            row,
                                                        ),
                                                    )
                                                "
                                                :style="{
                                                    width: `${signalForTournament(row)}%`,
                                                }"
                                            />
                                        </div>
                                        <p
                                            class="text-xs font-medium text-muted-foreground"
                                        >
                                            {{
                                                getSignalBand(
                                                    signalForTournament(row),
                                                )
                                            }}
                                        </p>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="grid grid-cols-2 gap-1.5">
                                        <div
                                            v-for="stat in buildTournamentRows(
                                                row,
                                            ).slice(0, 6)"
                                            :key="`${row.id}-${stat.key}`"
                                            class="rounded-md border border-border bg-muted/40 px-2 py-1.5"
                                        >
                                            <p
                                                class="text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                                            >
                                                {{ stat.label }}
                                            </p>
                                            <p
                                                class="truncate text-xs font-semibold text-foreground"
                                            >
                                                {{ stat.value }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <Button
                                        size="sm"
                                        @click="exportTournamentRow(row, index)"
                                        >Export PNG</Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
