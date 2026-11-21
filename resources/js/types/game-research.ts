export type StructuredResearchProvenance = {
    provider?: string;
    publisher?: string;
    source_label?: string;
    source_scope?: string;
    parser_version?: string;
    snapshot_id?: number;
    observation_id?: number;
    payload_hash?: string;
    material_hash?: string;
    observed_at?: string | null;
    published_at?: string | null;
};

export type GameResearchFinding = StructuredResearchProvenance & {
    json_pointer?: string;
    raw_status?: string | null;
    normalized_status?: string;
    espn_athlete_id?: string;
    source_time_field?: string | null;
    source_time_raw?: string | null;
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
    sources: (StructuredResearchProvenance & {
        url: string;
        title?: string;
        published_at?: string | null;
        observed_at?: string | null;
    })[];
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
