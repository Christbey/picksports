export function homepagePerformance(kind = 'measured') {
    const overall = {
        total_predictions: 1250,
        winner_accuracy: 62.8,
        avg_spread_error: 7.25,
        avg_total_error: 9,
        win_record: '785-465',
    };
    const roi = {
        total_bets: 100,
        priced_bets: 80,
        total_wins: 45,
        total_losses: 30,
        total_wagered: 80,
        total_profit: 3.14,
        roi_percentage: 3.925,
        win_percentage: 60,
    };
    const data = {
        overall,
        recent: {
            overall: {
                ...overall,
                total_predictions: 100,
                winner_accuracy: 61,
                win_record: '61-39',
            },
            roi: { ...roi },
        },
        roi,
    };
    if (kind === 'empty') {
        data.overall = {
            ...overall,
            total_predictions: 0,
            winner_accuracy: 0,
            avg_spread_error: null,
            win_record: '0-0',
        };
        data.recent.overall = { ...data.overall };
        data.roi = { ...roi, total_bets: 0, priced_bets: 0, roi_percentage: 0 };
    }
    if (kind === 'negative') data.roi.roi_percentage = -3.925;
    if (kind === 'zero') {
        data.overall.winner_accuracy = 0;
        data.overall.avg_spread_error = 0;
        data.recent.overall.winner_accuracy = 0;
        data.roi.roi_percentage = 0;
    }
    if (kind === 'unpriced') {
        data.roi.priced_bets = 0;
        data.roi.roi_percentage = 0;
    }
    if (kind === 'null') {
        data.overall.avg_spread_error = null;
        data.roi.roi_percentage = null;
    }
    if (kind === 'legacy') delete data.roi.priced_bets;
    return data;
}
