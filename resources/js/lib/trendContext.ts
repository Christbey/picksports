export const isConditionalPattern = (
    category: string,
    message: string,
): boolean =>
    category === 'first_score' ||
    /\bwhen\b|\bafter (?:leading|trailing|scoring)\b|\bif they\b/i.test(
        message,
    );
