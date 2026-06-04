import type { QueryParams } from '@/wayfinder';

export type ApiV2SportSlug =
    | 'nba'
    | 'wnba'
    | 'nfl'
    | 'cfb'
    | 'mlb'
    | 'cbb'
    | 'wcbb'
    | (string & {});

export type ApiV2Record = Record<string, unknown>;

export type ApiV2Id = string | number;

export type ApiV2AccessTier = 'free' | 'premium' | 'admin' | (string & {});

export type ApiV2FreshnessStatus =
    | 'fresh'
    | 'stale'
    | 'missing'
    | 'unknown'
    | (string & {});

export type ApiV2Query = QueryParams;

export type ApiV2PaginationMeta = {
    current_page?: number;
    from?: number | null;
    last_page?: number;
    links?: unknown[];
    path?: string;
    per_page?: number;
    to?: number | null;
    total?: number;
};

export type ApiV2FreshnessMeta = {
    checked_at?: string | null;
    latest_data_at?: string | null;
    stale_after_minutes?: number | null;
    status?: ApiV2FreshnessStatus;
    warnings?: string[];
    [key: string]: unknown;
};

export type ApiV2Meta = {
    version?: 'v2' | string;
    sport?: ApiV2SportSlug;
    contract?: string;
    filters?: ApiV2Record;
    pagination?: ApiV2PaginationMeta;
    tier?: ApiV2AccessTier | ApiV2Record;
    freshness?: ApiV2FreshnessMeta;
    warnings?: string[];
    generated_at?: string;
    [key: string]: unknown;
};

export type ApiV2CollectionResponse<T, TMeta extends ApiV2Meta = ApiV2Meta> = {
    data: T[];
    meta: TMeta;
};

export type ApiV2ItemResponse<T, TMeta extends ApiV2Meta = ApiV2Meta> = {
    data: T;
    meta?: TMeta;
};

export type ApiV2TeamSummary = {
    id: ApiV2Id;
    espn_id?: string | number | null;
    abbreviation?: string | null;
    location?: string | null;
    name?: string | null;
    display_name?: string | null;
    short_display_name?: string | null;
    logo_url?: string | null;
    team_id?: ApiV2Id | null;
    position?: string | null;
    [key: string]: unknown;
};

export type ApiV2PlayerSummary = {
    id: ApiV2Id;
    team_id?: ApiV2Id | null;
    display_name?: string | null;
    full_name?: string | null;
    position?: string | null;
    [key: string]: unknown;
};

export type ApiV2GameSummary = {
    id: ApiV2Id;
    espn_id?: string | number | null;
    season?: number | string | null;
    season_type?: number | string | null;
    week?: number | string | null;
    name?: string | null;
    short_name?: string | null;
    game_date?: string | null;
    game_time?: string | null;
    status?: string | null;
    home_team_id?: ApiV2Id | null;
    away_team_id?: ApiV2Id | null;
    home_team?: ApiV2TeamSummary | null;
    away_team?: ApiV2TeamSummary | null;
    [key: string]: unknown;
};

export type ApiV2Sport = {
    slug: ApiV2SportSlug;
    name: string;
    label?: string;
    active?: boolean;
    free_access?: boolean;
    supports?: string[];
    [key: string]: unknown;
};

export type ApiV2Game = ApiV2GameSummary & {
    sport: ApiV2SportSlug;
    home_score?: number | null;
    away_score?: number | null;
    has_prediction?: boolean;
    updated_at?: string | null;
};

export type ApiV2Team = ApiV2TeamSummary & {
    sport: ApiV2SportSlug;
    nickname?: string | null;
    conference?: string | null;
    league?: string | null;
    division?: string | null;
    color?: string | null;
    alternate_color?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
};

export type ApiV2Player = ApiV2PlayerSummary & {
    sport: ApiV2SportSlug;
    espn_id?: string | number | null;
    first_name?: string | null;
    last_name?: string | null;
    jersey_number?: string | number | null;
    height?: string | number | null;
    weight?: string | number | null;
    age?: number | null;
    experience?: string | number | null;
    year?: string | number | null;
    college?: string | null;
    hometown?: string | null;
    status?: string | null;
    batting_hand?: string | null;
    throwing_hand?: string | null;
    headshot_url?: string | null;
    team?: ApiV2TeamSummary | null;
    created_at?: string | null;
    updated_at?: string | null;
};

