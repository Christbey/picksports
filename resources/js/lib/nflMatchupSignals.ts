export interface MatchupMetricEvidence {
    value: number | null;
    rank: number | null;
    games: number;
    plays?: number | null;
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
        metric: string;
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
