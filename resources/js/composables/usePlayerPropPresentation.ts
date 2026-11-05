import type { Ref } from 'vue';
import { formatCoverCount, propFreshness } from '@/lib/playerPropPresentation';
import type { CoverRecord, Recommendation } from '@/lib/playerPropTypes';
export function usePlayerPropPresentation(
    props: { sport: string },
    nowMs: Ref<number>,
) {
    const getConfidenceColor = (rec: Recommendation) => {
        const tier = rec.confidence_decomposition?.signal_quality?.tier;
        if (tier === 'very_strong') return 'bg-green-500';
        if (tier === 'strong') return 'bg-emerald-500';
        if (rec.confidence >= 60) return 'bg-yellow-500';
        return 'bg-gray-500';
    };

    const quoteStatus = (rec: Recommendation) =>
        propFreshness(rec.fetched_at, rec.freshness_hours, nowMs.value);

    const getSignalBand = (rec: Recommendation) => {
        const label = rec.confidence_decomposition?.signal_quality?.label;
        if (label) return label;

        const confidence = rec.confidence;
        const dataQuality = Number(rec.data_quality_score ?? 0);
        if (confidence >= 88 && dataQuality >= 85) return 'Very Strong';
        if (confidence >= 75 && dataQuality >= 75) return 'Strong';
        if (confidence >= 60) return 'Lean';
        return 'Low';
    };

    const getSignalBandVariant = (rec: Recommendation) => {
        const tier = rec.confidence_decomposition?.signal_quality?.tier;
        if (tier === 'very_strong') return 'default';
        if (tier === 'strong') return 'secondary';
        if (rec.confidence >= 60) return 'outline';
        return 'outline';
    };

    const getCoverRecordRows = (rec: Recommendation) => {
        const record = rec.stats?.cover_record;
        if (!record) return [];

        const season = rec.stats.season_summary?.season;
        const rows: [string, CoverRecord | null | undefined][] = [
            [
                props.sport === 'NFL'
                    ? `${rec.stats.season_summary?.season ?? 'Current'} season`
                    : 'Historical',
                record.season,
            ],
            ...(props.sport === 'NFL'
                ? ([
                      [
                          `${season ? season - 1 : 'Last'} season`,
                          record.last_season,
                      ],
                      ['All available', record.all_time],
                      [
                          `vs ${rec.stats.season_summary?.opponent ?? 'opponent'}`,
                          record.vs_opponent,
                      ],
                      [
                          `vs ${rec.stats.season_summary?.opponent_conference ?? 'conference'}`,
                          record.vs_conference,
                      ],
                  ] as [string, CoverRecord | null | undefined][])
                : []),
            ['Last 17', record.historical_last_17],
            ['Last 10', record.last_10],
            ['Last 5', record.last_5],
            ['Home/Away', record.home_away],
            ...(props.sport !== 'NFL'
                ? [
                      ['vs Opponent', record.vs_opponent] as [
                          string,
                          CoverRecord | null,
                      ],
                  ]
                : []),
        ];
        return rows.filter(
            (row, index) =>
                (props.sport === 'NFL' && index < 5) || row[1] != null,
        );
    };

    const formatCoverRecord = (
        record: CoverRecord | null | undefined,
        recommendation: 'Over' | 'Under',
    ) => formatCoverCount(record, recommendation);

    const formatCoverRate = (record: CoverRecord | null | undefined) =>
        record?.win_rate == null ? 'N/A' : `${record.win_rate.toFixed(1)}%`;

    const getInitials = (name: string) =>
        name
            .split(' ')
            .filter(Boolean)
            .map((part) => part[0])
            .join('')
            .toUpperCase();

    const getResultStatus = (rec: Recommendation) => {
        if (
            !rec.graded_at ||
            rec.actual_value === null ||
            rec.actual_value === undefined
        ) {
            return {
                label: 'Pending',
                variant: 'outline' as const,
                className: 'text-muted-foreground border-border',
            };
        }

        const actual = Number(rec.actual_value ?? 0);
        const line = Number(rec.line ?? 0);
        if (Math.abs(actual - line) < 0.0001) {
            return {
                label: 'Push',
                variant: 'secondary' as const,
                className: '',
            };
        }

        const didGoOver = Boolean(rec.hit_over);
        const won =
            (rec.recommendation === 'Over' && didGoOver) ||
            (rec.recommendation === 'Under' && !didGoOver);

        if (won) {
            return {
                label: 'Won',
                variant: 'default' as const,
                className: 'bg-emerald-600 hover:bg-emerald-600 text-white',
            };
        }

        return {
            label: 'Lost',
            variant: 'destructive' as const,
            className: '',
        };
    };

    return {
        getConfidenceColor,
        quoteStatus,
        getSignalBand,
        getSignalBandVariant,
        getCoverRecordRows,
        formatCoverRecord,
        formatCoverRate,
        getInitials,
        getResultStatus,
    };
}
export type PlayerPropPresentation = ReturnType<
    typeof usePlayerPropPresentation
>;
