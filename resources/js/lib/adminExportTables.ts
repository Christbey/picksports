import type { Ref } from 'vue';
import type {
    Recommendation,
    PredictionExport,
    FuturesExport,
    AdminExportProps,
} from '@/types/admin-exports';
import {
    STANDARD_SHEET_CELL_HEIGHT,
    STANDARD_SHEET_CELL_WIDTH,
    STANDARD_SHEET_COLUMNS,
    STANDARD_SHEET_GAP,
    STANDARD_SHEET_PAD,
    createStandardSheetCanvas,
    downloadCanvasPng,
    drawCenteredText,
    drawRoundRect,
    drawStatRow,
    fitTextToWidth,
} from '@/composables/useExportSheets';
import {
    formatOdds,
    shortMarket,
    sanitizeFilename,
    formatStatNumber,
} from '@/lib/adminExportPresentation';
type TableContext = {
    props: AdminExportProps;
    adSafeMode: Ref<boolean>;
    selectedSport: Ref<string>;
    signalForProp: (row: Recommendation) => number;
    signalForPrediction: (row: PredictionExport) => number;
    signalForFutures: (row: FuturesExport) => number;
};

export function createAdminExportTables({
    props,
    adSafeMode,
    selectedSport,
    signalForProp,
    signalForPrediction,
    signalForFutures,
}: TableContext) {
    async function exportRecommendationsTable(): Promise<void> {
        const total = props.recommendations.length;
        const columns = STANDARD_SHEET_COLUMNS;
        const cellW = STANDARD_SHEET_CELL_WIDTH;
        const cellH = STANDARD_SHEET_CELL_HEIGHT;
        const gap = STANDARD_SHEET_GAP;
        const pad = STANDARD_SHEET_PAD;
        const sheet = createStandardSheetCanvas(total);

        if (!sheet) {
            return;
        }
        const { canvas, ctx } = sheet;

        props.recommendations.forEach((rec, index) => {
            const signal = signalForProp(rec);
            const col = index % columns;
            const row = Math.floor(index / columns);
            const x = pad + col * (cellW + gap);
            const y = pad + row * (cellH + gap);

            drawRoundRect(ctx, x, y, cellW, cellH, 18);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.strokeStyle = '#cbd5e1';
            ctx.lineWidth = 1;
            ctx.stroke();

            const inner = 24;
            const contentX = x + inner;
            const contentW = cellW - inner * 2;
            let cursorY = y + 42;

            ctx.fillStyle = '#0f172a';
            ctx.font =
                '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            const playerName =
                rec.player.name.length > 26
                    ? `${rec.player.name.slice(0, 26)}...`
                    : rec.player.name;
            ctx.fillText(playerName, contentX, cursorY);

            cursorY += 30;
            ctx.fillStyle = '#64748b';
            ctx.font =
                '500 18px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                fitTextToWidth(
                    ctx,
                    `${rec.player.position ?? 'N/A'} • ${rec.player.team ?? 'N/A'}`,
                    contentW - 18,
                ),
                contentX,
                cursorY,
            );

            cursorY += 26;
            const matchup = `${rec.game.away_team ?? 'Away'} @ ${rec.game.home_team ?? 'Home'}`;
            const shortMatchup =
                matchup.length > 42 ? `${matchup.slice(0, 42)}...` : matchup;
            ctx.fillText(
                fitTextToWidth(ctx, shortMatchup, contentW - 18),
                contentX,
                cursorY,
            );

            const pctW = 110;
            const pctH = 52;
            const pctX = x + cellW - inner - pctW;
            const pctY = y + 20;
            drawRoundRect(ctx, pctX, pctY, pctW, pctH, 12);
            ctx.fillStyle = '#16a34a';
            ctx.fill();
            drawCenteredText(
                ctx,
                `${signal}%`,
                pctX + pctW / 2,
                pctY + pctH / 2,
                '800 30px "Instrument Sans"',
                '#ffffff',
            );

            cursorY += 26;
            drawRoundRect(ctx, contentX, cursorY, contentW, 72, 12);
            ctx.fillStyle = '#eef2ff';
            ctx.fill();

            ctx.fillStyle =
                rec.recommendation === 'Over' ? '#15803d' : '#b91c1c';
            ctx.font =
                '700 34px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                `${rec.recommendation} ${rec.line}`,
                contentX + 14,
                cursorY + 32,
            );

            ctx.fillStyle = '#475569';
            ctx.font =
                '600 17px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                fitTextToWidth(ctx, shortMarket(rec.market), contentW - 130),
                contentX + 14,
                cursorY + 57,
            );
            ctx.fillStyle = '#0f172a';
            ctx.font = '700 22px monospace';
            const odds = formatOdds(rec.odds);
            const oddsW = ctx.measureText(odds).width;
            ctx.fillText(odds, contentX + contentW - oddsW - 14, cursorY + 43);

            cursorY += 100;
            const propSheetRows: Array<[string, string]> = [
                ['Season Avg', `${rec.stats?.season_avg ?? 0}`],
                ['Last 5 Games', `${rec.stats?.last5_avg ?? 0}`],
                [
                    'Edge vs Line',
                    `${(rec.edge ?? 0) > 0 ? '+' : ''}${rec.edge ?? 0}`,
                ],
            ];
            const visiblePropSheetRows = adSafeMode.value
                ? [propSheetRows[0], propSheetRows[2]]
                : propSheetRows;
            visiblePropSheetRows.forEach(([label, value], rowIndex) => {
                drawStatRow(
                    ctx,
                    label,
                    value,
                    contentX,
                    cursorY + rowIndex * 24,
                    contentW,
                    adSafeMode.value ? 15 : 15,
                );
            });

            ctx.fillStyle = '#334155';
            ctx.font =
                '700 12px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText('picksports.app', x + cellW - 122, y + cellH - 16);
        });

        await downloadCanvasPng(
            canvas,
            `${sanitizeFilename(`${selectedSport.value}-props-table-${total}`)}.png`,
        );
    }

    async function exportPredictionsTable(): Promise<void> {
        const total = props.predictions.length;
        const columns = STANDARD_SHEET_COLUMNS;
        const cellW = STANDARD_SHEET_CELL_WIDTH;
        const cellH = STANDARD_SHEET_CELL_HEIGHT;
        const gap = STANDARD_SHEET_GAP;
        const pad = STANDARD_SHEET_PAD;
        const sheet = createStandardSheetCanvas(total);
        if (!sheet) return;
        const { canvas, ctx } = sheet;

        props.predictions.forEach((prediction, index) => {
            const signal = signalForPrediction(prediction);
            const col = index % columns;
            const gridRow = Math.floor(index / columns);
            const x = pad + col * (cellW + gap);
            const y = pad + gridRow * (cellH + gap);

            drawRoundRect(ctx, x, y, cellW, cellH, 18);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.strokeStyle = '#cbd5e1';
            ctx.lineWidth = 1;
            ctx.stroke();

            const inner = 24;
            const contentX = x + inner;
            const contentW = cellW - inner * 2;
            let cursorY = y + 42;

            ctx.fillStyle = '#0f172a';
            ctx.font =
                '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                fitTextToWidth(
                    ctx,
                    `${prediction.away_team} @ ${prediction.home_team}`,
                    contentW - 170,
                ),
                contentX,
                cursorY,
            );

            cursorY += 28;
            ctx.fillStyle = '#64748b';
            ctx.font =
                '500 18px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                fitTextToWidth(
                    ctx,
                    `${prediction.game.date ?? 'N/A'} • ${prediction.game.time ?? 'TBD'}`,
                    contentW - 18,
                ),
                contentX,
                cursorY,
            );

            const pct = signal;
            const pctW = 110;
            const pctH = 52;
            const pctX = x + cellW - inner - pctW;
            const pctY = y + 20;
            drawRoundRect(ctx, pctX, pctY, pctW, pctH, 12);
            ctx.fillStyle = '#16a34a';
            ctx.fill();
            drawCenteredText(
                ctx,
                `${pct}%`,
                pctX + pctW / 2,
                pctY + pctH / 2,
                '800 30px "Instrument Sans"',
                '#ffffff',
            );

            cursorY += 26;
            drawRoundRect(ctx, contentX, cursorY, contentW, 72, 12);
            ctx.fillStyle = '#eef2ff';
            ctx.fill();
            ctx.fillStyle = '#0f172a';
            ctx.font =
                '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                fitTextToWidth(
                    ctx,
                    `${prediction.pick_side} • ${prediction.pick_team}`,
                    contentW - 28,
                ),
                contentX + 14,
                cursorY + 33,
            );
            ctx.fillStyle = '#475569';
            ctx.font =
                '600 17px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                fitTextToWidth(
                    ctx,
                    `Spread ${formatStatNumber(prediction.predicted_spread)} • Total ${formatStatNumber(prediction.predicted_total)}`,
                    contentW - 130,
                ),
                contentX + 14,
                cursorY + 57,
            );

            cursorY += 100;
            const predictionSheetRows: Array<[string, string]> = [
                [
                    'Win Probability',
                    `${formatStatNumber(prediction.win_probability)}%`,
                ],
                ['Home Elo', `${formatStatNumber(prediction.home_elo)}`],
                ['Away Elo', `${formatStatNumber(prediction.away_elo)}`],
            ];
            const visiblePredictionSheetRows = adSafeMode.value
                ? [predictionSheetRows[0], predictionSheetRows[1]]
                : predictionSheetRows;
            visiblePredictionSheetRows.forEach(([label, value], rowIndex) => {
                drawStatRow(
                    ctx,
                    label,
                    value,
                    contentX,
                    cursorY + rowIndex * 24,
                    contentW,
                    adSafeMode.value ? 15 : 15,
                );
            });

            ctx.fillStyle = '#334155';
            ctx.font =
                '700 12px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText('picksports.app', x + cellW - 122, y + cellH - 16);
        });

        await downloadCanvasPng(
            canvas,
            `${sanitizeFilename(`${selectedSport.value}-predictions-table-${total}`)}.png`,
        );
    }

    async function exportFuturesTable(): Promise<void> {
        const total = props.futures.length;
        const columns = STANDARD_SHEET_COLUMNS;
        const cellW = STANDARD_SHEET_CELL_WIDTH;
        const cellH = STANDARD_SHEET_CELL_HEIGHT;
        const gap = STANDARD_SHEET_GAP;
        const pad = STANDARD_SHEET_PAD;
        const sheet = createStandardSheetCanvas(total);
        if (!sheet) return;
        const { canvas, ctx } = sheet;

        props.futures.forEach((row, index) => {
            const signal = signalForFutures(row);
            const col = index % columns;
            const gridRow = Math.floor(index / columns);
            const x = pad + col * (cellW + gap);
            const y = pad + gridRow * (cellH + gap);

            drawRoundRect(ctx, x, y, cellW, cellH, 18);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.strokeStyle = '#cbd5e1';
            ctx.lineWidth = 1;
            ctx.stroke();

            const inner = 24;
            const contentX = x + inner;
            const contentW = cellW - inner * 2;
            let cursorY = y + 42;

            ctx.fillStyle = '#0f172a';
            ctx.font =
                '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                fitTextToWidth(ctx, row.team, contentW - 170),
                contentX,
                cursorY,
            );

            cursorY += 28;
            ctx.fillStyle = '#64748b';
            ctx.font =
                '500 18px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                fitTextToWidth(
                    ctx,
                    `${row.conference_or_league ?? 'N/A'} • Season ${row.season}`,
                    contentW - 18,
                ),
                contentX,
                cursorY,
            );

            const pct = signal;
            const pctW = 110;
            const pctH = 52;
            const pctX = x + cellW - inner - pctW;
            const pctY = y + 20;
            drawRoundRect(ctx, pctX, pctY, pctW, pctH, 12);
            ctx.fillStyle = '#16a34a';
            ctx.fill();
            drawCenteredText(
                ctx,
                `${pct}%`,
                pctX + pctW / 2,
                pctY + pctH / 2,
                '800 30px "Instrument Sans"',
                '#ffffff',
            );

            cursorY += 26;
            drawRoundRect(ctx, contentX, cursorY, contentW, 72, 12);
            ctx.fillStyle = '#eef2ff';
            ctx.fill();
            ctx.fillStyle = '#0f172a';
            ctx.font =
                '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                `Seed ${row.projected_seed ?? 'N/A'}`,
                contentX + 14,
                cursorY + 33,
            );
            ctx.fillStyle = '#475569';
            ctx.font =
                '600 17px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(
                `Title ${formatStatNumber(row.champion_probability)}%`,
                contentX + 14,
                cursorY + 57,
            );

            cursorY += 100;
            const futuresSheetRows: Array<[string, string]> = [
                [
                    'Playoff Make',
                    `${formatStatNumber(row.playoff_make_probability)}%`,
                ],
                [
                    'Conf/LCS',
                    `${formatStatNumber(row.conference_finals_probability ?? row.league_championship_probability)}%`,
                ],
                [
                    'Finals/WS',
                    `${formatStatNumber(row.nba_finals_probability ?? row.world_series_probability)}%`,
                ],
            ];
            const visibleFuturesSheetRows = adSafeMode.value
                ? [futuresSheetRows[0], futuresSheetRows[2]]
                : futuresSheetRows;
            visibleFuturesSheetRows.forEach(([label, value], rowIndex) => {
                drawStatRow(
                    ctx,
                    label,
                    value,
                    contentX,
                    cursorY + rowIndex * 24,
                    contentW,
                    adSafeMode.value ? 15 : 15,
                );
            });

            ctx.fillStyle = '#334155';
            ctx.font =
                '700 12px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText('picksports.app', x + cellW - 122, y + cellH - 16);
        });

        await downloadCanvasPng(
            canvas,
            `${sanitizeFilename(`${selectedSport.value}-futures-table-${total}`)}.png`,
        );
    }

    async function exportTournamentsTable(): Promise<void> {
        const total = props.tournaments.length;
        const pad = 32;
        const titleH = 52;
        const headerH = 44;
        const rowH = 42;
        const width = 2200;
        const height = pad * 2 + titleH + headerH + total * rowH + 8;
        const col = {
            team: 380,
            conference: 180,
            season: 120,
            seed: 120,
            make: 180,
            title: 180,
            autoBid: 180,
            atLarge: 180,
            firstFour: 180,
            bidThief: 180,
        };

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(0, 0, width, height);

        ctx.fillStyle = '#0f172a';
        ctx.font =
            '700 30px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(
            `${selectedSport.value} Tournament Forecast Export`,
            pad,
            pad + 34,
        );
        ctx.font =
            '500 18px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillStyle = '#475569';
        ctx.fillText(`Rows: ${total}`, width - 150, pad + 34);

        let x = pad;
        const headerY = pad + titleH;
        ctx.fillStyle = '#e2e8f0';
        ctx.fillRect(pad, headerY, width - pad * 2, headerH);
        ctx.fillStyle = '#334155';
        ctx.font =
            '700 15px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        const headers = [
            ['TEAM', col.team],
            ['CONF', col.conference],
            ['SEASON', col.season],
            ['SEED', col.seed],
            ['MAKE %', col.make],
            ['TITLE %', col.title],
            ['AUTO %', col.autoBid],
            ['AT LARGE %', col.atLarge],
            ['FIRST FOUR %', col.firstFour],
            ['BID THIEF %', col.bidThief],
        ] as const;
        headers.forEach(([label, w]) => {
            ctx.fillText(label, x + 10, headerY + 28);
            x += w;
        });

        props.tournaments.forEach((row, index) => {
            const y = headerY + headerH + index * rowH;
            ctx.fillStyle = index % 2 === 0 ? '#ffffff' : '#f8fafc';
            ctx.fillRect(pad, y, width - pad * 2, rowH);
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(pad, y + rowH);
            ctx.lineTo(width - pad, y + rowH);
            ctx.stroke();

            ctx.fillStyle = '#0f172a';
            ctx.font =
                '600 15px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            let cx = pad;
            const values = [
                fitTextToWidth(ctx, row.team, col.team - 16),
                row.conference ?? 'N/A',
                `${row.season}`,
                `${row.projected_seed ?? 'N/A'}`,
                formatStatNumber(row.tournament_make_probability),
                formatStatNumber(row.champion_probability),
                formatStatNumber(row.auto_bid_probability),
                formatStatNumber(row.at_large_probability),
                formatStatNumber(row.first_four_probability),
                formatStatNumber(row.bid_thief_probability),
            ];
            const widths = [
                col.team,
                col.conference,
                col.season,
                col.seed,
                col.make,
                col.title,
                col.autoBid,
                col.atLarge,
                col.firstFour,
                col.bidThief,
            ];
            values.forEach((value, valueIndex) => {
                ctx.fillText(value, cx + 10, y + 27);
                cx += widths[valueIndex];
            });
        });

        await downloadCanvasPng(
            canvas,
            `${sanitizeFilename(`${selectedSport.value}-tournament-table-${total}`)}.png`,
        );
    }

    return {
        exportRecommendationsTable,
        exportPredictionsTable,
        exportFuturesTable,
        exportTournamentsTable,
    };
}
