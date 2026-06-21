import type { MlbDailyPick } from '@/types/mlb-daily-picks';

const TRACKING_LABELS: Record<string, string> = {
    bet_candidate: 'Strong Candidate',
    lean_candidate: 'Lean Candidate',
    watch: 'Watchlist',
    no_play: 'No Play',
    tracking_only: 'Tracking Only',
    candidate: 'Candidate',
    promoted: 'Validated',
};

export function safeMlbPickStatus(candidate: MlbDailyPick): string {
    if (candidate.is_tracking_only || !candidate.is_public) {
        const tier =
            TRACKING_LABELS[candidate.internal_candidate_label ?? ''] ??
            tierFromScore(candidate.score);

        return `${tier} - Tracking Only`;
    }

    return TRACKING_LABELS[candidate.recommendation_label] ?? 'Validated';
}

export function tierFromScore(score: number): string {
    if (score >= 80) return 'Strong Candidate';
    if (score >= 68) return 'Lean Candidate';
    if (score >= 58) return 'Watchlist';
    return 'No Play';
}

export function safeMlbBoardMode(mode?: string | null): string {
    if (mode === 'validated') return 'Validated';

    return 'Tracking Only';
}

export function labelizeMlbCode(value?: string | null): string {
    if (!value) return '';

    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase())
        .replace(/\bBetting\b/g, 'Pick')
        .replace(/\bBet\b/g, 'Pick');
}
