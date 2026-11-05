import type {
    Team,
    TournamentGame,
    BracketParticipant,
    BracketSlot,
    BracketMatchup,
} from '@/types/bracket';
export const teamToParticipant = (team: Team): BracketParticipant => ({
    id: team.id !== null ? `team:${team.id}` : `name:${team.name}`,
    name: team.name,
    abbreviation: team.abbreviation,
    logo: team.logo ?? null,
});
export const participantWithSeed = (
    team: Team,
    seed: number | null,
): BracketParticipant => ({
    ...teamToParticipant(team),
    seed,
});
export const seedPlaceholderSlot = (seed: number): BracketSlot => ({
    participant: {
        id: `seed:${seed}`,
        name: 'TBD',
        abbreviation: 'TBD',
        seed,
    },
});
export const formatVenue = (game: TournamentGame | null) => {
    if (!game) return 'Winner advances here';

    const parts = [game.venueName, game.venueCity, game.venueState].filter(
        Boolean,
    );
    return parts.join(', ') || game.note || 'Tournament site TBD';
};
export const isRoundOf64PlayInCard = (matchup: BracketMatchup) =>
    matchup.roundKey === 'round_of_64' &&
    matchup.participants.some((slot) => slot.sourceMatchupId);
export const sortedGames = (games: TournamentGame[]) =>
    [...games].sort((left, right) =>
        `${left.gameDate ?? ''} ${left.gameTime ?? ''}`.localeCompare(
            `${right.gameDate ?? ''} ${right.gameTime ?? ''}`,
        ),
    );
export const firstFourDestinationLabel = (matchup: BracketMatchup) => {
    const seed = matchup.game?.playInTargetSeed;
    const region = matchup.game?.region;

    if (seed && region && region !== 'Unassigned') {
        return `${region} ${seed}`;
    }

    if (seed) {
        return `Seed ${seed}`;
    }

    return 'Bracket destination TBD';
};
export const roundTrackConfig: Record<
    string,
    { minHeight: string; gridRows: string; starts: number[]; span: number }
> = {
    round_of_64: {
        minHeight: 'min-h-[52rem]',
        gridRows: 'grid-rows-[repeat(67,minmax(0,0.8rem))]',
        starts: [1, 9, 17, 25, 37, 45, 53, 61],
        span: 6,
    },
    round_of_32: {
        minHeight: 'min-h-[52rem]',
        gridRows: 'grid-rows-[repeat(67,minmax(0,0.8rem))]',
        starts: [5, 21, 41, 57],
        span: 6,
    },
    sweet_16: {
        minHeight: 'min-h-[52rem]',
        gridRows: 'grid-rows-[repeat(67,minmax(0,0.8rem))]',
        starts: [13, 49],
        span: 6,
    },
    elite_8: {
        minHeight: 'min-h-[52rem]',
        gridRows: 'grid-rows-[repeat(67,minmax(0,0.8rem))]',
        starts: [31],
        span: 6,
    },
};
export const roundColumnMinHeightClass = (roundKey: string) =>
    roundTrackConfig[roundKey]?.minHeight ?? 'min-h-[22rem]';
export const roundTrackStyle = (roundKey: string, matchupIndex: number) => {
    const config = roundTrackConfig[roundKey];

    if (!config) {
        return { gridRow: '1 / span 3' };
    }

    return {
        gridRow: `${config.starts[matchupIndex] ?? 1} / span ${config.span}`,
    };
};
export const roundColumnGridClass = (roundKey: string) =>
    roundTrackConfig[roundKey]?.gridRows ??
    'grid-rows-[repeat(12,minmax(0,1rem))]';
