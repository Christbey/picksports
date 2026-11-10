import type {
    ApiV2Meta,
    ApiV2Prediction,
    ApiV2Query,
    ApiV2CollectionResponse,
} from '@/types';

const liveFields = [
    'status',
    'live_predicted_spread',
    'live_predicted_total',
    'live_win_probability',
    'live_seconds_remaining',
    'live_outs_remaining',
    'live_updated_at',
    'live_status',
    'live_unavailable_reason',
];
const gameFields = [
    'status',
    'home_score',
    'away_score',
    'period',
    'clock',
    'inning',
    'inning_half',
    'balls',
    'strikes',
    'outs',
    'home_linescores',
    'away_linescores',
];
const pick = (value: object, fields: string[]) =>
    Object.fromEntries(
        fields
            .filter((key) => Object.prototype.hasOwnProperty.call(value, key))
            .map((key) => [key, (value as Record<string, unknown>)[key]]),
    );

export function boardDeliveryMeta(value: unknown) {
    if (!value || typeof value !== 'object') return null;
    const data = value as Record<string, unknown>;
    if (
        typeof data.version !== 'string' ||
        !/^[a-f0-9]{64}$/.test(data.version) ||
        (data.mode !== 'live' && data.mode !== 'replace')
    )
        return null;
    return { version: data.version, mode: data.mode };
}

/** Apply only live fields; a poll cannot overwrite a published forecast. */
export function mergeBoardLivePredictions(
    current: ApiV2Prediction[],
    updates: ApiV2Prediction[],
): ApiV2Prediction[] {
    if (current.length !== updates.length)
        throw new Error('Board membership changed');
    return current.map((previous, index) => {
        const update = updates[index];
        if (
            update.id !== previous.id ||
            update.sport !== previous.sport ||
            !previous.game ||
            !update.game ||
            update.game.id !== previous.game.id
        ) {
            throw new Error('Board membership changed');
        }
        return {
            ...previous,
            ...pick(update, liveFields),
            game: { ...previous.game, ...pick(update.game, gameFields) },
        };
    });
}

/** Keep each page's baseline local, and never let an older request replace it. */
export function createBoardDelivery(
    fetcher: (
        query: ApiV2Query,
    ) => Promise<ApiV2CollectionResponse<ApiV2Prediction> | null>,
    enabled: () => boolean,
) {
    let latest = 0;
    let baseline: {
        key: string;
        version: string;
        rows: ApiV2Prediction[];
    } | null = null;
    return async (
        query: ApiV2Query,
        background = false,
        resolveStartup?: (meta: ApiV2Meta) => ApiV2Query,
    ) => {
        const request = ++latest;
        const key = JSON.stringify(query);
        const base = baseline?.key === key ? baseline : null;
        const compact = enabled();
        const live = compact && !resolveStartup && background && base !== null;
        const payload = await fetcher({
            ...query,
            ...(compact
                ? { view: resolveStartup ? 'startup' : live ? 'live' : 'board' }
                : {}),
            ...(live ? { board_version: base.version } : {}),
        });
        if (!payload || request !== latest) return payload;
        const delivery = compact ? boardDeliveryMeta(payload.meta.board) : null;
        let rows = payload.data;
        try {
            if (compact && payload.meta.board !== undefined && !delivery)
                throw new Error('Invalid board response');
            if (resolveStartup && delivery?.mode !== 'replace')
                throw new Error('Invalid board startup response');
            if (delivery?.mode === 'live') {
                if (!base || base.version !== delivery.version)
                    throw new Error('Board context changed');
                rows = mergeBoardLivePredictions(base.rows, rows);
            }
            const resolvedKey = resolveStartup
                ? JSON.stringify(resolveStartup(payload.meta))
                : key;
            baseline = delivery
                ? { key: resolvedKey, version: delivery.version, rows }
                : null;
        } catch (error) {
            baseline = null;
            throw error;
        }
        return { ...payload, data: rows };
    };
}
