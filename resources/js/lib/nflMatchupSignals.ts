export interface MatchupMetricEvidence {
    display_value?: string | null;
    player_name?: string | null;
    identity_status?: string;
    league_players?: number;
    value: number | null;
    rank: number | null;
    games: number;
    plays?: number | null;
    eligible?: boolean;
    rank_end?: number | null;
    scheduled_games?: number;
    through_date?: string | null;
    league_average?: number | null;
    sampling_interval?: { lower: number; upper: number } | null;
    recent_three?: { value: number; games: number; plays: number } | null;
}

export interface NflMatchupSignal {
    id: number;
    label: string;
    category: string;
    status: 'matched' | 'not_matched' | 'insufficient_data';
    offense_team_id: number;
    defense_team_id: number;
    reason: string | null;
    evidence: {
        personnel_only?: boolean;
        offense_only?: boolean;
        metric: string;
        epa_context?: {
            opponent_adjusted: {
                offense: MatchupMetricEvidence | null;
                defense: MatchupMetricEvidence | null;
                definition: string | null;
            };
            splits: Array<{
                metric: string;
                source?: string;
                offense: MatchupMetricEvidence | null;
                defense: MatchupMetricEvidence | null;
            }>;
        };
        definition?: string;
        source: string;
        offense: MatchupMetricEvidence;
        defense: MatchupMetricEvidence;
        league_teams: number;
    };
}

export interface NflSituationalRecord {
    id: string;
    catalog_id: number | null;
    record_type?: 'ats' | 'totals';
    label: string;
    definition: string;
    minimum_sample: number;
    sample_size: number;
    record: { wins: number; losses: number; ties: number };
    status: string;
    from_date: string | null;
    through_date: string | null;
    game_ids: number[];
}

export interface NflMatchupSignalData {
    role?: string;
    affects_prediction?: boolean;
    generated_at: string;
    matchup: {
        version: string;
        mode: string;
        season: number;
        target_season?: number;
        cutoff_at: string | null;
        baseline?: {
            mode: string;
            through_week: number;
            through_date: string;
            games: number;
            label: string;
        } | null;
        window: string;
        minimum_games: number;
        minimum_league_teams: number;
        summary: Record<string, number>;
        limitations?: string[];
        signals: NflMatchupSignal[];
        catalog: Array<{
            id: number;
            label: string;
            category: string;
            support: string;
            reason: string | null;
            definition?: string | null;
            required_inputs?: string[];
            prediction_effect?: string;
        }>;
    };
    situational: Record<
        string,
        {
            team_id: number | null;
            label: string;
            window: string;
            records: NflSituationalRecord[];
            market_records?: Record<string, { status: string; reason: string }>;
            limitations: string[];
        }
    >;
}

// Nested top-5/top-10 definitions are one metric family, not extra confirmations.
export function distinctMatchupSignals(signals: NflMatchupSignal[]) {
    const seen = new Set<string>();
    return signals.filter((signal) => {
        if (signal.status !== 'matched') return false;
        const key = `${signal.offense_team_id}:${signal.defense_team_id}:${signal.evidence.metric}`;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

export function signalStatus(status: string) {
    return (
        {
            matched: 'Condition met',
            not_matched: 'Condition not met',
            insufficient_data: 'Insufficient history',
            descriptive_record: 'Descriptive record',
            unavailable: 'Not evaluated — needs data or implementation',
        }[status] ?? status.replaceAll('_', ' ')
    );
}

export const matchupCategories: Record<string, string> = {
    overall: 'Overall offense / defense',
    passing: 'Passing',
    rushing: 'Rushing',
    offensive_line: 'Offensive line',
    quarterback_scheme: 'QB / defensive scheme',
    receivers_coverage: 'Receivers / coverage',
    situational_records: 'Situational records',
    travel_home_road: 'Travel / home / road',
};

export function matchupChecklist(data: NflMatchupSignalData) {
    const evaluations = new Map<number, NflMatchupSignal[]>();
    for (const signal of data.matchup.signals) {
        evaluations.set(signal.id, [
            ...(evaluations.get(signal.id) ?? []),
            signal,
        ]);
    }
    const records = new Map<
        number,
        Array<{ team: string; record: NflSituationalRecord }>
    >();
    for (const side of Object.values(data.situational)) {
        for (const record of side.records) {
            if (record.catalog_id == null) continue;
            records.set(record.catalog_id, [
                ...(records.get(record.catalog_id) ?? []),
                { team: side.label, record },
            ]);
        }
    }
    return data.matchup.catalog.map((entry) => {
        const signals = evaluations.get(entry.id) ?? [];
        const situations = records.get(entry.id) ?? [];
        // Roll-up is for browsing only; each team/direction keeps its own result.
        const status = signals.some((signal) => signal.status === 'matched')
            ? 'matched'
            : signals.some((signal) => signal.status === 'insufficient_data')
              ? 'insufficient_data'
              : signals.length
                ? 'not_matched'
                : situations.some(
                        ({ record }) => record.status === 'descriptive_record',
                    )
                  ? 'descriptive_record'
                  : situations.length
                    ? 'insufficient_data'
                    : 'unavailable';
        return { ...entry, status, signals, situations };
    });
}

export function defenseEvidenceText(sample: MatchupMetricEvidence): string {
    if (sample.display_value) return `Defense: ${sample.display_value}`;
    const value =
        sample.value == null || !Number.isFinite(sample.value)
            ? 'Unavailable'
            : sample.value.toFixed(3);
    return `Defense ${value} · rank ${sample.rank ?? '—'} · ${sample.games} games`;
}

export function epaInterpretation(signal: NflMatchupSignal): string | null {
    if (signal.evidence.metric !== 'epa') return null;
    const { offense, defense } = signal.evidence;
    if (
        signal.status === 'insufficient_data' ||
        offense.eligible === false ||
        defense.eligible === false ||
        offense.rank == null ||
        defense.rank == null
    ) {
        return 'EPA matchup unavailable: qualifying samples are incomplete.';
    }
    const band = (sample: MatchupMetricEvidence) => {
        const end = sample.rank_end ?? sample.rank!;
        if (end <= 5) return 'Top-5';
        if (end <= 10) return 'Top-10';
        if (sample.rank! >= 23) return 'Bottom-10';
        return 'Middle-ranked';
    };
    const o = band(offense),
        d = band(defense);
    const strong = (s: string) => s === 'Top-5' || s === 'Top-10';
    const note =
        strong(o) && strong(d)
            ? 'strength versus strength'
            : o === 'Bottom-10' && strong(d)
              ? 'potential offensive disadvantage'
              : strong(o) && d === 'Bottom-10'
                ? 'potential offensive advantage'
                : 'mixed efficiency profile';
    return `${o} offense meets ${d.toLowerCase()} defense; ${note}.`;
}
