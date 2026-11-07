export const formatPercent = (value: number, hasSample: boolean) =>
    hasSample ? `${value.toFixed(1)}%` : 'Pending';

export const formatSignedPercent = (
    value: number | null,
    hasSample: boolean,
) => {
    if (!hasSample || value === null) return 'Pending';

    return `${value > 0 ? '+' : ''}${value.toFixed(2)}%`;
};
