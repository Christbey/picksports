import type { SignalRow } from '@/types/mlb-signals';

export function formatPercent(value?: number | null): string | null {
    if (value == null) return null;
    return `${Math.round(value * 100)}%`;
}

export function formatRuns(value?: number | null): string | null {
    if (value == null) return null;
    return `${value.toFixed(1)} runs`;
}

export function formatSignedRuns(value?: number | null): string | null {
    if (value == null) return null;
    return `${value >= 0 ? '+' : ''}${value.toFixed(1)} runs`;
}

export function formatNumber(value?: number | null): string {
    if (value == null) return '-';
    return value.toFixed(1);
}

export function formatLiveScore(row: SignalRow): string {
    const score =
        row.away_score != null && row.home_score != null
            ? `${row.away_score}-${row.home_score}`
            : 'score pending';
    const inning =
        row.inning != null
            ? `${labelize(row.inning_state)} ${row.inning}`.trim()
            : labelize(row.status);

    return `${score} ${inning}`.trim();
}

export function formatAmericanOdds(value?: number | null): string {
    if (value == null) return '-';
    return value > 0 ? `+${value}` : `${value}`;
}

export function formatProbabilityEdge(value?: number | null): string | null {
    if (value == null) return null;
    return `${value >= 0 ? '+' : ''}${(value * 100).toFixed(1)}%`;
}

export function labelize(value?: string | null): string {
    if (!value) return '';
    return value.replaceAll('_', ' ');
}

export function sideLabel(value?: string | null): string {
    if (!value) return '';
    return labelize(value);
}

export function primaryReason(row: SignalRow): string {
    return labelize(row.reason_codes?.[0]);
}

export function riskSummary(row: SignalRow): string {
    if (!row.risk_flags?.length) return 'no major risk flags';
    return row.risk_flags.slice(0, 2).map(labelize).join(' | ');
}

export function pickLabel(row: SignalRow): string {
    const market = labelize(row.type);
    const side = sideLabel(row.pick_side);

    return `${market}: ${side}`;
}

export function coverageLabel(value?: number): string {
    return value == null ? '0%' : `${value.toFixed(1)}%`;
}

export function classificationClass(value?: string | null): string {
    if (value === 'bet') {
        return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (value === 'lean') {
        return 'border-sky-500/40 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    return 'border-muted-foreground/30 bg-muted text-muted-foreground';
}

export function scoreBarClass(score?: number | null): string {
    if ((score ?? 0) >= 70) return 'bg-emerald-500';
    if ((score ?? 0) >= 55) return 'bg-sky-500';
    return 'bg-amber-500';
}
