import type { Bet } from '@/types/my-bets';
export function formatCurrency(amount: number) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

export function formatDate(date: string) {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function getSportName(predictionSport: string) {
    return predictionSport.toUpperCase();
}

export function displaySelection(bet: Bet) {
    if (bet.selection_label) {
        return bet.selection_label;
    }

    if (bet.bet_type === 'moneyline') {
        return `${bet.selection_side?.toUpperCase() ?? 'N/A'} ML`;
    }

    if (bet.bet_type === 'total_over' || bet.bet_type === 'total_under') {
        const label = bet.bet_type === 'total_over' ? 'Over' : 'Under';
        return bet.line !== null ? `${label} ${bet.line}` : label;
    }

    const side = bet.selection_side?.toUpperCase() ?? 'N/A';

    return bet.line !== null
        ? `${side} ${bet.line > 0 ? '+' : ''}${bet.line}`
        : side;
}

export function getResultColor(result: string) {
    const colors = {
        won: 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/50',
        lost: 'text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900/50',
        push: 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-900/50',
        pending:
            'text-yellow-600 dark:text-yellow-400 bg-yellow-100 dark:bg-yellow-900/50',
    };
    return colors[result as keyof typeof colors] || colors.pending;
}
