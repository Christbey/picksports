import type { Ref } from 'vue';
import type {
    Recommendation,
    ExportPreset,
    StatRow,
    PredictionExport,
    FuturesExport,
    TournamentExport,
} from '@/types/admin-exports';
import {
    drawCenteredText,
    drawRoundRect,
    fitFontSizeForWidth,
    fitTextToWidth,
} from '@/composables/useExportSheets';
import {
    getSignalBand,
    formatOdds,
    shortMarket,
    sanitizeFilename,
    formatStatNumber,
    buildStatRows,
    normalizePercent,
    buildPredictionRows,
    buildFuturesRows,
    buildTournamentRows,
} from '@/lib/adminExportPresentation';
type CardContext = {
    activePreset: Ref<ExportPreset>;
    adSafeMode: Ref<boolean>;
    selectedSport: Ref<string>;
    signalForProp: (row: Recommendation) => number;
    signalForPrediction: (row: PredictionExport) => number;
    signalForFutures: (row: FuturesExport) => number;
    signalForTournament: (row: TournamentExport) => number;
};

export function createAdminExportCards({
    activePreset,
    adSafeMode,
    selectedSport,
    signalForProp,
    signalForPrediction,
    signalForFutures,
    signalForTournament,
}: CardContext) {
    function selectRowsForAdMode(rows: StatRow[]): StatRow[] {
        if (!adSafeMode.value) {
            return rows;
        }

        return rows.slice(0, 6);
    }

    async function exportRecommendation(
        rec: Recommendation,
        index: number,
    ): Promise<void> {
        const preset = activePreset.value;
        const scale = preset.id === 'instagram' ? 1 : 0.64;
        const canvas = document.createElement('canvas');
        canvas.width = preset.width;
        canvas.height = preset.height;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        const signal = signalForProp(rec);
        const bg = ctx.createLinearGradient(0, 0, preset.width, preset.height);
        bg.addColorStop(0, '#020617');
        bg.addColorStop(1, '#0f172a');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, preset.width, preset.height);

        const outerPad = 56 * scale;
        const cardX = outerPad;
        const cardY = outerPad;
        const cardW = preset.width - outerPad * 2;
        const cardH = preset.height - outerPad * 2;

        drawRoundRect(ctx, cardX, cardY, cardW, cardH, 28 * scale);
        ctx.fillStyle = '#f8fafc';
        ctx.fill();

        const inset = 38 * scale;
        const contentX = cardX + inset;
        const contentW = cardW - inset * 2;
        let y = cardY + inset;

        ctx.fillStyle = '#0f172a';
        ctx.font = `700 ${(adSafeMode.value ? 46 : 40) * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        const maxNameW = contentW - 170 * scale;
        ctx.fillText(
            fitTextToWidth(ctx, rec.player.name, maxNameW),
            contentX,
            y + 10 * scale,
        );

        y += 48 * scale;
        ctx.fillStyle = '#64748b';
        ctx.font = `600 ${(adSafeMode.value ? 26 : 24) * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        const metaText = `${rec.player.position ?? 'N/A'} • ${rec.player.team ?? 'N/A'}`;
        ctx.fillText(
            fitTextToWidth(ctx, metaText, contentW - 170 * scale),
            contentX,
            y,
        );

        y += 32 * scale;
        const matchupText = `${rec.game.away_team ?? 'Away'} @ ${rec.game.home_team ?? 'Home'}`;
        ctx.fillText(
            fitTextToWidth(ctx, matchupText, contentW - 170 * scale),
            contentX,
            y,
        );

        const pctBoxW = 150 * scale;
        const pctBoxH = 66 * scale;
        const pctX = contentX + contentW - pctBoxW;
        const pctY = cardY + inset;

        drawRoundRect(ctx, pctX, pctY, pctBoxW, pctBoxH, 14 * scale);
        ctx.fillStyle = '#16a34a';
        ctx.fill();
        drawCenteredText(
            ctx,
            `${signal}%`,
            pctX + pctBoxW / 2,
            pctY + pctBoxH / 2,
            `800 ${40 * scale}px "Instrument Sans"`,
            '#ffffff',
        );

        ctx.fillStyle = '#334155';
        ctx.font = `700 ${18 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(getSignalBand(signal), pctX, pctY + pctBoxH + 22 * scale);

        y += 28 * scale;
        drawRoundRect(ctx, contentX, y, contentW, 114 * scale, 16 * scale);
        ctx.fillStyle = '#eaeefc';
        ctx.fill();

        ctx.fillStyle = rec.recommendation === 'Over' ? '#15803d' : '#b91c1c';
        ctx.font = `700 ${(adSafeMode.value ? 48 : 44) * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            `${rec.recommendation} ${rec.line}`,
            contentX + 20 * scale,
            y + 50 * scale,
        );

        ctx.fillStyle = '#475569';
        ctx.font = `600 ${23 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            fitTextToWidth(
                ctx,
                shortMarket(rec.market),
                contentW - 220 * scale,
            ),
            contentX + 20 * scale,
            y + 84 * scale,
        );

        ctx.fillStyle = '#0f172a';
        ctx.font = `700 ${28 * scale}px monospace`;
        const odds = formatOdds(rec.odds);
        const oddsWidth = ctx.measureText(odds).width;
        ctx.fillText(
            odds,
            contentX + contentW - oddsWidth - 20 * scale,
            y + 73 * scale,
        );

        y += 136 * scale;
        drawRoundRect(ctx, contentX, y, contentW, 64 * scale, 14 * scale);
        ctx.fillStyle = '#edf2ff';
        ctx.fill();

        ctx.fillStyle = '#334155';
        ctx.font = `600 ${20 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText('Signal Strength', contentX + 18 * scale, y + 24 * scale);
        const pctText = `${signal}%`;
        const pctTextW = ctx.measureText(pctText).width;
        ctx.fillText(
            pctText,
            contentX + contentW - pctTextW - 18 * scale,
            y + 24 * scale,
        );

        const barX = contentX + 18 * scale;
        const barY = y + 34 * scale;
        const barW = contentW - 36 * scale;
        const barH = 14 * scale;
        drawRoundRect(ctx, barX, barY, barW, barH, 8 * scale);
        ctx.fillStyle = '#dbeafe';
        ctx.fill();
        drawRoundRect(
            ctx,
            barX,
            barY,
            barW * (Math.max(0, Math.min(signal, 100)) / 100),
            barH,
            8 * scale,
        );
        ctx.fillStyle =
            signal >= 88 ? '#22c55e' : signal >= 76 ? '#34d399' : '#eab308';
        ctx.fill();

        y += 76 * scale;
        const footerH = 54 * scale;
        const tableBottom = cardY + cardH - inset - footerH - 10 * scale;
        const tableH = tableBottom - y;
        drawRoundRect(ctx, contentX, y, contentW, tableH, 12 * scale);
        ctx.fillStyle = '#ffffff';
        ctx.fill();

        const rows = selectRowsForAdMode(buildStatRows(rec));
        const headerH = 32 * scale;
        const rowH = (tableH - headerH) / rows.length;

        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(
            contentX + 1 * scale,
            y + 1 * scale,
            contentW - 2 * scale,
            headerH - 1 * scale,
        );
        ctx.beginPath();
        ctx.moveTo(contentX + 12 * scale, y + headerH);
        ctx.lineTo(contentX + contentW - 12 * scale, y + headerH);
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1;
        ctx.stroke();

        ctx.fillStyle = '#475569';
        ctx.font = `700 ${13 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            'METRIC',
            contentX + 18 * scale,
            y + headerH / 2 + 4 * scale,
        );
        const valueHeader = 'VALUE';
        const valueHeaderW = ctx.measureText(valueHeader).width;
        ctx.fillText(
            valueHeader,
            contentX + contentW - valueHeaderW - 18 * scale,
            y + headerH / 2 + 4 * scale,
        );

        rows.forEach((row, rowIndex) => {
            const ry = y + headerH + rowIndex * rowH;
            const labelX = contentX + 18 * scale;
            const valueRightX = contentX + contentW - 30 * scale;
            const dividerX = contentX + contentW * 0.58;

            if (rowIndex >= 0) {
                ctx.beginPath();
                ctx.moveTo(contentX + 12 * scale, ry);
                ctx.lineTo(contentX + contentW - 12 * scale, ry);
                ctx.strokeStyle = '#e2e8f0';
                ctx.lineWidth = 1;
                ctx.stroke();
            }

            ctx.fillStyle = '#64748b';
            ctx.font = `700 ${14 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
            const label = fitTextToWidth(
                ctx,
                row.label,
                dividerX - labelX - 12 * scale,
            );
            ctx.fillText(label, labelX, ry + rowH / 2 + 4 * scale);

            const valueMaxWidth = valueRightX - dividerX - 14 * scale;
            const valueFontPx = fitFontSizeForWidth(
                ctx,
                row.value,
                valueMaxWidth,
                23 * scale,
                16 * scale,
            );
            ctx.fillStyle = '#0f172a';
            ctx.font = `800 ${valueFontPx}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
            const value = fitTextToWidth(ctx, row.value, valueMaxWidth);
            const valueWidth = ctx.measureText(value).width;
            ctx.fillText(
                value,
                valueRightX - valueWidth,
                ry + rowH / 2 + 6 * scale,
            );
        });

        const footerY = cardY + cardH - footerH;
        drawRoundRect(ctx, cardX, footerY, cardW, footerH, 0);
        ctx.fillStyle = '#0f172a';
        ctx.fill();
        drawCenteredText(
            ctx,
            'PICKSPORTS • DATA-DRIVEN PLAYER PROPS',
            cardX + cardW / 2,
            footerY + footerH / 2,
            `700 ${16 * scale}px "Instrument Sans"`,
            '#f8fafc',
        );

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob((data) => resolve(data), 'image/png'),
        );
        if (!blob) {
            return;
        }

        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.href = url;
        link.download = `${sanitizeFilename(`${selectedSport.value}-${rec.player.name}-${rec.market}-${index + 1}`)}-${preset.width}x${preset.height}-${Date.now()}.png`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    async function exportPrediction(
        prediction: PredictionExport,
        index: number,
    ): Promise<void> {
        await exportGenericTableCard(
            `${prediction.away_team} @ ${prediction.home_team}`,
            `${prediction.game.date ?? 'N/A'} • ${prediction.game.time ?? 'TBD'}`,
            `${prediction.pick_side} • ${prediction.pick_team}`,
            `Spread ${formatStatNumber(prediction.predicted_spread)} • Total ${formatStatNumber(prediction.predicted_total)}`,
            signalForPrediction(prediction),
            buildPredictionRows(prediction),
            'PICKSPORTS • DATA-DRIVEN GAME PREDICTIONS',
            `${selectedSport.value}-prediction-${prediction.away_team}-${prediction.home_team}-${index + 1}`,
        );
    }

    async function exportGenericTableCard(
        title: string,
        subtitle: string,
        headline: string,
        subheadline: string,
        confidence: number,
        rows: StatRow[],
        footer: string,
        filename: string,
    ): Promise<void> {
        const preset = activePreset.value;
        const scale = preset.id === 'instagram' ? 1 : 0.64;
        const canvas = document.createElement('canvas');
        canvas.width = preset.width;
        canvas.height = preset.height;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        const confidencePct = Math.round(normalizePercent(confidence));
        const bg = ctx.createLinearGradient(0, 0, preset.width, preset.height);
        bg.addColorStop(0, '#020617');
        bg.addColorStop(1, '#0f172a');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, preset.width, preset.height);

        const outerPad = 56 * scale;
        const cardX = outerPad;
        const cardY = outerPad;
        const cardW = preset.width - outerPad * 2;
        const cardH = preset.height - outerPad * 2;
        drawRoundRect(ctx, cardX, cardY, cardW, cardH, 28 * scale);
        ctx.fillStyle = '#f8fafc';
        ctx.fill();
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1.2 * scale;
        ctx.stroke();

        const insetX = 38 * scale;
        const insetY = 30 * scale;
        const contentX = cardX + insetX;
        const contentW = cardW - insetX * 2;
        let y = cardY + insetY;
        const pctBoxW = 150 * scale;
        const pctBoxH = 66 * scale;
        const headerRightReserve = pctBoxW + 22 * scale;

        // Header block
        ctx.fillStyle = '#0f172a';
        ctx.font = `700 ${38 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            fitTextToWidth(ctx, title, contentW - headerRightReserve),
            contentX,
            y + 22 * scale,
        );

        y += 46 * scale;
        ctx.fillStyle = '#64748b';
        ctx.font = `600 ${21 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            fitTextToWidth(ctx, subtitle, contentW - headerRightReserve),
            contentX,
            y + 6 * scale,
        );

        const pctX = contentX + contentW - pctBoxW;
        const pctY = cardY + insetY;
        drawRoundRect(ctx, pctX, pctY, pctBoxW, pctBoxH, 14 * scale);
        ctx.fillStyle = '#16a34a';
        ctx.fill();
        drawCenteredText(
            ctx,
            `${confidencePct}%`,
            pctX + pctBoxW / 2,
            pctY + pctBoxH / 2,
            `800 ${40 * scale}px "Instrument Sans"`,
            '#ffffff',
        );

        ctx.fillStyle = '#334155';
        ctx.font = `700 ${18 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            getSignalBand(confidencePct),
            pctX,
            pctY + pctBoxH + 22 * scale,
        );

        // Pick block
        y += 26 * scale;
        const pickH = 114 * scale;
        drawRoundRect(ctx, contentX, y, contentW, pickH, 16 * scale);
        ctx.fillStyle = '#eaeefc';
        ctx.fill();

        ctx.fillStyle = '#0f172a';
        ctx.font = `700 ${40 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            fitTextToWidth(ctx, headline, contentW - 44 * scale),
            contentX + 20 * scale,
            y + 52 * scale,
        );
        ctx.fillStyle = '#475569';
        ctx.font = `700 ${22 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            fitTextToWidth(ctx, subheadline, contentW - 44 * scale),
            contentX + 20 * scale,
            y + 86 * scale,
        );

        // Signal block
        y += pickH + 16 * scale;
        const signalH = 64 * scale;
        drawRoundRect(ctx, contentX, y, contentW, signalH, 14 * scale);
        ctx.fillStyle = '#edf2ff';
        ctx.fill();
        ctx.fillStyle = '#334155';
        ctx.font = `600 ${20 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText('Signal Strength', contentX + 18 * scale, y + 24 * scale);
        const pctText = `${confidencePct}%`;
        const pctTextW = ctx.measureText(pctText).width;
        ctx.fillText(
            pctText,
            contentX + contentW - pctTextW - 18 * scale,
            y + 24 * scale,
        );

        const barX = contentX + 18 * scale;
        const barY = y + 34 * scale;
        const barW = contentW - 36 * scale;
        const barH = 14 * scale;
        drawRoundRect(ctx, barX, barY, barW, barH, 8 * scale);
        ctx.fillStyle = '#dbeafe';
        ctx.fill();
        drawRoundRect(
            ctx,
            barX,
            barY,
            barW * (Math.max(0, Math.min(confidencePct, 100)) / 100),
            barH,
            8 * scale,
        );
        ctx.fillStyle =
            confidencePct >= 80
                ? '#22c55e'
                : confidencePct >= 70
                  ? '#34d399'
                  : '#eab308';
        ctx.fill();

        // Stats table block
        y += signalH + 12 * scale;
        const footerH = 54 * scale;
        const tableBottom = cardY + cardH - insetY - footerH - 10 * scale;
        const tableH = tableBottom - y;
        drawRoundRect(ctx, contentX, y, contentW, tableH, 12 * scale);
        ctx.fillStyle = '#ffffff';
        ctx.fill();

        const visibleRows = selectRowsForAdMode(rows);
        const headerH = 32 * scale;
        const rowH = (tableH - headerH) / visibleRows.length;
        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(
            contentX + 1 * scale,
            y + 1 * scale,
            contentW - 2 * scale,
            headerH - 1 * scale,
        );
        ctx.beginPath();
        ctx.moveTo(contentX + 12 * scale, y + headerH);
        ctx.lineTo(contentX + contentW - 12 * scale, y + headerH);
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1;
        ctx.stroke();

        ctx.fillStyle = '#475569';
        ctx.font = `700 ${13 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        ctx.fillText(
            'METRIC',
            contentX + 18 * scale,
            y + headerH / 2 + 4 * scale,
        );
        const valueHeader = 'VALUE';
        const valueHeaderW = ctx.measureText(valueHeader).width;
        ctx.fillText(
            valueHeader,
            contentX + contentW - valueHeaderW - 18 * scale,
            y + headerH / 2 + 4 * scale,
        );

        visibleRows.forEach((row, rowIndex) => {
            const ry = y + headerH + rowIndex * rowH;
            const labelX = contentX + 18 * scale;
            const valueRightX = contentX + contentW - 30 * scale;
            const dividerX = contentX + contentW * 0.58;

            ctx.beginPath();
            ctx.moveTo(contentX + 12 * scale, ry);
            ctx.lineTo(contentX + contentW - 12 * scale, ry);
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;
            ctx.stroke();

            ctx.fillStyle = '#64748b';
            ctx.font = `700 ${14 * scale}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
            ctx.fillText(
                fitTextToWidth(ctx, row.label, dividerX - labelX - 12 * scale),
                labelX,
                ry + rowH / 2 + 4 * scale,
            );

            const valueMaxWidth = valueRightX - dividerX - 14 * scale;
            const valueFontPx = fitFontSizeForWidth(
                ctx,
                row.value,
                valueMaxWidth,
                22 * scale,
                16 * scale,
            );
            ctx.fillStyle = '#0f172a';
            ctx.font = `800 ${valueFontPx}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
            const value = fitTextToWidth(ctx, row.value, valueMaxWidth);
            const valueWidth = ctx.measureText(value).width;
            ctx.fillText(
                value,
                valueRightX - valueWidth,
                ry + rowH / 2 + 6 * scale,
            );
        });

        const footerY = cardY + cardH - footerH;
        drawRoundRect(ctx, cardX, footerY, cardW, footerH, 0);
        ctx.fillStyle = '#0f172a';
        ctx.fill();
        drawCenteredText(
            ctx,
            `${footer} • PICKSPORTS.APP`,
            cardX + cardW / 2,
            footerY + footerH / 2,
            `700 ${14 * scale}px "Instrument Sans"`,
            '#f8fafc',
        );

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob((data) => resolve(data), 'image/png'),
        );
        if (!blob) {
            return;
        }

        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.href = url;
        link.download = `${sanitizeFilename(filename)}-${preset.width}x${preset.height}-${Date.now()}.png`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    async function exportFuturesRow(
        row: FuturesExport,
        index: number,
    ): Promise<void> {
        const headline =
            `${row.team} ${row.projected_seed ? `• Seed ${row.projected_seed}` : ''}`.trim();
        const subheadline = `Playoff ${formatStatNumber(row.playoff_make_probability)}% • Title ${formatStatNumber(row.champion_probability)}%`;
        await exportGenericTableCard(
            `${selectedSport.value} Futures`,
            `${row.conference_or_league ?? 'All'} • Season ${row.season}`,
            headline,
            subheadline,
            signalForFutures(row),
            buildFuturesRows(row),
            'PICKSPORTS • DATA-DRIVEN FUTURES',
            `${selectedSport.value}-futures-${row.team}-${index + 1}`,
        );
    }

    async function exportTournamentRow(
        row: TournamentExport,
        index: number,
    ): Promise<void> {
        const headline =
            `${row.team} ${row.projected_seed ? `• Seed ${row.projected_seed}` : ''}`.trim();
        const subheadline = `Make ${formatStatNumber(row.tournament_make_probability)}% • Title ${formatStatNumber(row.champion_probability)}%`;
        await exportGenericTableCard(
            `${selectedSport.value} Tournament`,
            `${row.conference ?? 'All'} • Season ${row.season}`,
            headline,
            subheadline,
            signalForTournament(row),
            buildTournamentRows(row),
            'PICKSPORTS • DATA-DRIVEN TOURNAMENT FORECAST',
            `${selectedSport.value}-tournament-${row.team}-${index + 1}`,
        );
    }

    return {
        exportRecommendation,
        exportPrediction,
        exportFuturesRow,
        exportTournamentRow,
    };
}
