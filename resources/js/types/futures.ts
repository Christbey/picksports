export type MarketOdds = {
    bookmaker: string;
    market_key: string;
    price: number | null;
    implied_probability: number | null;
    fetched_at: string | null;
    odds_api_sport_key: string;
};
export type MarketEdge = {
    model_probability: number | null;
    market_probability: number | null;
    edge_probability: number | null;
    edge_percent_points: number | null;
    fair_price: number | null;
    market_price: number | null;
    has_edge: boolean;
};
