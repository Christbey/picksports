import type { ApiV2Prediction, ApiV2Record } from '@/types';
export function teamText(team: unknown): string {
    const payload = (team ?? {}) as ApiV2Record;

    return [
        payload.abbreviation,
        payload.short_display_name,
        payload.display_name,
        payload.location,
        payload.name,
    ]
        .filter((value) => typeof value === 'string' && value.length > 0)
        .join(' ');
}

export function formatDateLabel(date: string): string {
    if (!date) return 'Select date';

    const [year, month, day] = date.split('-').map(Number);
    const displayDate = new Date(year, month - 1, day);

    return displayDate.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

export function todayKey(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Chicago',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}

export function gameSortKey(prediction: ApiV2Prediction): string {
    const game = prediction.game;
    const date = String(game?.game_date ?? '');
    const datePart = date.includes('T')
        ? date.split('T')[0]
        : date.split(' ')[0];
    const timePart = String(game?.game_time ?? '23:59:59');

    return `${datePart} ${timePart} ${game?.short_name ?? ''}`;
}

export function boardDateOptions(dates: string[]): string[] {
    return [...new Set([...dates, todayKey()])].sort();
}
