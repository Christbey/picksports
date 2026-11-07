export interface Bet {
    id: number;
    prediction_id: number | string | null;
    prediction_sport: string | null;
    bet_amount: number;
    odds: string;
    bet_type: string;
    selection_side: string | null;
    selection_label: string | null;
    line: number | null;
    result: 'pending' | 'won' | 'lost' | 'push';
    profit_loss: number | null;
    notes: string | null;
    placed_at: string;
    settled_at: string | null;
}

export interface Statistics {
    total_bets: number;
    total_wagered: number;
    wins: number;
    losses: number;
    pushes: number;
    win_rate: number;
    total_profit: number;
    roi: number;
}

export interface BetsData {
    data: Bet[];
    links: any[];
    current_page: number;
    last_page: number;
}

export interface UserBetsResponse {
    bets: BetsData;
    statistics: Statistics;
}
