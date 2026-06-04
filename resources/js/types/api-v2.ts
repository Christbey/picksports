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
    filters?: ApiV2Record;
    pagination?: ApiV2PaginationMeta;
    tier?: ApiV2AccessTier;
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

export type ApiV2Entity<TAttributes extends ApiV2Record = ApiV2Record> = {
    id: ApiV2Id;
    type?: string;
    attributes: TAttributes;
    relationships?: ApiV2Record;
    links?: ApiV2Record;
};

export type ApiV2Sport = ApiV2Entity<{
    slug: ApiV2SportSlug;
    name: string;
    active?: boolean;
    free_access?: boolean;
    supports?: string[];
    [key: string]: unknown;
}>;

export type ApiV2Game = ApiV2Entity<{
    sport?: ApiV2SportSlug;
    game_date?: string | null;
    game_time?: string | null;
    starts_at?: string | null;
    status?: string | null;
    season?: number | string | null;
    season_type?: number | string | null;
    home_team?: string | null;
    away_team?: string | null;
    home_score?: number | null;
    away_score?: number | null;
    [key: string]: unknown;
}>;

export type ApiV2Team = ApiV2Entity<{
    name?: string | null;
    abbreviation?: string | null;
    market?: string | null;
    conference?: string | null;
    division?: string | null;
    [key: string]: unknown;
}>;

export type ApiV2Player = ApiV2Entity<{
    name?: string | null;
    display_name?: string | null;
    position?: string | null;
    team?: string | null;
    status?: string | null;
    [key: string]: unknown;
}>;

export type ApiV2Prediction = ApiV2Entity<{
    game_id?: ApiV2Id;
    predicted_winner?: string | null;
    confidence?: number | null;
    edge?: number | null;
    recommendation?: string | null;
    graded_at?: string | null;
    result?: string | null;
    [key: string]: unknown;
}>;

export type ApiV2Stat = ApiV2Entity<{
    subject_type?: 'team' | 'player' | string;
    subject_id?: ApiV2Id;
    season?: number | string | null;
    game_id?: ApiV2Id | null;
    stats?: ApiV2Record;
    [key: string]: unknown;
}>;

export type ApiV2PlayerProp = ApiV2Entity<{
    game_id?: ApiV2Id | null;
    player_id?: ApiV2Id | null;
    player_name?: string | null;
    market?: string | null;
    line?: number | null;
    over_price?: number | null;
    under_price?: number | null;
    prices?: {
        over?: number | null;
        under?: number | null;
        [key: string]: unknown;
    };
    starts_at?: string | null;
    [key: string]: unknown;
}>;

export type ApiV2FuturesOdd = ApiV2Entity<{
    market?: string | null;
    outcome?: string | null;
    entity?: {
        id?: ApiV2Id | null;
        type?: string | null;
        name?: string | null;
        [key: string]: unknown;
    };
    price?: number | null;
    decimal_price?: number | null;
    implied_probability?: number | null;
    sportsbook?: string | null;
    last_seen_at?: string | null;
    [key: string]: unknown;
}>;

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
