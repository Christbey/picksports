import { computed } from 'vue';

export const formatNumber = (value: number | string | null | undefined, decimals = 1): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return num.toFixed(decimals);
};

export const formatSpread = (spread: number | string): string => {
    const num = typeof spread === 'string' ? parseFloat(spread) : spread;
    if (isNaN(num)) return '-';
    return num > 0 ? `+${num}` : String(num);
};

export const formatDateLong = (dateString: string | null): string => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });
};

export const formatDateShort = (dateString: string, timeString?: string, includeTime = true): string => {
    const [year, month, day] = dateString.split('-').map(Number);
    const date = timeString ? new Date(`${dateString}T${timeString}`) : new Date(year, month - 1, day);

    if (includeTime) {
        return new Intl.DateTimeFormat('en-US', {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
    }).format(date);
};

export const getBetterValue = (
    homeVal: number,
    awayVal: number,
    lowerIsBetter = false,
): 'home' | 'away' | null => {
    if (lowerIsBetter) {
        if (homeVal < awayVal) return 'home';
        if (awayVal < homeVal) return 'away';
        return null;
    }

    if (homeVal > awayVal) return 'home';
    if (awayVal > homeVal) return 'away';
    return null;
};

export const formatTierName = (tier: string): string => {
    const tierNames: Record<string, string> = {
        basic: 'Basic',
        pro: 'Pro',
        premium: 'Premium',
    };
    return tierNames[tier] || `${tier.charAt(0).toUpperCase()}${tier.slice(1)}`;
};

export const useGameStatus = (status: () => string) => {
    return computed(() => {
        switch (status()) {
            case 'STATUS_SCHEDULED':
                return 'Scheduled';
            case 'STATUS_IN_PROGRESS':
                return 'Live';
            case 'STATUS_FINAL':
                return 'Final';
            default:
                return status();
        }
    });
};
