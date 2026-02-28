import type { LineScoreEntry } from '@/types';

type GameRecord = {
    home_team_id: number;
    away_team_id: number;
    home_score: number | null;
    away_score: number | null;
};

const normalizeLineScoreEntry = (entry: unknown, index: number): LineScoreEntry | null => {
    if (typeof entry === 'number' || typeof entry === 'string') {
        return { period: index + 1, value: entry };
    }

    if (!entry || typeof entry !== 'object') return null;

    const obj = entry as Record<string, unknown>;
    const value = obj.value;
    if (typeof value !== 'number' && typeof value !== 'string') return null;

    const period = typeof obj.period === 'number' ? obj.period : index + 1;
    return { period, value };
};

export const parseLinescores = (linescores: unknown): LineScoreEntry[] => {
    if (!linescores) return [];

    const raw: unknown[] =
        Array.isArray(linescores)
            ? linescores
            : typeof linescores === 'string'
              ? (() => {
                    try {
                        const parsed = JSON.parse(linescores);
                        return Array.isArray(parsed) ? parsed : [];
                    } catch {
                        return [];
                    }
                })()
              : [];

    return raw
        .map((entry, index) => normalizeLineScoreEntry(entry, index))
        .filter((entry): entry is LineScoreEntry => entry !== null);
};

export const parseBroadcastNetworks = (value: unknown): string[] => {
    if (!value) return [];
    if (Array.isArray(value)) return value as string[];
    if (typeof value !== 'string') return [];
    try {
        return JSON.parse(value);
    } catch {
        return [];
    }
};

export const getRecentForm = (games: GameRecord[], teamId: number): string => {
    return games
        .map((g) => {
            const isHome = g.home_team_id === teamId;
            const teamScore = isHome ? g.home_score : g.away_score;
            const oppScore = isHome ? g.away_score : g.home_score;
            return teamScore && oppScore && teamScore > oppScore ? 'W' : 'L';
        })
        .join('-');
};

export const calculatePercentage = (made: number, attempted: number): string => {
    if (!attempted || attempted === 0) return '0.0';
    return ((made / attempted) * 100).toFixed(1);
};

export const formatVenueLabel = (
    venueName: string | null | undefined,
    venueCity?: string | null,
): string | null => {
    if (!venueName) return null;
    return `${venueName}${venueCity ? `, ${venueCity}` : ''}`;
};

export const getWinLossRecord = (games: GameRecord[], teamId: number): string => {
    const wins = games.filter((g) => {
        const isHome = g.home_team_id === teamId;
        const teamScore = isHome ? g.home_score : g.away_score;
        const oppScore = isHome ? g.away_score : g.home_score;
        return teamScore !== null && oppScore !== null && teamScore > oppScore;
    }).length;
    return `${wins}-${games.length - wins}`;
};
