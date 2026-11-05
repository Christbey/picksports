import { computed, ref, inject, type InjectionKey } from 'vue';
import { router } from '@inertiajs/vue3';
import { createAdminExportCards } from '@/lib/adminExportCards';
import { createAdminExportTables } from '@/lib/adminExportTables';
import type {
    AdminExportProps,
    Recommendation,
    Option,
    GameOption,
    ExportPreset,
    PredictionExport,
    FuturesExport,
    TournamentExport,
} from '@/types/admin-exports';
import {
    getConfidenceColor,
    getSignalBand,
    formatOdds,
    getInitials,
    formatStatNumber,
    buildRelativeSignalMap,
    buildStatRows,
    normalizePercent,
    buildPredictionRows,
    buildFuturesRows,
    buildTournamentRows,
} from '@/lib/adminExportPresentation';

export function useAdminExports(props: AdminExportProps) {
    const activeTab = ref<'props' | 'predictions' | 'futures' | 'tournament'>(
        props.activeTab ?? 'props',
    );
    const selectedSport = ref(props.filters.sport ?? 'NBA');
    const selectedDate = ref(props.filters.date ?? '');
    const selectedGame = ref(
        props.filters.game ? String(props.filters.game) : '',
    );
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
            ((activeTab.value === 'futures' ||
                activeTab.value === 'tournament') &&
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
            (activeTab.value === 'futures' ||
                activeTab.value === 'tournament') &&
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

    const setTab = (
        tab: 'props' | 'predictions' | 'futures' | 'tournament',
    ) => {
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
        futuresSignalMap.value[row.id] ??
        Math.round(row.playoff_make_probability);
    const signalForTournament = (row: TournamentExport) =>
        tournamentSignalMap.value[row.id] ??
        Math.round(row.tournament_make_probability);

    const cards = createAdminExportCards({
        activePreset,
        adSafeMode,
        selectedSport,
        signalForProp,
        signalForPrediction,
        signalForFutures,
        signalForTournament,
    });
    const {
        exportRecommendationsTable,
        exportPredictionsTable,
        exportFuturesTable,
        exportTournamentsTable,
    } = createAdminExportTables({
        props,
        adSafeMode,
        selectedSport,
        signalForProp,
        signalForPrediction,
        signalForFutures,
    });
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
            await exportTournamentsTable();
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

    return {
        activeTab,
        selectedSport,
        selectedDate,
        selectedGame,
        selectedMarket,
        selectedSeason,
        selectedPreset,
        adSafeMode,
        exportingAll,
        presets,
        activeRowsCount,
        filteredGames,
        filteredDates,
        seasonOptions,
        hasActiveFilters,
        applyFilters,
        clearFilters,
        onDateChange,
        setTab,
        signalForProp,
        signalForPrediction,
        signalForFutures,
        signalForTournament,
        exportAllCurrent,
        activeTabMeta,
        getConfidenceColor,
        getSignalBand,
        formatOdds,
        getInitials,
        formatStatNumber,
        buildStatRows,
        buildPredictionRows,
        buildFuturesRows,
        buildTournamentRows,
        props,
        ...cards,
    };
}
export const adminExportsKey: InjectionKey<ReturnType<typeof useAdminExports>> =
    Symbol('adminExports');
export function useAdminExportsContext() {
    const context = inject(adminExportsKey);
    if (!context)
        throw new Error('Admin export sections require their page context');
    return context;
}
