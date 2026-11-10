import type { DashboardPrediction, PredictionListItem } from '@/types';
export interface PredictionCardProps {
    prediction: DashboardPrediction | PredictionListItem;
    href: string;
    sport?: string;
    compact?: boolean;
    deferTeamLogos?: boolean;
}
export interface SavePickOption {
    betType: 'spread' | 'moneyline' | 'total_over' | 'total_under';
    selectionSide: 'home' | 'away' | 'over' | 'under';
    teamLabel: string;
    title: string;
    defaultLine: number | null;
}

export interface TrackedBet {
    id: number;
    bet_amount: number;
    odds: string;
    bet_type: SavePickOption['betType'];
    selection_side: SavePickOption['selectionSide'] | null;
    selection_label: string | null;
    line: number | null;
    notes: string | null;
}

export interface TrackingMarketSummary {
    total_picks: number;
    leader: {
        side: SavePickOption['selectionSide'];
        count: number;
        percent: number;
    } | null;
    sides: Partial<
        Record<
            SavePickOption['selectionSide'],
            {
                count: number;
                percent: number;
            }
        >
    >;
}

export interface TrackingSummary {
    markets: Record<'moneyline' | 'spread' | 'total', TrackingMarketSummary>;
    user_bets: TrackedBet[];
}

export interface UserBetsTrackingResponse {
    tracking: TrackingSummary | null;
}

export interface PublicConsensus {
    summary: string;
    detail: string;
}

export interface CfbSignalBadge {
    label: string;
    tone: 'neutral' | 'positive' | 'watch';
}
