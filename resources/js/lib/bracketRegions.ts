import type {
    TournamentGame,
    TournamentRegion,
    BracketParticipant,
    BracketSlot,
    BracketMatchup,
    BracketRegion,
} from '@/types/bracket';
import { roundOf64SeedPairings, roundLabels } from '@/lib/bracketConfig';
import {
    firstFourDestinationLabel,
    sortedGames,
    participantWithSeed,
    seedPlaceholderSlot,
} from '@/lib/bracketPresentation';
export function createRegionBracketBuilder({
    selectedParticipant,
    fallbackParticipantForSeed,
}: {
    selectedParticipant: (
        matchup: BracketMatchup | null,
    ) => BracketParticipant | null;
    fallbackParticipantForSeed: (
        regionName: string,
        seed: number,
    ) => BracketParticipant | null;
}) {
    const buildVirtualRound = (
        regionName: string,
        roundKey: string,
        previousMatchups: BracketMatchup[],
        actualGames: TournamentGame[],
    ) => {
        const matchupCount =
            actualGames.length > 0
                ? actualGames.length
                : Math.ceil(previousMatchups.length / 2);

        return Array.from({ length: matchupCount }, (_, index) => {
            const leftWinner = selectedParticipant(
                previousMatchups[index * 2] ?? null,
            );
            const rightWinner = selectedParticipant(
                previousMatchups[index * 2 + 1] ?? null,
            );

            return {
                id: `${regionName}-${roundKey}-${index}`,
                roundKey,
                label: roundLabels[roundKey] ?? roundKey,
                game: actualGames[index] ?? null,
                participants: [
                    {
                        participant: leftWinner,
                        placeholderLabel: 'Advances here',
                        placeholderAbbreviation: 'TBD',
                    },
                    {
                        participant: rightWinner,
                        placeholderLabel: 'Advances here',
                        placeholderAbbreviation: 'TBD',
                    },
                ] as [BracketSlot, BracketSlot],
            };
        });
    };
    const firstFourWinnerSlot = (matchup: BracketMatchup): BracketSlot => ({
        participant: null,
        sourceMatchupId: matchup.id,
        placeholderLabel: `Winner to ${firstFourDestinationLabel(matchup)}`,
        placeholderAbbreviation: 'FF',
    });
    const buildRegionBracket = (
        region: TournamentRegion,
        firstFourMatchups: BracketMatchup[],
    ): BracketRegion => {
        const roundMap = new Map(
            region.rounds.map((round) => [round.key, sortedGames(round.games)]),
        );
        const roundOf64Games = roundMap.get('round_of_64') ?? [];

        const regionFirstFour = firstFourMatchups.filter(
            (matchup) => matchup.game?.region === region.name,
        );
        const roundOf64GamesByPair = new Map(
            roundOf64Games
                .filter(
                    (game) => game.homeSeed != null && game.awaySeed != null,
                )
                .map((game) => [
                    `${Math.min(game.homeSeed!, game.awaySeed!)}-${Math.max(game.homeSeed!, game.awaySeed!)}`,
                    game,
                ]),
        );

        const roundOf64Matchups: BracketMatchup[] = roundOf64SeedPairings.map(
            ([highSeed, lowSeed], index) => {
                const game =
                    roundOf64GamesByPair.get(
                        `${Math.min(highSeed, lowSeed)}-${Math.max(highSeed, lowSeed)}`,
                    ) ?? null;
                const matchingFirstFour = regionFirstFour.find(
                    (matchup) =>
                        matchup.game?.playInTargetSeed === highSeed ||
                        matchup.game?.playInTargetSeed === lowSeed,
                );
                const playInSeed = matchingFirstFour?.game?.playInTargetSeed;

                const slotForSeed = (
                    seed: number,
                    side: 'home' | 'away',
                ): BracketSlot => {
                    if (playInSeed === seed && matchingFirstFour) {
                        return firstFourWinnerSlot(matchingFirstFour);
                    }

                    if (!game) {
                        const fallbackParticipant = fallbackParticipantForSeed(
                            region.name,
                            seed,
                        );
                        return fallbackParticipant
                            ? { participant: fallbackParticipant }
                            : seedPlaceholderSlot(seed);
                    }

                    const team =
                        side === 'home' ? game.homeTeam : game.awayTeam;
                    const teamSeed =
                        side === 'home' ? game.homeSeed : game.awaySeed;

                    if (teamSeed === seed) {
                        return {
                            participant: participantWithSeed(team, teamSeed),
                        };
                    }

                    const oppositeTeam =
                        side === 'home' ? game.awayTeam : game.homeTeam;
                    const oppositeSeed =
                        side === 'home' ? game.awaySeed : game.homeSeed;

                    if (oppositeSeed === seed) {
                        return {
                            participant: participantWithSeed(
                                oppositeTeam,
                                oppositeSeed,
                            ),
                        };
                    }

                    const fallbackParticipant = fallbackParticipantForSeed(
                        region.name,
                        seed,
                    );
                    return fallbackParticipant
                        ? { participant: fallbackParticipant }
                        : seedPlaceholderSlot(seed);
                };

                return {
                    id: game
                        ? `game:${game.id}`
                        : `${region.name}-round_of_64-${index}`,
                    roundKey: 'round_of_64',
                    label: roundLabels.round_of_64,
                    game,
                    participants: [
                        slotForSeed(highSeed, 'away'),
                        slotForSeed(lowSeed, 'home'),
                    ] as [BracketSlot, BracketSlot],
                };
            },
        );

        const roundOf32Matchups = buildVirtualRound(
            region.name,
            'round_of_32',
            roundOf64Matchups,
            roundMap.get('round_of_32') ?? [],
        );
        const sweet16Matchups = buildVirtualRound(
            region.name,
            'sweet_16',
            roundOf32Matchups,
            roundMap.get('sweet_16') ?? [],
        );
        const elite8Matchups = buildVirtualRound(
            region.name,
            'elite_8',
            sweet16Matchups,
            roundMap.get('elite_8') ?? [],
        );

        return {
            id: region.id,
            name: region.name,
            rounds: [
                {
                    key: 'round_of_64',
                    label: roundLabels.round_of_64,
                    matchups: roundOf64Matchups,
                },
                {
                    key: 'round_of_32',
                    label: roundLabels.round_of_32,
                    matchups: roundOf32Matchups,
                },
                {
                    key: 'sweet_16',
                    label: roundLabels.sweet_16,
                    matchups: sweet16Matchups,
                },
                {
                    key: 'elite_8',
                    label: roundLabels.elite_8,
                    matchups: elite8Matchups,
                },
            ],
        };
    };
    return buildRegionBracket;
}
