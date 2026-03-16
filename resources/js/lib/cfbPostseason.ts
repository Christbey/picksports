export const CFB_POSTSEASON_LABELS: Record<number, string> = {
    1: 'Bowl Games',
    2: 'First Round',
    3: 'Quarterfinals',
    4: 'Semifinals',
    5: 'National Championship',
}

export function getCfbPostseasonLabel(postseasonRound?: number | null, fallbackWeek?: number | null): string | null {
    const round = postseasonRound ?? fallbackWeek ?? null

    if (!round) {
        return null
    }

    return CFB_POSTSEASON_LABELS[round] ?? `Postseason Round ${round}`
}
