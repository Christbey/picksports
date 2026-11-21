export type GameResearchFinding = {
    claim?: string;
    interpretation?: string;
    topic?: string;
    team_side?: string;
    source_url?: string | null;
    evidence_kind?: string;
    evidence_reference?: string;
};

export type GameResearchData = {
    schema_version: string;
    sport: string;
    game_id: number;
    status: 'ready' | 'partial' | 'unavailable';
    applicability: 'current' | 'outdated' | 'expired' | 'archived';
    assessed_at: string | null;
    expires_at: string | null;
    capabilities?: { can_retry: boolean };
    supporting: GameResearchFinding[];
    concerns: GameResearchFinding[];
    facts: GameResearchFinding[];
    unresolved: GameResearchFinding[];
    sources: {
        url: string;
        title?: string;
        published_at?: string | null;
        observed_at?: string | null;
    }[];
    season_evidence: Partial<
        Record<
            'home' | 'away',
            {
                sample_games?: number;
                games?: number;
                wins?: number;
                losses?: number;
                ties?: number;
                average_margin?: number | null;
                points_per_game?: number;
                points_allowed_per_game?: number;
            }
        >
    >;
    review: {
        status: string;
        reviewed_at?: string | null;
        summary?: string | null;
        context_summary?: string | null;
        key_factors?: string[];
        risk_flags?: string[];
        reason_code?: string | null;
    };
};
