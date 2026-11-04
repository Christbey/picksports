import { computed } from 'vue';
import type { BracketRegion, BracketMatchup } from '@/types/bracket';
import { roundPoints } from '@/lib/bracketConfig';
import type { useBracketTournament } from '@/composables/useBracketTournament';

export function useBracketProgress(
    tournament: ReturnType<typeof useBracketTournament>,
) {
    const { selectedParticipant, matchupResult, bracketRegions } = tournament;
    const roundPickedCount = (matchups: BracketMatchup[]) =>
        matchups.filter((matchup) => Boolean(selectedParticipant(matchup)))
            .length;

    const regionMetaById = computed(() => {
        const meta = new Map<
            string,
            {
                picked: number;
                total: number;
                pct: number;
                nextRoundKey: string | null;
                guidanceLabel: string;
                guidanceDetail: string;
            }
        >();

        bracketRegions.value.forEach((region) => {
            const roundPicked = new Map(
                region.rounds.map((round) => [
                    round.key,
                    roundPickedCount(round.matchups),
                ]),
            );
            const nextRound =
                region.rounds.find(
                    (round) =>
                        (roundPicked.get(round.key) ?? 0) <
                        round.matchups.length,
                ) ?? null;
            const total = region.rounds.reduce(
                (sum, round) => sum + round.matchups.length,
                0,
            );
            const picked = region.rounds.reduce(
                (sum, round) => sum + (roundPicked.get(round.key) ?? 0),
                0,
            );

            let guidanceLabel = 'Region complete';
            let guidanceDetail =
                'Every matchup in this region has a winner selected.';

            if (nextRound) {
                const pickedInRound = roundPicked.get(nextRound.key) ?? 0;
                const remaining = nextRound.matchups.length - pickedInRound;
                guidanceLabel =
                    pickedInRound === 0
                        ? `Start with ${nextRound.label}`
                        : `Next up: ${nextRound.label}`;
                guidanceDetail = `${remaining} matchup${remaining === 1 ? '' : 's'} left in ${nextRound.label}.`;
            }

            meta.set(region.id, {
                picked,
                total,
                pct: total ? Math.round((picked / total) * 100) : 0,
                nextRoundKey: nextRound?.key ?? null,
                guidanceLabel,
                guidanceDetail,
            });
        });

        return meta;
    });

    const regionCompletion = (region: BracketRegion) =>
        regionMetaById.value.get(region.id) ?? {
            picked: 0,
            total: 0,
            pct: 0,
            nextRoundKey: null,
            guidanceLabel: 'Region complete',
            guidanceDetail:
                'Every matchup in this region has a winner selected.',
        };

    const regionGuidanceLabel = (region: BracketRegion) =>
        regionCompletion(region).guidanceLabel;
    const regionGuidanceDetail = (region: BracketRegion) =>
        regionCompletion(region).guidanceDetail;

    const roundState = (region: BracketRegion, roundKey: string) => {
        const activeRound = regionCompletion(region).nextRoundKey;

        if (!activeRound) {
            return 'completed';
        }

        if (roundKey === activeRound) {
            return 'active';
        }

        const activeIndex = region.rounds.findIndex(
            (round) => round.key === activeRound,
        );
        const roundIndex = region.rounds.findIndex(
            (round) => round.key === roundKey,
        );

        return roundIndex < activeIndex ? 'completed' : 'upcoming';
    };

    const roundSectionClass = (region: BracketRegion, roundKey: string) => {
        const state = roundState(region, roundKey);

        if (state === 'active') {
            return 'border-primary/20 bg-card/80';
        }

        if (state === 'completed') {
            return 'border-emerald-300/10 bg-card/70';
        }

        return 'border-border/60 bg-card/60';
    };

    const roundHeaderBadge = (region: BracketRegion, roundKey: string) => {
        const state = roundState(region, roundKey);

        if (state === 'active') {
            return 'Pick here';
        }

        if (state === 'completed') {
            return 'Done';
        }

        return 'Later';
    };

    const roundGradeSummary = (matchups: BracketMatchup[]) => {
        const summary = {
            correct: 0,
            incorrect: 0,
            pending: 0,
            graded: 0,
            pointsEarned: 0,
            possiblePoints: 0,
        };

        matchups.forEach((matchup) => {
            const matchupPoints = roundPoints[matchup.roundKey] ?? 0;
            const result = matchupResult(matchup);

            summary.possiblePoints += matchupPoints;

            if (!result || result.status === 'unpicked') {
                return;
            }

            summary.pointsEarned += result.points ?? 0;

            if (result.status === 'correct') {
                summary.correct += 1;
                summary.graded += 1;
                return;
            }

            if (result.status === 'incorrect') {
                summary.incorrect += 1;
                summary.graded += 1;
                return;
            }

            if (result.status === 'pending') {
                summary.pending += 1;
            }
        });

        return summary;
    };

    const roundGradeSummaryLabel = (matchups: BracketMatchup[]) => {
        const summary = roundGradeSummary(matchups);

        if (summary.graded === 0 && summary.pending === 0) {
            return 'Ungraded';
        }

        const parts = [];

        if (summary.correct > 0) {
            parts.push(`${summary.correct} right`);
        }

        if (summary.incorrect > 0) {
            parts.push(`${summary.incorrect} wrong`);
        }

        if (summary.pending > 0) {
            parts.push(`${summary.pending} pending`);
        }

        return parts.join(' · ');
    };
    const roundMetaById = computed(() => {
        const meta = new Map<
            string,
            {
                picked: number;
                summary: ReturnType<typeof roundGradeSummary>;
                summaryLabel: string;
            }
        >();

        bracketRegions.value.forEach((region) => {
            region.rounds.forEach((round) => {
                const id = `${region.id}:${round.key}`;
                const summary = roundGradeSummary(round.matchups);
                meta.set(id, {
                    picked: roundPickedCount(round.matchups),
                    summary,
                    summaryLabel: roundGradeSummaryLabel(round.matchups),
                });
            });
        });

        return meta;
    });

    const roundMeta = (region: BracketRegion, roundKey: string) =>
        roundMetaById.value.get(`${region.id}:${roundKey}`) ?? {
            picked: 0,
            summary: {
                correct: 0,
                incorrect: 0,
                pending: 0,
                graded: 0,
                pointsEarned: 0,
                possiblePoints: 0,
            },
            summaryLabel: 'Ungraded',
        };

    return {
        regionCompletion,
        regionGuidanceLabel,
        regionGuidanceDetail,
        roundSectionClass,
        roundHeaderBadge,
        roundMeta,
    };
}
