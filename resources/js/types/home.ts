export interface OverallStats {
    total_predictions: number;
    winner_accuracy: number;
    avg_spread_error: number | null;
    avg_total_error: number | null;
    win_record: string;
}

export interface ROIStats {
    total_bets: number;
    priced_bets?: number;
    total_wins: number;
    total_losses: number;
    total_wagered: number;
    total_profit: number | null;
    roi_percentage: number | null;
    win_percentage: number | null;
}

export interface PerformanceData {
    overall: OverallStats;
    recent: {
        overall: OverallStats;
        roi: ROIStats;
    };
    roi: ROIStats;
}
