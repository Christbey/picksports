export function predictionWeather(value: unknown) {
    const data = (value && typeof value === 'object' ? value : {}) as Record<
        string,
        unknown
    >;
    const numeric = (key: string) =>
        data[key] !== null &&
        data[key] !== undefined &&
        data[key] !== '' &&
        Number.isFinite(Number(data[key]))
            ? Number(data[key])
            : null;
    const time = (value: unknown) => {
        if (typeof value !== 'string' || Number.isNaN(Date.parse(value)))
            return 'unknown';
        return new Intl.DateTimeFormat('en-US', {
            timeZone: 'America/Chicago',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            timeZoneName: 'short',
        }).format(new Date(value));
    };
    if (data.available !== true)
        return {
            summary: 'Weather forecast unavailable',
            detail: 'No weather forecast was stored for this prediction.',
        };
    const parts: string[] = [];
    if (data.is_indoor === true) parts.push('Indoors');
    else {
        const temp = numeric('temperature_f'),
            wind = numeric('wind_speed_mph'),
            gust = numeric('wind_gust_mph'),
            rain = numeric('precipitation_inches'),
            chance = numeric('precipitation_probability');
        parts.push(temp === null ? 'Temperature unavailable' : `${temp}°F`);
        parts.push(
            wind === null
                ? 'Wind unavailable'
                : `Wind ${wind} mph${gust === null ? '' : ` · gusts ${gust} mph`}`,
        );
        parts.push(
            rain === null ? 'Precipitation unavailable' : `Precip ${rain} in`,
        );
        if (chance !== null) parts.push(`${chance}% precipitation chance`);
    }
    return {
        summary: parts.join(' · '),
        detail: `${data.source === 'model_snapshot' ? 'Forecast saved with pregame model' : 'Latest stored forecast; not included in this model snapshot'} · ${data.provider ?? 'Unknown provider'} · Valid ${time(data.forecast_valid_at)} · Received ${time(data.received_at)}`,
    };
}
