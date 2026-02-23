export const formatNumber = (value: number | string | null, decimals = 1): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return num.toFixed(decimals);
};

export const formatPercent = (value: number | string | null): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return `${num.toFixed(1)}%`;
};

export const formatBattingAverage = (value: number | string | null): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return num.toFixed(3).replace(/^0/, '');
};

export const ratingClass = (value: number | null, boldThreshold = 5): string => {
    if (value === null) return '';
    if (value > boldThreshold) return 'text-green-600 dark:text-green-400 font-semibold';
    if (value > 0) return 'text-green-600 dark:text-green-400';
    if (value < -boldThreshold) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value < 0) return 'text-red-600 dark:text-red-400';
    return '';
};
