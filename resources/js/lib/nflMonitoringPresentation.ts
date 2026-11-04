import type {
    Observation,
    EvaluationWindow,
} from '@/types/nfl-model-monitoring';
export function formatPercent(value: number | null, digits = 1): string {
    return value === null ? 'N/A' : `${(value * 100).toFixed(digits)}%`;
}

export function formatNumber(
    value: number | null | undefined,
    digits = 3,
): string {
    return value === null || value === undefined
        ? 'N/A'
        : value.toFixed(digits);
}

export function formatSigned(
    value: number | null | undefined,
    digits = 3,
): string {
    if (value === null || value === undefined) return 'N/A';

    return `${value > 0 ? '+' : ''}${value.toFixed(digits)}`;
}

export function formatDate(value: string | null): string {
    if (!value) return 'N/A';

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

export function formatGameDate(value: string | null): string {
    if (!value) return 'Date pending';

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${value}T12:00:00Z`));
}

export function labelize(value: string | null | undefined): string {
    if (!value) return 'N/A';

    return value
        .replaceAll('_', ' ')
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function shortHash(value: string | null | undefined): string {
    if (!value) return 'N/A';

    return value.length > 18
        ? `${value.slice(0, 9)}...${value.slice(-7)}`
        : value;
}

export function artifactStatusClass(status: string): string {
    if (status === 'promoted') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }
    if (status === 'promotion_eligible') {
        return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';
}

export function decisionStatusClass(observation: Observation): string {
    if (observation.settlement?.status === 'win') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }
    if (observation.settlement?.status === 'loss') {
        return 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300';
    }
    if (observation.decision?.is_bet) {
        return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    return 'border-border bg-muted text-muted-foreground';
}

export function decisionLabel(observation: Observation): string {
    if (observation.settlement) {
        return labelize(observation.settlement.status);
    }
    if (!observation.decision) return 'Awaiting Decision';

    return observation.decision.is_bet ? 'Tracking Bet' : 'No Bet';
}

export function decisionReason(observation: Observation): string {
    const reasons = observation.decision?.eligibility_reasons ?? [];
    if (reasons.length > 0) return reasons.map(labelize).join(', ');
    if (observation.decision?.is_bet) return 'Promotion and edge gates passed';

    return 'Decision pending';
}

export function outcomeTone(delta: number | null | undefined): string {
    if (delta === null || delta === undefined || delta === 0) {
        return 'text-muted-foreground';
    }

    return delta > 0
        ? 'text-emerald-700 dark:text-emerald-300'
        : 'text-rose-700 dark:text-rose-300';
}

export function evaluationSeason(window: EvaluationWindow): number | undefined {
    return window.evaluation_season ?? window.test_season;
}

export function improvementTone(value: number | null | undefined): string {
    if (value === null || value === undefined || value === 0) {
        return 'text-muted-foreground';
    }

    return value > 0
        ? 'text-emerald-700 dark:text-emerald-300'
        : 'text-rose-700 dark:text-rose-300';
}
