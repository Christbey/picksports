export const STANDARD_SHEET_COLUMNS = 4;
export const STANDARD_SHEET_CELL_WIDTH = 620;
export const STANDARD_SHEET_CELL_HEIGHT = 330;
export const STANDARD_SHEET_GAP = 24;
export const STANDARD_SHEET_PAD = 36;

export function drawRoundRect(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    w: number,
    h: number,
    r: number,
): void {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

export function drawCenteredText(
    ctx: CanvasRenderingContext2D,
    text: string,
    cx: number,
    cy: number,
    font: string,
    color: string,
): void {
    ctx.save();
    ctx.font = font;
    ctx.fillStyle = color;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(text, cx, cy);
    ctx.restore();
}

export function fitTextToWidth(
    ctx: CanvasRenderingContext2D,
    text: string,
    maxWidth: number,
): string {
    if (ctx.measureText(text).width <= maxWidth) {
        return text;
    }

    let out = text;
    while (out.length > 0 && ctx.measureText(`${out}...`).width > maxWidth) {
        out = out.slice(0, -1);
    }

    return out.length > 0 ? `${out}...` : '';
}

export function fitFontSizeForWidth(
    ctx: CanvasRenderingContext2D,
    text: string,
    maxWidth: number,
    startSize: number,
    minSize: number,
    weight = 800,
): number {
    let size = startSize;
    while (size > minSize) {
        ctx.font = `${weight} ${size}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
        if (ctx.measureText(text).width <= maxWidth) {
            return size;
        }
        size -= 1;
    }

    return minSize;
}

export function drawStatRow(
    ctx: CanvasRenderingContext2D,
    left: string,
    right: string,
    x: number,
    y: number,
    width: number,
    fontSize: number,
): void {
    ctx.fillStyle = '#64748b';
    ctx.font = `500 ${fontSize}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    ctx.fillText(left, x, y);

    ctx.fillStyle = '#0f172a';
    ctx.font = `600 ${fontSize}px "Instrument Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    const maxRight = 34;
    const truncatedRight =
        right.length > maxRight ? `${right.slice(0, maxRight)}...` : right;
    const rightWidth = ctx.measureText(truncatedRight).width;
    ctx.fillText(truncatedRight, x + width - rightWidth, y);
}

export function createStandardSheetCanvas(total: number) {
    const columns = STANDARD_SHEET_COLUMNS;
    const rows = Math.ceil(total / columns);
    const width =
        STANDARD_SHEET_PAD * 2 +
        columns * STANDARD_SHEET_CELL_WIDTH +
        (columns - 1) * STANDARD_SHEET_GAP;
    const height =
        STANDARD_SHEET_PAD * 2 +
        rows * STANDARD_SHEET_CELL_HEIGHT +
        (rows - 1) * STANDARD_SHEET_GAP;

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return null;
    }

    const bg = ctx.createLinearGradient(0, 0, width, height);
    bg.addColorStop(0, '#020617');
    bg.addColorStop(1, '#0f172a');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, width, height);

    return { canvas, ctx };
}

export async function downloadCanvasPng(
    canvas: HTMLCanvasElement,
    filename: string,
): Promise<void> {
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob((data) => resolve(data), 'image/png'),
    );
    if (!blob) {
        return;
    }

    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