export type ApiV2Prediction = {
    id: ApiV2Id;
    sport: ApiV2SportSlug;
    game_id?: ApiV2Id | null;
    game?: ApiV2GameSummary | null;
    status?: string | null;
    pick?: ApiV2Record;
    projection?: ApiV2Record;
    market_summary?: ApiV2Record;
    created_at?: string | null;
    updated_at?: string | null;
    [key: string]: unknown;
};

export type ApiV2Stat = {
    id: ApiV2Id;
    sport: ApiV2SportSlug;
    type?: 'team' | 'player' | string;
    game_id?: ApiV2Id | null;
    team_id?: ApiV2Id | null;
    player_id?: ApiV2Id | null;
    stat_type?: string | null;
    team_type?: string | null;
    season?: number | string | null;
    season_type?: number | string | null;
    game_date?: string | null;
    game?: ApiV2GameSummary | null;
    team?: ApiV2TeamSummary | null;
    player?: ApiV2PlayerSummary | null;
    stats?: ApiV2Record;
    created_at?: string | null;
    updated_at?: string | null;
    [key: string]: unknown;
};

export type ApiV2TeamMetric = {
    id: ApiV2Id;
    sport: ApiV2SportSlug;
    team_id?: ApiV2Id | null;
    season?: number | string | null;
    season_type?: number | string | null;
    team?: ApiV2TeamSummary | null;
    calculation_date?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    [key: string]: unknown;
};

export type ApiV2PlayerLeaderboardRow = {
    player_id?: ApiV2Id | null;
    player?: ApiV2Record | null;
    games_played?: number | string | null;
    [key: string]: unknown;
};

export type ApiV2PlayerProp = {
    id: ApiV2Id;
    sport: ApiV2SportSlug;
    game_id?: ApiV2Id | null;
    player_id?: ApiV2Id | null;
    player_name?: string | null;
    market?: string | null;
    bookmaker?: string | null;
    line?: number | null;
    over_price?: number | null;
    under_price?: number | null;
    prices?: {
        over?: number | null;
        under?: number | null;
        [key: string]: unknown;
    };
    recommendation?: {
        side?: 'Over' | 'Under' | 'over' | 'under' | string | null;
        confidence_score?: number | null;
        predicted_over_probability?: number | null;
        market_over_probability?: number | null;
        edge_probability?: number | null;
        data_quality_score?: number | null;
        match_quality_score?: number | null;
        context_adjustment_factor?: number | null;
        [key: string]: unknown;
    };
    grading?: {
        actual_value?: number | null;
        hit_over?: boolean | null;
        error?: number | null;
        graded_at?: string | null;
        [key: string]: unknown;
    };
    player?: ApiV2PlayerSummary | null;
    game?: ApiV2GameSummary | null;
    fetched_at?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    [key: string]: unknown;
};

export type ApiV2FuturesOdd = {
    id: ApiV2Id;
    sport: ApiV2SportSlug;
    season?: number | string | null;
    odds_api_sport_key?: string | null;
    event_id?: string | number | null;
    event_name?: string | null;
    commence_time?: string | null;
    bookmaker?: string | null;
    market_key?: string | null;
    market_last_update?: string | null;
    outcome?: {
        name?: string | null;
        description?: string | null;
        point?: number | null;
        price?: number | null;
        implied_probability?: number | null;
        [key: string]: unknown;
    };
    entity?: ApiV2TeamSummary | ApiV2PlayerSummary | null;
    fetched_at?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    [key: string]: unknown;
};

export type ApiV2PayloadInspector = {
    endpoint: string;
    status: 'pass' | 'warn' | 'fail' | string;
    sport?: ApiV2SportSlug;
    keys?: string[];
    warnings?: string[];
    missing_keys?: string[];
    unexpected_keys?: string[];
    sample?: ApiV2Record;
    [key: string]: unknown;
};
