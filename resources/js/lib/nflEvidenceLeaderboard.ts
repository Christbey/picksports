export type EvidenceMetrics = {
    games: number;
    sample: number;
    wins: number;
    losses: number;
    pushes: number;
    no_pick?: number;
    ungraded: number;
    hit_rate: number | null;
    wilson_lower: number | null;
    wilson_upper: number | null;
    paired_baseline_sample: number;
    baseline_lift: number | null;
    mean_absolute_error: number | null;
    brier: number | null;
};

export type EvidenceRow = EvidenceMetrics & {
    condition_state?: string;
    control_comparison?: {
        present_games: number;
        control_games: number;
        common_strata: number;
        comparison_improvement: number | null;
        status: string;
    } | null;
    partial_overlap?: Array<{
        signal_key: string;
        shared_games: number;
        jaccard: number;
    }>;
    signal_type: string;
    signal_key: string;
    label: string;
    model_version: string;
    feature_version: string;
    blend_version: string;
    definition_hash: string;
    config_hash: string;
    direction: string | null;
    market: string;
    status: string;
    validated: boolean;
    bet_eligible: boolean;
    direction_basis: string[];
    discovery: EvidenceMetrics;
    later: EvidenceMetrics;
    weeks: Array<EvidenceMetrics & { window: string }>;
    cohorts: Record<string, EvidenceMetrics>;
    identical_sample_signals: string[];
};

export type EvidenceLeaderboard = {
    scope: {
        from_season: number;
        to_season: number;
        model_version: string;
        cohort: string;
        validation_from_week: number;
    };
    minimum_games: number;
    selected_observations: number;
    excluded_kickoff_mismatch: number;
    rows: EvidenceRow[];
    inventory: Array<{
        evidence: string;
        collection: string;
        grading: string;
        gap: string;
    }>;
    operations?: {
        audit: {
            checked_at: string;
            reasons: Record<string, Record<string, number>>;
            freshness: Record<string, string | null>;
            games: Array<{
                game_id: number;
                matchup: string;
                market: string;
                reason: string;
                quote_status: string;
            }>;
            scheduler_heartbeats: Array<{
                command: string;
                status: string;
                ran_at: string;
            }>;
        };
        trials: Array<{
            artifact_id: string;
            candidate_contributions?: Record<
                string,
                Record<
                    string,
                    {
                        games: number;
                        mean_full_minus_ablated_improvement: number | null;
                    }
                >
            >;
            protocol?: {
                name: string;
                market: string;
                discovery_end: string;
                validation_start: string;
                validation_end: string;
            };
            status: string;
            promoted: boolean;
            periods?: Record<
                string,
                {
                    games: number;
                    weeks: number;
                    mean_improvement: number | null;
                    simultaneous_lower_bound: number | null;
                }
            >;
            checks?: Record<string, boolean>;
        }>;
        phase_status: Record<string, string>;
    };
    limitations: string[];
};

export function evidenceRows(
    report: EvidenceLeaderboard,
    market: string,
    cohort: string,
    search: string,
    state = 'present',
): EvidenceRow[] {
    const term = search.trim().toLowerCase();
    return report.rows
        .filter(
            (row) =>
                (state === 'all' ||
                    (state === 'present'
                        ? ['triggered', 'matched'].includes(
                              row.condition_state ?? 'triggered',
                          )
                        : state === 'unavailable'
                          ? !['triggered', 'matched', 'not_matched'].includes(
                                row.condition_state ?? 'triggered',
                            )
                          : row.condition_state === state)) &&
                row.market === market &&
                `${row.label} ${row.signal_key}`.toLowerCase().includes(term),
        )
        .flatMap((row) => {
            if (cohort === 'all') return [row];
            const metrics = row.cohorts[cohort];
            return metrics ? [{ ...row, ...metrics }] : [];
        })
        .sort(
            (a, b) =>
                Number(b.sample >= report.minimum_games) -
                    Number(a.sample >= report.minimum_games) ||
                (b.wilson_lower ?? -1) - (a.wilson_lower ?? -1) ||
                b.sample - a.sample ||
                a.signal_key.localeCompare(b.signal_key),
        );
}
