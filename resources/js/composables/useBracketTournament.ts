import { computed, type Ref } from 'vue';
import type {
    TournamentRegion,
    BracketParticipant,
    BracketSlot,
    BracketMatchup,
    SavedBracket,
} from '@/types/bracket';
import {
    regionNames,
    roundLabels,
    bracketFallbackBySeason,
} from '@/lib/bracketConfig';
import {
    participantWithSeed,
    formatVenue,
    isRoundOf64PlayInCard,
    sortedGames,
    firstFourDestinationLabel,
    roundColumnMinHeightClass,
    roundTrackStyle,
    roundColumnGridClass,
} from '@/lib/bracketPresentation';
import { createRegionBracketBuilder } from '@/lib/bracketRegions';

export function useBracketTournament(
    props: { regions?: TournamentRegion[] },
    picks: Ref<Record<string, string>>,
    currentBracket: Ref<SavedBracket | null>,
    isBracketLocked: () => boolean,
) {
    const participantLogo = (slot: BracketSlot | null) =>
        resolveSlotParticipant(slot)?.logo ?? null;
    const bracketSeason = computed(() => {
        const season = props.regions
            ?.flatMap((region) =>
                region.rounds.flatMap((round) =>
                    round.games.map((game) =>
                        Number(game.gameDate?.slice(0, 4)),
                    ),
                ),
            )
            .find(Boolean);
        return season || new Date().getFullYear();
    });

    const fallbackParticipantForSeed = (
        regionName: string,
        seed: number,
    ): BracketParticipant | null => {
        const seasonFallbacks = bracketFallbackBySeason[bracketSeason.value];
        const team = seasonFallbacks?.[regionName]?.[seed];

        if (!team) {
            return null;
        }

        return {
            id: `fallback:${bracketSeason.value}:${regionName}:${seed}`,
            name: team.name,
            abbreviation: team.abbreviation,
            seed,
            logo: null,
        };
    };

    const compactMatchupSummary = (matchup: BracketMatchup) => {
        const summary = matchupOpponentSummary(matchup);
        if (!summary) return null;

        if (matchup.roundKey !== 'round_of_64') {
            return summary;
        }

        return summary.replace('Winner of ', 'W: ').replace(' faces ', ' vs ');
    };

    const allTournamentGames = computed(() =>
        (props.regions ?? []).flatMap((region) =>
            region.rounds.flatMap((round) => round.games),
        ),
    );

    const firstFourMatchups = computed<BracketMatchup[]>(() =>
        sortedGames(
            allTournamentGames.value.filter(
                (game) => game.roundKey === 'first_four',
            ),
        ).map((game) => ({
            id: `game:${game.id}`,
            roundKey: 'first_four',
            label: roundLabels.first_four,
            game,
            participants: [
                {
                    participant: participantWithSeed(
                        game.awayTeam,
                        game.awaySeed,
                    ),
                },
                {
                    participant: participantWithSeed(
                        game.homeTeam,
                        game.homeSeed,
                    ),
                },
            ],
        })),
    );
    const firstFourMatchupsById = computed(
        () =>
            new Map(
                firstFourMatchups.value.map((matchup) => [matchup.id, matchup]),
            ),
    );

    const resolveSlotParticipant = (
        slot: BracketSlot | null,
    ): BracketParticipant | null => {
        if (!slot) return null;
        if (slot.participant) return slot.participant;
        if (!slot.sourceMatchupId) return null;

        const sourceMatchup = firstFourMatchupsById.value.get(
            slot.sourceMatchupId,
        );
        return selectedParticipant(sourceMatchup ?? null);
    };

    const sourceMatchupForSlot = (slot: BracketSlot | null) => {
        if (!slot?.sourceMatchupId) return null;
        return firstFourMatchupsById.value.get(slot.sourceMatchupId) ?? null;
    };

    const sourceMatchupLabel = (slot: BracketSlot | null) => {
        const sourceMatchup = sourceMatchupForSlot(slot);
        if (!sourceMatchup) return null;

        const participants = sourceMatchup.participants
            .map((entry) => resolveSlotParticipant(entry))
            .filter((participant): participant is BracketParticipant =>
                Boolean(participant),
            );

        if (participants.length !== 2) {
            return null;
        }

        return `Winner of ${participants[0].name} / ${participants[1].name}`;
    };

    const selectedParticipant = (
        matchup: BracketMatchup | null,
    ): BracketParticipant | null => {
        if (!matchup) return null;

        const selectedId = picks.value[matchup.id];
        if (!selectedId) return null;

        return (
            matchup.participants
                .map((slot) => resolveSlotParticipant(slot))
                .find((participant) => participant?.id === selectedId) ?? null
        );
    };

    const participantButtonLabel = (
        slot: BracketSlot | null,
        fallback: string,
    ) => {
        const participant = resolveSlotParticipant(slot);

        if (participant) {
            return participant.name;
        }

        const sourceLabel = sourceMatchupLabel(slot);
        if (sourceLabel) {
            return sourceLabel;
        }

        return slot?.placeholderLabel ?? fallback;
    };

    const participantButtonAbbr = (
        slot: BracketSlot | null,
        fallback: string,
    ) =>
        resolveSlotParticipant(slot)?.abbreviation ??
        slot?.placeholderAbbreviation ??
        fallback;
    const participantSeed = (slot: BracketSlot | null) =>
        resolveSlotParticipant(slot)?.seed ?? null;

    const slotHasResolvedParticipant = (slot: BracketSlot | null) =>
        Boolean(resolveSlotParticipant(slot));
    const slotIsPlaceholder = (slot: BracketSlot | null) =>
        !slotHasResolvedParticipant(slot) && !sourceMatchupLabel(slot);

    const slotWinProbability = (
        matchup: BracketMatchup,
        slot: BracketSlot | null,
    ) => {
        const participant = resolveSlotParticipant(slot);
        const game = matchup.game;

        if (!participant || !game?.prediction) {
            return null;
        }

        if (participant.id === `team:${game.homeTeam.id}`) {
            return Math.round(game.prediction.homeWinProbability * 100);
        }

        if (participant.id === `team:${game.awayTeam.id}`) {
            return Math.round(game.prediction.awayWinProbability * 100);
        }

        return null;
    };

    const slotWinProbabilityClass = (
        matchup: BracketMatchup,
        slot: BracketSlot | null,
    ) => {
        const probability = slotWinProbability(matchup, slot);

        if (probability === null) {
            return 'text-muted-foreground';
        }

        return probability >= 50 ? 'text-emerald-200/80' : 'text-rose-200/80';
    };

    const matchupResult = (matchup: BracketMatchup) =>
        currentBracket.value?.results?.[matchup.id] ?? null;

    const selectedSlotResultStatus = (
        matchup: BracketMatchup,
        slot: BracketSlot | null,
    ) => {
        const participant = resolveSlotParticipant(slot);
        const result = matchupResult(matchup);

        if (!participant || !result || result.picked_id !== participant.id) {
            return null;
        }

        return result.status;
    };

    const resultToneClass = (
        matchup: BracketMatchup,
        slot: BracketSlot | null,
    ) => {
        const status = selectedSlotResultStatus(matchup, slot);

        if (status === 'correct') {
            return 'border-emerald-300/40 bg-emerald-300/12';
        }

        if (status === 'incorrect') {
            return 'border-rose-300/40 bg-rose-300/12';
        }

        if (status === 'pending') {
            return 'border-amber-300/40 bg-amber-300/12';
        }

        if (selectionState(matchup, slot) === 'selected') {
            return 'border-primary/35 bg-primary/12';
        }

        return 'border-border/70 bg-card/70 hover:border-border hover:bg-accent/60';
    };

    const resultBadgeLabel = (
        matchup: BracketMatchup,
        slot: BracketSlot | null,
    ) => {
        const status = selectedSlotResultStatus(matchup, slot);

        if (status === 'correct') return 'Correct';
        if (status === 'incorrect') return 'Missed';
        if (status === 'pending') return 'Pending';
        return null;
    };

    const resultBadgeClass = (
        matchup: BracketMatchup,
        slot: BracketSlot | null,
    ) => {
        const status = selectedSlotResultStatus(matchup, slot);

        if (status === 'correct')
            return 'border-emerald-300/20 bg-emerald-300/12 text-emerald-100/85';
        if (status === 'incorrect')
            return 'border-rose-300/20 bg-rose-300/12 text-rose-100/85';
        if (status === 'pending')
            return 'border-amber-300/20 bg-amber-300/12 text-amber-100/85';
        return 'border-border/70 bg-card/60 text-muted-foreground';
    };

    const matchupOpponentSummary = (matchup: BracketMatchup) => {
        const playInSlot = matchup.participants.find(
            (slot) => slot.sourceMatchupId,
        );
        if (!playInSlot) return null;

        const opposingSlot =
            matchup.participants.find((slot) => slot !== playInSlot) ?? null;
        const opposingParticipant = resolveSlotParticipant(opposingSlot);
        if (!opposingParticipant) return null;

        const opponentLabel = opposingParticipant.seed
            ? `(${opposingParticipant.seed}) ${opposingParticipant.name}`
            : opposingParticipant.name;

        return `${sourceMatchupLabel(playInSlot) ?? 'Play-in winner'} faces ${opponentLabel}`;
    };

    const selectionState = (
        matchup: BracketMatchup,
        slot: BracketSlot | null,
    ) => {
        const participant = resolveSlotParticipant(slot);
        if (!participant) return 'empty';
        if (picks.value[matchup.id] === participant.id) return 'selected';
        return 'available';
    };

    const selectWinner = (
        matchup: BracketMatchup,
        slot: BracketSlot | null,
    ) => {
        if (isBracketLocked()) return;

        const participant = resolveSlotParticipant(slot);
        if (!participant) return;

        picks.value = {
            ...picks.value,
            [matchup.id]: participant.id,
        };
    };

    const regionMap = computed(() => {
        const map = new Map(
            (props.regions ?? []).map((region) => [region.name, region]),
        );

        for (const regionName of regionNames) {
            if (!map.has(regionName)) {
                map.set(regionName, {
                    id: regionName.toLowerCase(),
                    name: regionName,
                    rounds: [],
                });
            }
        }

        return map;
    });

    const buildRegionBracket = createRegionBracketBuilder({
        selectedParticipant,
        fallbackParticipantForSeed,
    });
    const bracketRegions = computed(() =>
        regionNames
            .map((name) => regionMap.value.get(name))
            .filter((region): region is TournamentRegion => Boolean(region))
            .map((region) =>
                buildRegionBracket(region, firstFourMatchups.value),
            ),
    );

    const allRegionMatchups = computed(() =>
        bracketRegions.value.flatMap((region) =>
            region.rounds.flatMap((round) => round.matchups),
        ),
    );

    const standaloneRounds = computed(() => {
        const finalFourGames = sortedGames(
            allTournamentGames.value.filter(
                (game) => game.roundKey === 'final_four',
            ),
        );
        const championshipGames = sortedGames(
            allTournamentGames.value.filter(
                (game) => game.roundKey === 'national_championship',
            ),
        );

        const regionChampion = (regionName: string) => {
            const region = bracketRegions.value.find(
                (entry) => entry.name === regionName,
            );
            const elite8Round = region?.rounds.find(
                (round) => round.key === 'elite_8',
            );
            return elite8Round?.matchups[0]
                ? selectedParticipant(elite8Round.matchups[0])
                : null;
        };

        const finalFourMatchups: BracketMatchup[] = [
            {
                id: 'final-four-0',
                roundKey: 'final_four',
                label: roundLabels.final_four,
                game: finalFourGames[0] ?? null,
                participants: [
                    {
                        participant: regionChampion('East'),
                        placeholderLabel: 'East champion',
                        placeholderAbbreviation: 'E',
                    },
                    {
                        participant: regionChampion('West'),
                        placeholderLabel: 'West champion',
                        placeholderAbbreviation: 'W',
                    },
                ],
            },
            {
                id: 'final-four-1',
                roundKey: 'final_four',
                label: roundLabels.final_four,
                game: finalFourGames[1] ?? null,
                participants: [
                    {
                        participant: regionChampion('South'),
                        placeholderLabel: 'South champion',
                        placeholderAbbreviation: 'S',
                    },
                    {
                        participant: regionChampion('Midwest'),
                        placeholderLabel: 'Midwest champion',
                        placeholderAbbreviation: 'MW',
                    },
                ],
            },
        ];

        const championshipMatchup: BracketMatchup = {
            id: 'championship-0',
            roundKey: 'national_championship',
            label: roundLabels.national_championship,
            game: championshipGames[0] ?? null,
            participants: [
                {
                    participant: selectedParticipant(finalFourMatchups[0]),
                    placeholderLabel: 'Semifinal winner',
                    placeholderAbbreviation: 'SF1',
                },
                {
                    participant: selectedParticipant(finalFourMatchups[1]),
                    placeholderLabel: 'Semifinal winner',
                    placeholderAbbreviation: 'SF2',
                },
            ],
        };

        return {
            firstFour: firstFourMatchups.value,
            finalFour: finalFourMatchups,
            championship: championshipMatchup,
        };
    });

    const allBracketMatchups = computed(() => [
        ...standaloneRounds.value.firstFour,
        ...allRegionMatchups.value,
        ...standaloneRounds.value.finalFour,
        standaloneRounds.value.championship,
    ]);

    return {
        participantLogo,
        formatVenue,
        compactMatchupSummary,
        isRoundOf64PlayInCard,
        allTournamentGames,
        resolveSlotParticipant,
        selectedParticipant,
        participantButtonLabel,
        participantButtonAbbr,
        participantSeed,
        slotIsPlaceholder,
        slotWinProbability,
        slotWinProbabilityClass,
        matchupResult,
        selectedSlotResultStatus,
        resultToneClass,
        resultBadgeLabel,
        resultBadgeClass,
        firstFourDestinationLabel,
        selectWinner,
        bracketRegions,
        roundColumnMinHeightClass,
        roundTrackStyle,
        roundColumnGridClass,
        standaloneRounds,
        allBracketMatchups,
    };
}
