import type { NflTeamStats } from '../types/sports';

type StatValue = { text: string; value: number | null };
type Stats = Partial<NflTeamStats>;

const numeric = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    if (typeof value === 'string' && !value.trim()) return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};
const scalar = (value: unknown): StatValue => {
    const number = numeric(value);
    return { text: number === null ? '—' : String(number), value: number };
};
const pair = (
    first: unknown,
    second: unknown,
    percentage = false,
): StatValue => {
    const a = numeric(first);
    const b = numeric(second);
    return {
        text: `${scalar(first).text}-${scalar(second).text}${percentage && a !== null && b !== null && b > 0 ? ` (${Math.round((a / b) * 100)}%)` : ''}`,
        value: a !== null && b !== null && b > 0 ? a / b : null,
    };
};
const turnovers = (stats: Stats): StatValue => {
    const interceptions = numeric(stats.interceptions);
    const fumbles = numeric(stats.fumbles_lost);
    const value =
        interceptions !== null && fumbles !== null
            ? interceptions + fumbles
            : null;
    return {
        text: `${scalar(value).text} (${scalar(interceptions).text} INT, ${scalar(fumbles).text} FUM)`,
        value,
    };
};
const possession = (value: unknown): StatValue => {
    if (typeof value !== 'string' || !/^\d+:\d{2}$/.test(value))
        return { text: '—', value: null };
    const [minutes, seconds] = value.split(':').map(Number);
    return seconds < 60
        ? { text: value, value: minutes * 60 + seconds }
        : { text: '—', value: null };
};

const fields: {
    label: string;
    read: (stats: Stats) => StatValue;
    lower?: boolean;
}[] = [
    { label: 'Total Yards', read: (s) => scalar(s.total_yards) },
    {
        label: 'Passing (C-A, Yds)',
        read: (s) => ({
            text: `${pair(s.passing_completions, s.passing_attempts).text}, ${scalar(s.passing_yards).text}`,
            value: numeric(s.passing_yards),
        }),
    },
    {
        label: 'Rushing (Att, Yds)',
        read: (s) => ({
            text: `${scalar(s.rushing_attempts).text}, ${scalar(s.rushing_yards).text}`,
            value: numeric(s.rushing_yards),
        }),
    },
    { label: 'First Downs', read: (s) => scalar(s.first_downs) },
    {
        label: '3rd Down',
        read: (s) =>
            pair(s.third_down_conversions, s.third_down_attempts, true),
    },
    {
        label: '4th Down',
        read: (s) => pair(s.fourth_down_conversions, s.fourth_down_attempts),
    },
    {
        label: 'Red Zone',
        read: (s) => pair(s.red_zone_scores, s.red_zone_attempts),
    },
    { label: 'Turnovers', read: turnovers, lower: true },
    {
        label: 'Sacks Allowed',
        read: (s) => scalar(s.sacks_allowed),
        lower: true,
    },
    {
        label: 'Penalties (Pen-Yds)',
        read: (s) => ({
            text: pair(s.penalties, s.penalty_yards).text,
            value: numeric(s.penalty_yards),
        }),
        lower: true,
    },
    {
        label: 'Time of Possession',
        read: (s) => possession(s.time_of_possession),
    },
];

export const nflBoxScoreRows = (away: Stats, home: Stats) =>
    fields.map((field) => {
        const a = field.read(away);
        const h = field.read(home);
        let better: 'home' | 'away' | null = null;
        if (a.value !== null && h.value !== null && a.value !== h.value) {
            better = (field.lower ? h.value < a.value : h.value > a.value)
                ? 'home'
                : 'away';
        }
        return { label: field.label, away: a.text, home: h.text, better };
    });
