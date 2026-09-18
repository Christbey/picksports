// Synthetic fixtures only. Browser checks must never start research or touch production data.
import { ref } from 'vue';

const team = (id, abbreviation, display_name) => ({
    id,
    abbreviation,
    display_name,
    name: display_name,
    logo:
        'data:image/svg+xml,' +
        encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><circle cx="32" cy="32" r="28" fill="#167ac6"/></svg>',
        ),
    active_injuries: [],
});
const away = team(1, 'DET', 'Detroit Lions');
const home = team(2, 'BUF', 'Buffalo Bills');
const windowData = (sample, label) => ({
    sample_size: sample,
    trends: { scoring: ['Scored 25+ in three of the last five games.'] },
    scored_signals: [],
    locked_trends: {},
    evidence: {
        label,
        sample_size: sample,
        seasons: [2025, 2026],
        from_date: '2025-12-15',
        through_date: '2026-09-13',
        latest_source_update: null,
        record: {
            wins: Math.min(3, sample),
            losses: Math.max(0, sample - 3),
            ties: 0,
        },
        metrics: { points_for: { value: 28, sample_size: sample } },
    },
});
const profile = {
    ...windowData(5, 'Last 5 games'),
    team_evidence: {
        windows: {
            recent_5: windowData(5, 'Last 5 games'),
            recent_10: windowData(10, 'Last 10 games'),
            season: windowData(1, 'This season'),
            historical: windowData(30, 'Previous 3 seasons'),
        },
        changes: [],
    },
};
const prediction = {
    predicted_spread: 3.5,
    predicted_total: 48.5,
    win_probability: 0.63,
    away_elo: 1500,
    home_elo: 1600,
    betting_value: [],
};
const starters = (side) => ({
    team: side,
    entries: Array.from({ length: 26 }, (_, i) => ({
        position_slot_key: `slot-${i}`,
        position_code: 'WR',
        depth_rank: 1,
        player_id: i,
        is_starter: true,
        full_name: `${side.abbreviation} Starter ${i + 1}`,
        stats: { games_played: 1, metrics: [] },
    })),
});
export const revision = {
    id: 1,
    created_at: '2026-09-17T20:00:00Z',
    baseline: prediction,
    revised: prediction,
    brief: {
        eligibility: {
            status: 'hold',
            reasons: ['weather_stale', 'research_candidate_changed'],
        },
        supporting: [
            {
                claim: 'Verified supporting claim.',
                interpretation: 'Detailed context. '.repeat(80),
                source_url: 'https://example.com/evidence',
            },
        ],
        opposing: [
            {
                claim: 'Verified counterargument.',
                interpretation: 'Evidence remains visible on demand.',
                source_url: null,
            },
        ],
        prop_angles: [],
        unresolved: [],
    },
};
export function useFixturePage() {
    const phase =
        typeof window === 'undefined'
            ? 'final'
            : new URLSearchParams(window.location.search).get('phase');
    const status =
        phase === 'live'
            ? 'STATUS_IN_PROGRESS'
            : phase === 'scheduled'
              ? 'STATUS_SCHEDULED'
              : 'STATUS_FINAL';
    return {
        pageProps: ref({
            title: 'DET @ BUF',
            breadcrumbs: [],
            loading: false,
            awayTeam: away,
            homeTeam: home,
            game: {
                id: 1722,
                status,
                game_date: '2026-09-17',
                away_score: 31,
                home_score: 41,
            },
            gameStatus:
                phase === 'live'
                    ? 'In Progress'
                    : phase === 'scheduled'
                      ? 'Scheduled'
                      : 'Final',
            showScoreStatuses: ['STATUS_FINAL', 'STATUS_IN_PROGRESS'],
            venueLabel: 'Highmark Stadium',
            broadcastNetworks: ['Prime Video'],
            extraInfoItems: ['Regular Season - Week 2'],
            formatDate: () => 'September 17, 2026',
            teamLink: (id) => `/nfl/teams/${id}`,
            gradientClass: 'from-blue-500 to-blue-900',
            showLinescore: true,
            awayLinescores: [{ value: 7 }],
            homeLinescores: [{ value: 14 }],
            awayScore: 31,
            homeScore: 41,
            showTrends: true,
            trendsTitle: 'Trends & Matchup History',
            trendsLoading: false,
            allTrendCategories: ['scoring'],
            formatCategoryName: (v) => v,
            isLockedCategory: () => false,
            formatTierName: (v) => v,
            getRequiredTier: () => 'free',
            awayLabel: 'DET',
            homeLabel: 'BUF',
            awayTrends: profile,
            homeTrends: profile,
        }),
        predictionSectionProps: ref({
            section: 'prediction',
            prediction,
            awayLabel: 'DET',
            homeLabel: 'BUF',
        }),
        analysisSectionProps: ref({
            section: 'analysis',
            prediction,
            awayLabel: 'DET',
            homeLabel: 'BUF',
            awayTeamStats: {
                total_yards: 321,
                passing_completions: 18,
                passing_attempts: 30,
                interceptions: 1,
                fumbles_lost: 0,
                time_of_possession: '29:12',
            },
            homeTeamStats: {
                total_yards: 432,
                passing_completions: 24,
                passing_attempts: 32,
                interceptions: 0,
                fumbles_lost: 0,
                time_of_possession: '30:48',
            },
        }),
        recentSectionProps: ref({
            section: 'recent',
            awayLabel: 'DET',
            homeLabel: 'BUF',
            awayTeamId: 1,
            homeTeamId: 2,
        }),
        depthCharts: ref({
            away_team: starters(away),
            home_team: starters(home),
        }),
    };
}
