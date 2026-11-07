import {
    ShieldCheck,
    CheckCircle2,
    Trophy,
    Gauge,
    Sparkles,
} from 'lucide-vue-next';
import type {
    MlbDailyPick,
    MlbDailyPerformanceRecord,
} from '@/types/mlb-daily-picks';
export function formatDate(value?: string | null): string {
    if (!value) return 'Today';

    return new Intl.DateTimeFormat(undefined, {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
}

export function formatPercent(value?: number | null): string {
    if (value == null) return 'Pending';

    return `${Math.round(value * 100)}%`;
}

export function formatRecord(
    record?: MlbDailyPerformanceRecord | null,
): string {
    if (!record) return 'Collecting';

    return `${record.wins}-${record.losses}-${record.pushes}`;
}

export function formatHitRate(
    record?: MlbDailyPerformanceRecord | null,
): string {
    if (!record?.hit_rate) return 'Unlocking';

    return `${(record.hit_rate * 100).toFixed(1)}%`;
}

export function achievementIcon(key: string) {
    if (key === 'clean_slate') return ShieldCheck;
    if (key === 'consensus_board') return CheckCircle2;
    if (key === 'high_signal_day') return Trophy;
    if (key === 'no_force_picks') return Gauge;

    return Sparkles;
}

export function safeTrackingCopy(value?: string | null): string {
    if (!value) return '';

    return value
        .replace(/\bpublic betting validation\b/gi, 'public validation')
        .replace(/\bbetting validation\b/gi, 'validation')
        .replace(/\bbetting\b/gi, 'pick tracking')
        .replace(/\bbet\b/gi, 'pick');
}

export function healthBarClass(tone: string): string {
    if (tone === 'sky') return 'bg-sky-500';
    if (tone === 'amber') return 'bg-amber-500';

    return 'bg-emerald-500';
}

export function bestCandidatePerGame(
    candidates: MlbDailyPick[],
): MlbDailyPick[] {
    const bestByGame = new Map<number, MlbDailyPick>();

    for (const candidate of candidates) {
        const current = bestByGame.get(candidate.game_id);

        if (!current || candidate.score > current.score) {
            bestByGame.set(candidate.game_id, candidate);
        }
    }

    return Array.from(bestByGame.values()).sort((a, b) => {
        if (b.score !== a.score) return b.score - a.score;

        return String(a.game?.game_date ?? '').localeCompare(
            String(b.game?.game_date ?? ''),
        );
    });
}
