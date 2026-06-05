<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useAppearance } from '@/composables/useAppearance';
import { useApiV2Client } from '@/composables/useApiV2Client';

type Team = {
    id: number | null;
    name: string;
    abbreviation: string | null;
    logo?: string | null;
};

type TournamentGame = {
    id: number;
    espnEventId: string | null;
    gameDate: string | null;
    gameTime: string | null;
    region: string;
    roundKey: string;
    roundLabel: string;
    venueName: string | null;
    venueCity: string | null;
    venueState: string | null;
    note: string | null;
    homeSeed: number | null;
    awaySeed: number | null;
    playInTargetSeed: number | null;
    homeTeam: Team;
    awayTeam: Team;
    name: string | null;
    prediction?: {
        homeWinProbability: number;
        awayWinProbability: number;
    } | null;
};

type TournamentRound = {
    key: string;
    label: string;
    games: TournamentGame[];
};

type TournamentRegion = {
    id: string;
    name: string;
    rounds: TournamentRound[];
};

type BracketParticipant = {
    id: string;
    name: string;
    abbreviation: string | null;
    logo?: string | null;
    seed?: number | null;
};

type BracketSlot = {
    participant: BracketParticipant | null;
    sourceMatchupId?: string;
    placeholderLabel?: string;
    placeholderAbbreviation?: string;
};

type BracketMatchup = {
    id: string;
    roundKey: string;
    label: string;
    game: TournamentGame | null;
    participants: [BracketSlot, BracketSlot];
};

type BracketRegion = {
    id: string;
    name: string;
    rounds: {
        key: string;
        label: string;
        matchups: BracketMatchup[];
    }[];
};

type BracketResult = {
    status: 'correct' | 'incorrect' | 'pending' | 'unpicked';
    round_key: string;
    points: number;
    possible_points: number;
    picked_id: string | null;
    winning_id: string | null;
};

type SavedBracket = {
    id: number;
    public_id: string;
    season: number;
    name: string | null;
    group_id: number | null;
    group?: {
        id: number;
        public_id: string;
        name: string;
    } | null;
    picks: Record<string, string>;
    points_earned: number;
    max_points_remaining: number;
    correct_picks: number;
    incorrect_picks: number;
    graded_through_round: string | null;
    results: Record<string, BracketResult>;
    is_locked: boolean;
    can_edit: boolean;
    lock_at: string | null;
    submitted_at: string | null;
    updated_at?: string | null;
};

type Group = {
    id: number;
    public_id: string;
    name: string;
    type: string;
    sport: string | null;
    season: number | null;
    owner_id: number | null;
};

type LeaderboardEntry = {
    rank: number;
    bracket_id: number;
    bracket_public_id: string;
    bracket_name: string;
    user_id: number;
    user_name: string | null;
    points_earned: number;
    max_points_remaining: number;
    correct_picks: number;
    incorrect_picks: number;
    submitted_at: string | null;
    updated_at: string | null;
};

type ApiResource<T> = {
    data: T;
};

type ApiMutationError = {
    data?: {
        lock_at?: unknown;
        message?: unknown;
    } | null;
    status?: number;
};

const storageKey = 'march-madness-bracket-builder-v3';
const bracketLockAtIso = '2026-03-19T11:00:00-05:00';
const regionNames = ['East', 'West', 'South', 'Midwest'];
const roundOf64SeedPairings: Array<[number, number]> = [
    [1, 16],
    [8, 9],
    [5, 12],
    [4, 13],
    [6, 11],
    [3, 14],
    [7, 10],
    [2, 15],
];
const roundLabels: Record<string, string> = {
    first_four: 'First Four',
    round_of_64: 'Round of 64',
    round_of_32: 'Round of 32',
    sweet_16: 'Sweet 16',
    elite_8: 'Elite 8',
    final_four: 'Final Four',
    national_championship: 'National Championship',
};
const roundPoints: Record<string, number> = {
    first_four: 0,
    round_of_64: 10,
    round_of_32: 20,
    sweet_16: 40,
    elite_8: 80,
    final_four: 160,
    national_championship: 320,
};
const accentByRegion: Record<string, string> = {
    East: 'from-sky-500/8 via-cyan-500/4 to-transparent dark:from-sky-500/18 dark:via-cyan-500/8',
    West: 'from-amber-500/8 via-orange-500/4 to-transparent dark:from-amber-500/18 dark:via-orange-500/8',
    South: 'from-emerald-500/8 via-teal-500/4 to-transparent dark:from-emerald-500/18 dark:via-teal-500/8',
    Midwest:
        'from-rose-500/8 via-fuchsia-500/4 to-transparent dark:from-rose-500/18 dark:via-fuchsia-500/8',
    Unassigned:
        'from-foreground/[0.03] via-transparent to-transparent dark:from-foreground/[0.06]',
};
const bracketFallbackBySeason: Record<
    number,
    Record<string, Record<number, { name: string; abbreviation: string }>>
> = {
    2026: {
        West: {
            1: { name: 'Arizona Wildcats', abbreviation: 'ARIZ' },
            2: { name: 'Purdue Boilermakers', abbreviation: 'PUR' },
            3: { name: 'Gonzaga Bulldogs', abbreviation: 'GONZ' },
            4: { name: 'Arkansas Razorbacks', abbreviation: 'ARK' },
            5: { name: 'Wisconsin Badgers', abbreviation: 'WIS' },
            6: { name: 'BYU Cougars', abbreviation: 'BYU' },
            7: { name: 'Miami Hurricanes', abbreviation: 'MIA' },
            8: { name: 'Villanova Wildcats', abbreviation: 'VILL' },
            9: { name: 'Utah State Aggies', abbreviation: 'USU' },
            10: { name: 'Missouri Tigers', abbreviation: 'MIZ' },
            12: { name: 'High Point Panthers', abbreviation: 'HPU' },
            13: { name: "Hawai'i Rainbow Warriors", abbreviation: 'HAW' },
            14: { name: 'Kennesaw State Owls', abbreviation: 'KENN' },
            15: { name: 'Queens Royals', abbreviation: 'QUE' },
            16: { name: 'Long Island University Sharks', abbreviation: 'LIU' },
        },
        Midwest: {
            1: { name: 'Michigan Wolverines', abbreviation: 'MICH' },
            2: { name: 'Iowa State Cyclones', abbreviation: 'ISU' },
            3: { name: 'Virginia Cavaliers', abbreviation: 'UVA' },
            4: { name: 'Alabama Crimson Tide', abbreviation: 'ALA' },
            5: { name: 'Texas Tech Red Raiders', abbreviation: 'TTU' },
            6: { name: 'Tennessee Volunteers', abbreviation: 'TENN' },
            7: { name: 'Kentucky Wildcats', abbreviation: 'UK' },
            8: { name: 'Georgia Bulldogs', abbreviation: 'UGA' },
            9: { name: 'Saint Louis Billikens', abbreviation: 'SLU' },
            10: { name: 'Santa Clara Broncos', abbreviation: 'SCU' },
            12: { name: 'Akron Zips', abbreviation: 'AKR' },
            13: { name: 'Hofstra Pride', abbreviation: 'HOF' },
            14: { name: 'Wright State Raiders', abbreviation: 'WRST' },
            15: { name: 'Tennessee State Tigers', abbreviation: 'TNST' },
        },
        South: {
            1: { name: 'Florida Gators', abbreviation: 'FLA' },
            2: { name: 'Houston Cougars', abbreviation: 'HOU' },
            3: { name: 'Illinois Fighting Illini', abbreviation: 'ILL' },
            4: { name: 'Nebraska Cornhuskers', abbreviation: 'NEB' },
            5: { name: 'Vanderbilt Commodores', abbreviation: 'VAN' },
            6: { name: 'North Carolina Tar Heels', abbreviation: 'UNC' },
            7: { name: "Saint Mary's Gaels", abbreviation: 'SMC' },
            8: { name: 'Clemson Tigers', abbreviation: 'CLEM' },
            9: { name: 'Iowa Hawkeyes', abbreviation: 'IOWA' },
            10: { name: 'Texas A&M Aggies', abbreviation: 'TA&M' },
            11: { name: 'VCU Rams', abbreviation: 'VCU' },
            12: { name: 'McNeese Cowboys', abbreviation: 'MCN' },
            13: { name: 'Troy Trojans', abbreviation: 'TROY' },
            14: { name: 'Pennsylvania Quakers', abbreviation: 'PENN' },
            15: { name: 'Idaho Vandals', abbreviation: 'IDHO' },
        },
    },
};

const props = defineProps<{
    regions?: TournamentRegion[];
}>();

const { resolvedAppearance, updateAppearance } = useAppearance();
const api = useApiV2Client();
const page = usePage();
const picks = ref<Record<string, string>>({});
const currentBracket = ref<SavedBracket | null>(null);
const brackets = ref<SavedBracket[]>([]);
const activeBracketPublicId = ref<string | null>(null);
const availableGroups = ref<Group[]>([]);
const bracketNameDraft = ref('');
const bracketGroupIdDraft = ref<string>('');
const leaderboard = ref<LeaderboardEntry[]>([]);
const saveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const metaSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const resetConfirmOpen = ref(false);
const storageLoaded = ref(false);
const serverLoaded = ref(false);

const teamToParticipant = (team: Team): BracketParticipant => ({
    id: team.id !== null ? `team:${team.id}` : `name:${team.name}`,
    name: team.name,
    abbreviation: team.abbreviation,
    logo: team.logo ?? null,
});

const participantWithSeed = (
    team: Team,
    seed: number | null,
): BracketParticipant => ({
    ...teamToParticipant(team),
    seed,
});

const participantLogo = (slot: BracketSlot | null) =>
    resolveSlotParticipant(slot)?.logo ?? null;
const bracketSeason = computed(() => {
    const season = props.regions
        ?.flatMap((region) =>
            region.rounds.flatMap((round) =>
                round.games.map((game) => Number(game.gameDate?.slice(0, 4))),
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

const seedPlaceholderSlot = (seed: number): BracketSlot => ({
    participant: {
        id: `seed:${seed}`,
        name: 'TBD',
        abbreviation: 'TBD',
        seed,
    },
});

const formatVenue = (game: TournamentGame | null) => {
    if (!game) return 'Winner advances here';

    const parts = [game.venueName, game.venueCity, game.venueState].filter(
        Boolean,
    );
    return parts.join(', ') || game.note || 'Tournament site TBD';
};

const compactMatchupSummary = (matchup: BracketMatchup) => {
    const summary = matchupOpponentSummary(matchup);
    if (!summary) return null;

    if (matchup.roundKey !== 'round_of_64') {
        return summary;
    }

    return summary.replace('Winner of ', 'W: ').replace(' faces ', ' vs ');
};

const isRoundOf64PlayInCard = (matchup: BracketMatchup) =>
    matchup.roundKey === 'round_of_64' &&
    matchup.participants.some((slot) => slot.sourceMatchupId);

const sortedGames = (games: TournamentGame[]) =>
    [...games].sort((left, right) =>
        `${left.gameDate ?? ''} ${left.gameTime ?? ''}`.localeCompare(
            `${right.gameDate ?? ''} ${right.gameTime ?? ''}`,
        ),
    );

const allTournamentGames = computed(() =>
    (props.regions ?? []).flatMap((region) =>
        region.rounds.flatMap((round) => round.games),
    ),
);

const apiStatus = (error: unknown): number | null =>
    typeof error === 'object' && error !== null && 'status' in error
        ? Number((error as ApiMutationError).status)
        : null;

const apiLockAt = (error: unknown): string | null => {
    if (typeof error !== 'object' || error === null || !('data' in error)) {
        return null;
    }

    const lockAt = (error as ApiMutationError).data?.lock_at;

    return typeof lockAt === 'string' ? lockAt : null;
};
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
            { participant: participantWithSeed(game.awayTeam, game.awaySeed) },
            { participant: participantWithSeed(game.homeTeam, game.homeSeed) },
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

    const sourceMatchup = firstFourMatchupsById.value.get(slot.sourceMatchupId);
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

const participantButtonLabel = (slot: BracketSlot | null, fallback: string) => {
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

const participantButtonAbbr = (slot: BracketSlot | null, fallback: string) =>
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

const resultToneClass = (matchup: BracketMatchup, slot: BracketSlot | null) => {
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

const firstFourDestinationLabel = (matchup: BracketMatchup) => {
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

const selectionState = (matchup: BracketMatchup, slot: BracketSlot | null) => {
    const participant = resolveSlotParticipant(slot);
    if (!participant) return 'empty';
    if (picks.value[matchup.id] === participant.id) return 'selected';
    return 'available';
};

const selectWinner = (matchup: BracketMatchup, slot: BracketSlot | null) => {
    if (isBracketLocked.value) return;

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
            .filter((game) => game.homeSeed != null && game.awaySeed != null)
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

                const team = side === 'home' ? game.homeTeam : game.awayTeam;
                const teamSeed =
                    side === 'home' ? game.homeSeed : game.awaySeed;

                if (teamSeed === seed) {
                    return { participant: participantWithSeed(team, teamSeed) };
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

const bracketRegions = computed(() =>
    regionNames
        .map((name) => regionMap.value.get(name))
        .filter((region): region is TournamentRegion => Boolean(region))
        .map((region) => buildRegionBracket(region, firstFourMatchups.value)),
);

const roundTrackConfig: Record<
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

const roundColumnMinHeightClass = (roundKey: string) =>
    roundTrackConfig[roundKey]?.minHeight ?? 'min-h-[22rem]';

const roundTrackStyle = (roundKey: string, matchupIndex: number) => {
    const config = roundTrackConfig[roundKey];

    if (!config) {
        return { gridRow: '1 / span 3' };
    }

    return {
        gridRow: `${config.starts[matchupIndex] ?? 1} / span ${config.span}`,
    };
};

const roundColumnGridClass = (roundKey: string) =>
    roundTrackConfig[roundKey]?.gridRows ??
    'grid-rows-[repeat(12,minmax(0,1rem))]';

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

const currentSeason = computed(() => {
    const seasons = allTournamentGames.value
        .map((game) => game.gameDate?.slice(0, 4))
        .filter((season): season is string => Boolean(season));

    return seasons.length
        ? Math.max(...seasons.map((season) => Number(season)))
        : new Date().getFullYear();
});
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const currentUserId = computed(() => Number(page.props.auth?.user?.id ?? 0));
const bracketLockDate = computed(() => new Date(bracketLockAtIso));
const isBracketLocked = computed(() => {
    if (currentBracket.value?.lock_at) {
        return new Date() >= new Date(currentBracket.value.lock_at);
    }

    return new Date() >= bracketLockDate.value;
});
const totalGames = computed(() => allBracketMatchups.value.length);
const pickedGames = computed(() => Object.keys(picks.value).length);
const completionPct = computed(() =>
    totalGames.value === 0
        ? 0
        : Math.round((pickedGames.value / totalGames.value) * 100),
);
const champion = computed(
    () =>
        selectedParticipant(standaloneRounds.value.championship)?.name ?? null,
);
const activeBracketName = computed(
    () => currentBracket.value?.name?.trim() || 'Untitled bracket',
);
const nextBracketName = computed(() => `Bracket ${brackets.value.length + 1}`);
const selectedGroupName = computed(() => {
    const groupId = Number(bracketGroupIdDraft.value);
    if (!groupId) return 'No group';

    return (
        availableGroups.value.find((group) => group.id === groupId)?.name ??
        'No group'
    );
});
const requiresGroupAssignment = computed(() =>
    availableGroups.value.some(
        (group) => group.owner_id !== currentUserId.value,
    ),
);
const maskedLeaderboardUser = (userName: string | null) => {
    const trimmed = userName?.trim();

    if (!trimmed) {
        return 'UNK';
    }

    return trimmed.replace(/\s+/g, '').slice(0, 3).toUpperCase();
};
const roundPickedCount = (matchups: BracketMatchup[]) =>
    matchups.filter((matchup) => Boolean(selectedParticipant(matchup))).length;

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
                    (roundPicked.get(round.key) ?? 0) < round.matchups.length,
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
        guidanceDetail: 'Every matchup in this region has a winner selected.',
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
const saveStateLabel = computed(() => {
    if (isBracketLocked.value) return 'Locked Mar 19, 2026 at 11:00 AM CT';
    if (!isAuthenticated.value) return 'Saved on this device';
    if (saveState.value === 'saving') return 'Saving to account';
    if (saveState.value === 'saved') return 'Saved to account';
    if (saveState.value === 'error') return 'Save failed';
    return 'Account sync ready';
});
const picksAutosaveLabel = computed(() => {
    if (isBracketLocked.value) return 'Bracket locked';
    if (!isAuthenticated.value) return 'Picks autosave on this device';
    if (saveState.value === 'saving') return 'Picks autosaving to your account';
    if (saveState.value === 'saved') return 'Picks autosaved to your account';
    if (saveState.value === 'error') return 'Picks failed to autosave';
    return 'Picks autosave to your account';
});
const picksAutosaveToneClass = computed(() => {
    if (saveState.value === 'saved') return 'text-emerald-200/80';
    if (saveState.value === 'saving') return 'text-foreground/80';
    if (saveState.value === 'error') return 'text-rose-200/80';
    return 'text-muted-foreground';
});
const metaSaveLabel = computed(() => {
    if (isBracketLocked.value) return 'Locked';
    if (metaSaveState.value === 'saving') return 'Saving...';
    if (metaSaveState.value === 'saved') return 'Saved';
    if (metaSaveState.value === 'error') return 'Retry Save';
    return 'Save Details';
});
const metaSaveToneClass = computed(() => {
    if (metaSaveState.value === 'saved') return 'text-emerald-200/80';
    if (metaSaveState.value === 'saving') return 'text-foreground/80';
    if (metaSaveState.value === 'error') return 'text-rose-200/80';
    return 'text-muted-foreground';
});
const appearanceToggleLabel = computed(() =>
    resolvedAppearance.value === 'dark' ? 'Light mode' : 'Dark mode',
);

const toggleAppearance = () => {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
};

const resetBracket = () => {
    if (isBracketLocked.value) return;
    picks.value = {};
};

const requestResetBracket = () => {
    if (isBracketLocked.value) return;
    resetConfirmOpen.value = true;
};

const confirmResetBracket = () => {
    resetBracket();
    resetConfirmOpen.value = false;
};

const upsertBracketInList = (bracket: SavedBracket) => {
    const next = [...brackets.value];
    const index = next.findIndex(
        (entry) => entry.public_id === bracket.public_id,
    );

    if (index === -1) {
        next.unshift(bracket);
    } else {
        next[index] = bracket;
    }

    brackets.value = next.sort(
        (left, right) =>
            new Date(right.updated_at ?? 0).getTime() -
            new Date(left.updated_at ?? 0).getTime(),
    );
};

const activateBracket = (bracket: SavedBracket | null, syncPicks = true) => {
    currentBracket.value = bracket;
    activeBracketPublicId.value = bracket?.public_id ?? null;
    bracketNameDraft.value = bracket?.name ?? '';
    bracketGroupIdDraft.value = bracket?.group_id
        ? String(bracket.group_id)
        : requiresGroupAssignment.value && availableGroups.value.length
          ? String(availableGroups.value[0].id)
          : '';

    if (!syncPicks) {
        return;
    }

    if (bracket?.picks) {
        applyStoredPicks(bracket.picks);
        return;
    }

    if (bracket) {
        picks.value = {};
    }
};

const applyStoredPicks = (stored: Record<string, string>) => {
    const validMatchupIds = new Set(
        allBracketMatchups.value.map((matchup) => matchup.id),
    );
    picks.value = Object.fromEntries(
        Object.entries(stored).filter(([matchupId]) =>
            validMatchupIds.has(matchupId),
        ),
    );
};

const readLocalPicks = () => {
    const stored = window.localStorage.getItem(storageKey);
    if (!stored) {
        storageLoaded.value = true;
        return;
    }

    try {
        applyStoredPicks(JSON.parse(stored) as Record<string, string>);
    } catch {
        window.localStorage.removeItem(storageKey);
    } finally {
        storageLoaded.value = true;
    }
};

const createBracket = async (
    initialPicks: Record<string, string> = {},
    syncPicks = true,
) => {
    if (!isAuthenticated.value || isBracketLocked.value) {
        return null;
    }

    const resolvedGroupId = bracketGroupIdDraft.value
        ? Number(bracketGroupIdDraft.value)
        : requiresGroupAssignment.value && availableGroups.value.length
          ? availableGroups.value[0].id
          : null;

    const response = await api.cbbBrackets.store<ApiResource<SavedBracket>>({
        season: currentSeason.value,
        name: bracketNameDraft.value.trim() || nextBracketName.value,
        group_id: resolvedGroupId,
        picks: initialPicks,
    });

    const bracket = response?.data;
    if (!bracket) {
        return null;
    }

    upsertBracketInList(bracket);
    activateBracket(bracket, syncPicks);

    return bracket;
};

const loadGroups = async () => {
    if (!isAuthenticated.value) {
        return;
    }

    const response = await api.groups.index<ApiResource<Group[]>>({
        query: {
            type: 'bracket_pool',
            sport: 'cbb',
            season: currentSeason.value,
        },
    });

    const groups = response?.data ?? [];
    availableGroups.value = groups;

    if (
        !bracketGroupIdDraft.value &&
        groups.length > 0 &&
        groups.some((group: Group) => group.owner_id !== currentUserId.value)
    ) {
        bracketGroupIdDraft.value = String(groups[0].id);
    }
};

const saveBracketMeta = async () => {
    if (
        !isAuthenticated.value ||
        !currentBracket.value ||
        isBracketLocked.value
    ) {
        return;
    }

    metaSaveState.value = 'saving';

    try {
        const resolvedGroupId = bracketGroupIdDraft.value
            ? Number(bracketGroupIdDraft.value)
            : requiresGroupAssignment.value && availableGroups.value.length
              ? availableGroups.value[0].id
              : null;

        const response = await api.cbbBrackets.update<
            ApiResource<SavedBracket>
        >(
            currentBracket.value.public_id,
            {
                name: bracketNameDraft.value.trim() || null,
                group_id: resolvedGroupId,
            },
        );

        const savedBracket = response?.data;
        if (!savedBracket) {
            metaSaveState.value = 'error';
            return;
        }

        upsertBracketInList(savedBracket);
        activateBracket(savedBracket, false);
        metaSaveState.value = 'saved';

        window.setTimeout(() => {
            if (metaSaveState.value === 'saved') {
                metaSaveState.value = 'idle';
            }
        }, 2000);
    } catch {
        metaSaveState.value = 'error';
    }
};

const switchBracket = (publicId: string) => {
    const bracket =
        brackets.value.find((entry) => entry.public_id === publicId) ?? null;
    activateBracket(bracket);
};

const loadServerBrackets = async () => {
    if (!isAuthenticated.value) {
        serverLoaded.value = true;
        return;
    }

    try {
        const response = await api.cbbBrackets.index<
            ApiResource<SavedBracket[]>
        >({
            query: { season: currentSeason.value },
        });

        const serverBrackets = response?.data ?? [];
        brackets.value = serverBrackets;

        if (serverBrackets.length > 0) {
            const activeBracket =
                serverBrackets.find(
                    (entry) => entry.public_id === activeBracketPublicId.value,
                ) ?? serverBrackets[0];
            activateBracket(activeBracket);
        } else if (
            !isBracketLocked.value &&
            Object.keys(picks.value).length > 0
        ) {
            await createBracket(picks.value);
        } else {
            activateBracket(null);
        }
    } finally {
        serverLoaded.value = true;
    }
};

const loadLeaderboard = async () => {
    if (!isAuthenticated.value) {
        return;
    }

    const response = await api.cbbBrackets.leaderboard<
        ApiResource<LeaderboardEntry[]>
    >({
        query: {
            season: currentSeason.value,
            limit: 10,
            group_id: currentBracket.value?.group_id ?? undefined,
        },
    });

    leaderboard.value = response?.data ?? [];
};

onMounted(() => {
    readLocalPicks();
    void loadGroups();
    void loadServerBrackets();
    void loadLeaderboard();
});

watch(
    () => currentBracket.value?.group_id,
    () => {
        void loadLeaderboard();
    },
);

watch(
    picks,
    (value) => {
        if (typeof window === 'undefined') return;
        window.localStorage.setItem(storageKey, JSON.stringify(value));
    },
    { deep: true },
);

watch(
    picks,
    (value, _oldValue, onCleanup) => {
        if (
            !isAuthenticated.value ||
            !storageLoaded.value ||
            !serverLoaded.value ||
            isBracketLocked.value
        ) {
            return;
        }

        saveState.value = 'saving';

        const timeoutId = window.setTimeout(async () => {
            try {
                let savedBracket: SavedBracket;

                if (activeBracketPublicId.value) {
                    const response = await api.cbbBrackets.update<
                        ApiResource<SavedBracket>
                    >(
                        activeBracketPublicId.value,
                        {
                            picks: value,
                        },
                    );

                    if (!response?.data) {
                        saveState.value = 'error';
                        return;
                    }

                    savedBracket = response.data;
                } else {
                    const createdBracket = await createBracket(value, false);

                    if (!createdBracket) {
                        return;
                    }

                    savedBracket = createdBracket;
                }

                saveState.value = 'saved';
                upsertBracketInList(savedBracket);
                activateBracket(savedBracket, false);
            } catch (error: unknown) {
                if (apiStatus(error) === 423) {
                    saveState.value = 'idle';
                    currentBracket.value = currentBracket.value
                        ? {
                              ...currentBracket.value,
                              is_locked: true,
                              can_edit: false,
                              lock_at:
                                  apiLockAt(error) ??
                                  currentBracket.value.lock_at ??
                                  bracketLockAtIso,
                          }
                        : null;
                } else {
                    saveState.value = 'error';
                }
            }
        }, 500);

        onCleanup(() => window.clearTimeout(timeoutId));
    },
    { deep: true },
);

watch(
    allBracketMatchups,
    (matchups) => {
        const validSelections = new Map(
            matchups.map((matchup) => [
                matchup.id,
                new Set(
                    matchup.participants
                        .map((slot) => resolveSlotParticipant(slot)?.id)
                        .filter((participantId): participantId is string =>
                            Boolean(participantId),
                        ),
                ),
            ]),
        );

        const filteredEntries = Object.entries(picks.value).filter(
            ([matchupId, participantId]) =>
                validSelections.get(matchupId)?.has(participantId),
        );

        if (filteredEntries.length !== Object.keys(picks.value).length) {
            picks.value = Object.fromEntries(filteredEntries);
        }
    },
    { deep: true, immediate: true },
);
</script>

<template>
    <Head title="March Madness Bracket Builder">
        <meta
            head-key="description"
            name="description"
            content="Build a March Madness bracket using real NCAA tournament games synced into PickSports."
        />
        <meta
            head-key="og:title"
            property="og:title"
            content="March Madness Bracket Builder"
        />
        <meta
            head-key="og:description"
            property="og:description"
            content="Interactive March Madness bracket builder powered by real tournament game data."
        />
    </Head>

    <div class="min-h-screen overflow-x-hidden bg-background text-foreground">
        <nav
            class="sticky top-0 z-50 border-b border-border/60 bg-background/72 backdrop-blur-xl"
            style="padding-top: env(safe-area-inset-top)"
        >
            <div
                class="mx-auto flex max-w-[1500px] items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8"
            >
                <Link href="/" class="flex items-center gap-3">
                    <div
                        class="flex size-9 items-center justify-center rounded-xl bg-primary text-sm font-black text-primary-foreground"
                    >
                        PS
                    </div>
                    <div>
                        <p
                            class="text-sm font-semibold tracking-[0.24em] text-foreground/90 uppercase"
                        >
                            PickSports
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Bracket Builder
                        </p>
                    </div>
                </Link>

                <Button
                    type="button"
                    variant="ghost"
                    class="border border-border/70 bg-card/60 text-foreground hover:bg-accent/70"
                    @click="toggleAppearance"
                >
                    {{ appearanceToggleLabel }}
                </Button>
            </div>
        </nav>

        <main
            class="relative"
            style="padding-bottom: env(safe-area-inset-bottom)"
        >
            <section
                class="mx-auto max-w-[1500px] px-4 pt-6 pb-8 sm:px-6 lg:px-8 lg:pt-10"
            >
                <div class="space-y-6">
                    <div class="ui-surface rounded-[2rem] p-4 sm:p-5">
                        <div
                            class="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] xl:items-start"
                        >
                            <div class="min-w-0 space-y-3">
                                <p
                                    class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                                >
                                    Active bracket
                                </p>
                                <p
                                    class="mt-2 truncate text-xl font-semibold text-foreground sm:text-2xl"
                                >
                                    {{
                                        isAuthenticated
                                            ? activeBracketName
                                            : 'Local bracket'
                                    }}
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                    >
                                        Season {{ currentSeason }}
                                    </span>
                                    <span
                                        v-if="
                                            isAuthenticated &&
                                            selectedGroupName !== 'No group'
                                        "
                                        class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                    >
                                        {{ selectedGroupName }}
                                    </span>
                                    <span
                                        class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] uppercase"
                                        :class="
                                            isBracketLocked
                                                ? 'text-destructive'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{
                                            isBracketLocked
                                                ? 'Locked'
                                                : 'Editable'
                                        }}
                                    </span>
                                    <span
                                        v-if="isAuthenticated"
                                        class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                    >
                                        {{ brackets.length }} saved
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                    >
                                        Local only
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-3 xl:min-w-0">
                                <div
                                    v-if="isAuthenticated"
                                    class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto]"
                                >
                                    <div class="space-y-1.5">
                                        <label
                                            class="text-[11px] font-medium tracking-[0.18em] text-muted-foreground uppercase"
                                            >Switch Bracket</label
                                        >
                                        <select
                                            :value="activeBracketPublicId ?? ''"
                                            class="ui-select rounded-2xl bg-card/70 text-foreground"
                                            @change="
                                                switchBracket(
                                                    (
                                                        $event.target as HTMLSelectElement
                                                    ).value,
                                                )
                                            "
                                        >
                                            <option
                                                v-if="!brackets.length"
                                                value=""
                                            >
                                                No saved brackets
                                            </option>
                                            <option
                                                v-for="bracket in brackets"
                                                :key="bracket.public_id"
                                                :value="bracket.public_id"
                                            >
                                                {{
                                                    bracket.name ||
                                                    'Untitled bracket'
                                                }}
                                            </option>
                                        </select>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        class="self-end border border-border/70 bg-card/60 text-foreground hover:bg-accent/70 disabled:cursor-not-allowed disabled:opacity-45"
                                        :disabled="isBracketLocked"
                                        @click="createBracket()"
                                    >
                                        New Bracket
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        class="self-end border border-border/70 bg-card/60 text-foreground hover:bg-accent/70 disabled:cursor-not-allowed disabled:opacity-45"
                                        :disabled="isBracketLocked"
                                        @click="requestResetBracket"
                                    >
                                        Reset Picks
                                    </Button>
                                </div>

                                <div
                                    v-if="isAuthenticated && currentBracket"
                                    class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto]"
                                >
                                    <div class="space-y-1.5">
                                        <label
                                            class="text-[11px] font-medium tracking-[0.18em] text-muted-foreground uppercase"
                                            >Bracket Name</label
                                        >
                                        <Input
                                            v-model="bracketNameDraft"
                                            placeholder="Bracket name"
                                            class="border-border/70 bg-card/70 text-foreground placeholder:text-muted-foreground"
                                            :disabled="
                                                !currentBracket ||
                                                isBracketLocked
                                            "
                                        />
                                    </div>
                                    <div class="space-y-1.5">
                                        <label
                                            class="text-[11px] font-medium tracking-[0.18em] text-muted-foreground uppercase"
                                            >Group</label
                                        >
                                        <select
                                            v-model="bracketGroupIdDraft"
                                            class="ui-select rounded-2xl bg-card/70 text-foreground"
                                            :disabled="
                                                !currentBracket ||
                                                isBracketLocked
                                            "
                                        >
                                            <option
                                                v-if="!requiresGroupAssignment"
                                                value=""
                                            >
                                                No group
                                            </option>
                                            <option
                                                v-for="group in availableGroups"
                                                :key="group.public_id"
                                                :value="String(group.id)"
                                            >
                                                {{ group.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        class="self-end border border-border/70 bg-card/60 text-foreground hover:bg-accent/70 disabled:cursor-not-allowed disabled:opacity-45"
                                        :disabled="
                                            !currentBracket ||
                                            isBracketLocked ||
                                            metaSaveState === 'saving'
                                        "
                                        @click="saveBracketMeta"
                                    >
                                        {{ metaSaveLabel }}
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                        >
                            <div
                                class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] tracking-[0.2em] text-muted-foreground uppercase"
                                >
                                    Progress
                                </p>
                                <div class="mt-2 flex items-center gap-3">
                                    <p
                                        class="text-xl font-semibold text-foreground"
                                    >
                                        {{ completionPct }}%
                                    </p>
                                    <div
                                        class="h-2 flex-1 rounded-full bg-accent/70"
                                    >
                                        <div
                                            class="h-full rounded-full bg-primary/80 transition-[width]"
                                            :style="{
                                                width: `${completionPct}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                                <p
                                    class="mt-2 text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                                >
                                    {{ pickedGames }}/{{ totalGames }} picked
                                </p>
                            </div>

                            <div
                                class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] tracking-[0.2em] text-muted-foreground uppercase"
                                >
                                    Champion
                                </p>
                                <p
                                    class="mt-2 text-xl font-semibold text-foreground"
                                >
                                    {{ champion ?? 'TBD' }}
                                </p>
                                <p
                                    class="mt-2 text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                                >
                                    {{
                                        isBracketLocked
                                            ? 'Bracket locked'
                                            : 'Still editable'
                                    }}
                                </p>
                            </div>

                            <div
                                v-if="isAuthenticated && currentBracket"
                                class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] tracking-[0.2em] text-muted-foreground uppercase"
                                >
                                    Score
                                </p>
                                <p
                                    class="mt-2 text-xl font-semibold text-foreground"
                                >
                                    {{ currentBracket.points_earned }} pts
                                </p>
                                <p
                                    class="mt-2 text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                                >
                                    {{ currentBracket.correct_picks }} correct ·
                                    {{ currentBracket.incorrect_picks }} missed
                                </p>
                            </div>
                        </div>

                        <div
                            class="mt-3 rounded-2xl border border-border/70 bg-card/45 px-4 py-3"
                        >
                            <div
                                v-if="isAuthenticated && currentBracket"
                                class="flex flex-col gap-2 text-xs md:flex-row md:items-center md:justify-between"
                            >
                                <div
                                    class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-4"
                                >
                                    <p
                                        class="tracking-[0.18em] uppercase"
                                        :class="picksAutosaveToneClass"
                                    >
                                        {{ picksAutosaveLabel }}
                                    </p>
                                    <p
                                        class="tracking-[0.18em] uppercase"
                                        :class="metaSaveToneClass"
                                    >
                                        Details:
                                        {{
                                            metaSaveState === 'idle'
                                                ? 'manual save'
                                                : metaSaveLabel.toLowerCase()
                                        }}
                                    </p>
                                </div>
                                <a href="#bracket-board" class="inline-flex">
                                    <Button
                                        size="sm"
                                        class="bg-primary text-primary-foreground hover:opacity-90"
                                    >
                                        Jump to Bracket
                                    </Button>
                                </a>
                            </div>
                            <p
                                v-else
                                class="text-xs tracking-[0.18em] text-muted-foreground uppercase"
                            >
                                {{ saveStateLabel }}
                            </p>
                        </div>
                    </div>

                    <div class="ui-surface rounded-[2rem] p-4 sm:p-5">
                        <div
                            class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
                        >
                            <div>
                                <p
                                    class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                                >
                                    How It Works
                                </p>
                                <h2
                                    class="mt-2 text-xl font-semibold text-foreground sm:text-2xl"
                                >
                                    Make picks round by round
                                </h2>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 lg:grid-cols-3">
                            <div
                                class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                >
                                    1. Start Here
                                </p>
                                <p
                                    class="mt-2 text-sm leading-6 text-foreground"
                                >
                                    Pick winners on each region board. First
                                    Four winners feed into the main bracket
                                    automatically.
                                </p>
                            </div>
                            <div
                                class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                >
                                    2. Saving
                                </p>
                                <p
                                    class="mt-2 text-sm leading-6 text-foreground"
                                >
                                    Picks autosave as you go. Bracket name and
                                    group changes use
                                    <span class="font-semibold"
                                        >Save Details</span
                                    >.
                                </p>
                            </div>
                            <div
                                class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                >
                                    3. Scoring
                                </p>
                                <p
                                    class="mt-2 text-sm leading-6 text-foreground"
                                >
                                    Correct picks earn more points each round.
                                    Final scores update your grade and
                                    leaderboard rank automatically.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="bracket-board"
                class="mx-auto max-w-[1500px] px-4 pb-24 sm:px-6 lg:px-8"
            >
                <div
                    class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p
                            class="text-sm tracking-[0.28em] text-muted-foreground uppercase"
                        >
                            Bracket Board
                        </p>
                        <h2
                            class="mt-2 text-2xl font-semibold tracking-tight text-foreground sm:text-4xl"
                        >
                            Complete the full bracket here
                        </h2>
                    </div>
                </div>

                <div v-if="allBracketMatchups.length" class="space-y-8">
                    <article
                        v-if="isAuthenticated && leaderboard.length"
                        class="relative overflow-hidden rounded-[2rem] border border-border/70 bg-card/80 p-4 shadow-[0_20px_60px_-32px_rgba(15,23,42,0.18)] backdrop-blur sm:p-6"
                    >
                        <div
                            class="absolute inset-0 bg-gradient-to-br from-foreground/[0.04] via-transparent to-transparent dark:from-foreground/[0.06]"
                        />
                        <div class="relative">
                            <div class="flex items-end justify-between gap-4">
                                <div>
                                    <p
                                        class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                                    >
                                        Leaderboard
                                    </p>
                                    <h3
                                        class="mt-2 text-xl font-semibold text-foreground sm:text-2xl"
                                    >
                                        Bracket standings
                                    </h3>
                                </div>
                                <p
                                    class="text-xs tracking-[0.18em] text-muted-foreground uppercase"
                                >
                                    Season {{ currentSeason }}
                                </p>
                            </div>

                            <div
                                class="mt-5 overflow-hidden rounded-[1.5rem] border border-border/70 bg-background/35"
                            >
                                <div
                                    v-for="entry in leaderboard"
                                    :key="entry.bracket_public_id"
                                    class="grid grid-cols-[56px_minmax(0,1fr)_84px] items-center gap-3 border-b border-border/60 px-4 py-3 last:border-b-0"
                                >
                                    <div
                                        class="text-sm font-semibold text-foreground/80"
                                    >
                                        #{{ entry.rank }}
                                    </div>
                                    <div class="min-w-0">
                                        <p
                                            class="truncate text-sm font-semibold text-foreground"
                                        >
                                            {{ entry.bracket_name }}
                                        </p>
                                        <p
                                            class="truncate text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                                        >
                                            {{
                                                maskedLeaderboardUser(
                                                    entry.user_name,
                                                )
                                            }}
                                            · {{ entry.correct_picks }} correct
                                            · {{ entry.incorrect_picks }} missed
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p
                                            class="text-lg font-semibold text-foreground"
                                        >
                                            {{ entry.points_earned }}
                                        </p>
                                        <p
                                            class="text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                                        >
                                            pts
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <a
                            v-for="region in bracketRegions"
                            :key="`jump-${region.id}`"
                            :href="`#region-${region.id}`"
                            class="rounded-2xl border border-border/70 bg-card/65 px-4 py-3 backdrop-blur transition-colors hover:border-border hover:bg-accent/55"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <p
                                        class="text-[11px] tracking-[0.2em] text-muted-foreground uppercase"
                                    >
                                        Region
                                    </p>
                                    <p
                                        class="text-base font-semibold text-foreground"
                                    >
                                        {{ region.name }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{ regionCompletion(region).pct }}%
                                    </p>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        {{ regionCompletion(region).picked }}/{{
                                            regionCompletion(region).total
                                        }}
                                    </p>
                                </div>
                            </div>
                        </a>
                    </div>

                    <article
                        class="relative overflow-hidden rounded-[2rem] border border-border/70 bg-card/80 p-4 shadow-[0_20px_60px_-32px_rgba(15,23,42,0.18)] backdrop-blur sm:p-6"
                    >
                        <div
                            class="absolute inset-0 bg-gradient-to-br from-foreground/[0.04] via-transparent to-transparent dark:from-foreground/[0.06]"
                        />
                        <div class="relative">
                            <div
                                class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between"
                            >
                                <div>
                                    <p
                                        class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                                    >
                                        Finals
                                    </p>
                                    <h3
                                        class="mt-2 text-xl font-semibold text-foreground sm:text-2xl"
                                    >
                                        Final Four and Title Game
                                    </h3>
                                </div>
                                <p
                                    class="max-w-md text-sm leading-6 text-muted-foreground"
                                >
                                    Regional winners flow here automatically as
                                    you complete each board.
                                </p>
                            </div>

                            <div
                                class="mt-5 grid gap-5 xl:grid-cols-[1fr_0.92fr]"
                            >
                                <section>
                                    <p
                                        class="mb-3 text-xs font-semibold tracking-[0.22em] text-muted-foreground uppercase"
                                    >
                                        {{ roundLabels.final_four }}
                                    </p>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div
                                            v-for="matchup in standaloneRounds.finalFour"
                                            :key="matchup.id"
                                            class="rounded-2xl border border-border/70 bg-card/70 p-3 backdrop-blur"
                                        >
                                            <div class="space-y-2">
                                                <button
                                                    type="button"
                                                    class="w-full rounded-xl border px-3 py-2 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                                    :class="
                                                        resultToneClass(
                                                            matchup,
                                                            matchup
                                                                .participants[0],
                                                        )
                                                    "
                                                    :disabled="
                                                        isBracketLocked ||
                                                        !resolveSlotParticipant(
                                                            matchup
                                                                .participants[0],
                                                        )
                                                    "
                                                    @click="
                                                        selectWinner(
                                                            matchup,
                                                            matchup
                                                                .participants[0],
                                                        )
                                                    "
                                                >
                                                    <div
                                                        class="flex items-center gap-2.5"
                                                    >
                                                        <img
                                                            v-if="
                                                                participantLogo(
                                                                    matchup
                                                                        .participants[0],
                                                                )
                                                            "
                                                            :src="
                                                                participantLogo(
                                                                    matchup
                                                                        .participants[0],
                                                                )!
                                                            "
                                                            :alt="
                                                                participantButtonLabel(
                                                                    matchup
                                                                        .participants[0],
                                                                    'Regional winner advances here',
                                                                )
                                                            "
                                                            class="size-8 rounded-full bg-background/70 object-contain p-1"
                                                        />
                                                        <div class="min-w-0">
                                                            <p
                                                                class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                                            >
                                                                {{
                                                                    participantButtonAbbr(
                                                                        matchup
                                                                            .participants[0],
                                                                        'TBD',
                                                                    )
                                                                }}
                                                            </p>
                                                            <div
                                                                class="mt-0.5 flex items-start gap-2"
                                                            >
                                                                <span
                                                                    v-if="
                                                                        participantSeed(
                                                                            matchup
                                                                                .participants[0],
                                                                        )
                                                                    "
                                                                    class="inline-flex min-w-7 justify-center rounded-full border border-border/70 bg-background/70 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground"
                                                                >
                                                                    {{
                                                                        participantSeed(
                                                                            matchup
                                                                                .participants[0],
                                                                        )
                                                                    }}
                                                                </span>
                                                                <div
                                                                    class="min-w-0"
                                                                >
                                                                    <p
                                                                        class="text-sm leading-5 font-semibold text-foreground"
                                                                    >
                                                                        {{
                                                                            participantButtonLabel(
                                                                                matchup
                                                                                    .participants[0],
                                                                                'Regional winner advances here',
                                                                            )
                                                                        }}
                                                                    </p>
                                                                    <p
                                                                        v-if="
                                                                            slotWinProbability(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[0],
                                                                            ) !==
                                                                            null
                                                                        "
                                                                        class="text-[11px]"
                                                                        :class="
                                                                            slotWinProbabilityClass(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[0],
                                                                            )
                                                                        "
                                                                    >
                                                                        {{
                                                                            slotWinProbability(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[0],
                                                                            )
                                                                        }}% ML
                                                                    </p>
                                                                    <p
                                                                        v-if="
                                                                            resultBadgeLabel(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[0],
                                                                            )
                                                                        "
                                                                        class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-[10px] tracking-[0.14em] uppercase"
                                                                        :class="
                                                                            resultBadgeClass(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[0],
                                                                            )
                                                                        "
                                                                    >
                                                                        {{
                                                                            resultBadgeLabel(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[0],
                                                                            )
                                                                        }}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="w-full rounded-xl border px-3 py-2 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                                    :class="
                                                        resultToneClass(
                                                            matchup,
                                                            matchup
                                                                .participants[1],
                                                        )
                                                    "
                                                    :disabled="
                                                        isBracketLocked ||
                                                        !resolveSlotParticipant(
                                                            matchup
                                                                .participants[1],
                                                        )
                                                    "
                                                    @click="
                                                        selectWinner(
                                                            matchup,
                                                            matchup
                                                                .participants[1],
                                                        )
                                                    "
                                                >
                                                    <div
                                                        class="flex items-center gap-2.5"
                                                    >
                                                        <img
                                                            v-if="
                                                                participantLogo(
                                                                    matchup
                                                                        .participants[1],
                                                                )
                                                            "
                                                            :src="
                                                                participantLogo(
                                                                    matchup
                                                                        .participants[1],
                                                                )!
                                                            "
                                                            :alt="
                                                                participantButtonLabel(
                                                                    matchup
                                                                        .participants[1],
                                                                    'Regional winner advances here',
                                                                )
                                                            "
                                                            class="size-8 rounded-full bg-background/70 object-contain p-1"
                                                        />
                                                        <div class="min-w-0">
                                                            <p
                                                                class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                                            >
                                                                {{
                                                                    participantButtonAbbr(
                                                                        matchup
                                                                            .participants[1],
                                                                        'TBD',
                                                                    )
                                                                }}
                                                            </p>
                                                            <div
                                                                class="mt-0.5 flex items-start gap-2"
                                                            >
                                                                <span
                                                                    v-if="
                                                                        participantSeed(
                                                                            matchup
                                                                                .participants[1],
                                                                        )
                                                                    "
                                                                    class="inline-flex min-w-7 justify-center rounded-full border border-border/70 bg-background/70 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground"
                                                                >
                                                                    {{
                                                                        participantSeed(
                                                                            matchup
                                                                                .participants[1],
                                                                        )
                                                                    }}
                                                                </span>
                                                                <div
                                                                    class="min-w-0"
                                                                >
                                                                    <p
                                                                        class="text-sm leading-5 font-semibold text-foreground"
                                                                    >
                                                                        {{
                                                                            participantButtonLabel(
                                                                                matchup
                                                                                    .participants[1],
                                                                                'Regional winner advances here',
                                                                            )
                                                                        }}
                                                                    </p>
                                                                    <p
                                                                        v-if="
                                                                            slotWinProbability(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[1],
                                                                            ) !==
                                                                            null
                                                                        "
                                                                        class="text-[11px]"
                                                                        :class="
                                                                            slotWinProbabilityClass(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[1],
                                                                            )
                                                                        "
                                                                    >
                                                                        {{
                                                                            slotWinProbability(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[1],
                                                                            )
                                                                        }}% ML
                                                                    </p>
                                                                    <p
                                                                        v-if="
                                                                            resultBadgeLabel(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[1],
                                                                            )
                                                                        "
                                                                        class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-[10px] tracking-[0.14em] uppercase"
                                                                        :class="
                                                                            resultBadgeClass(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[1],
                                                                            )
                                                                        "
                                                                    >
                                                                        {{
                                                                            resultBadgeLabel(
                                                                                matchup,
                                                                                matchup
                                                                                    .participants[1],
                                                                            )
                                                                        }}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </button>
                                            </div>

                                            <p
                                                class="mt-3 text-[11px] leading-5 text-muted-foreground"
                                            >
                                                {{ formatVenue(matchup.game) }}
                                            </p>
                                        </div>
                                    </div>
                                </section>

                                <section>
                                    <p
                                        class="mb-3 text-xs font-semibold tracking-[0.22em] text-muted-foreground uppercase"
                                    >
                                        {{ roundLabels.national_championship }}
                                    </p>
                                    <div
                                        class="rounded-[1.8rem] border border-border/70 bg-card/72 p-3.5 backdrop-blur sm:p-4"
                                    >
                                        <div class="space-y-2">
                                            <button
                                                type="button"
                                                class="w-full rounded-xl border px-3 py-2.5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                                :class="
                                                    resultToneClass(
                                                        standaloneRounds.championship,
                                                        standaloneRounds
                                                            .championship
                                                            .participants[0],
                                                    )
                                                "
                                                :disabled="
                                                    isBracketLocked ||
                                                    !resolveSlotParticipant(
                                                        standaloneRounds
                                                            .championship
                                                            .participants[0],
                                                    )
                                                "
                                                @click="
                                                    selectWinner(
                                                        standaloneRounds.championship,
                                                        standaloneRounds
                                                            .championship
                                                            .participants[0],
                                                    )
                                                "
                                            >
                                                <div
                                                    class="flex items-center gap-2.5"
                                                >
                                                    <img
                                                        v-if="
                                                            participantLogo(
                                                                standaloneRounds
                                                                    .championship
                                                                    .participants[0],
                                                            )
                                                        "
                                                        :src="
                                                            participantLogo(
                                                                standaloneRounds
                                                                    .championship
                                                                    .participants[0],
                                                            )!
                                                        "
                                                        :alt="
                                                            participantButtonLabel(
                                                                standaloneRounds
                                                                    .championship
                                                                    .participants[0],
                                                                'Semifinal winner advances here',
                                                            )
                                                        "
                                                        class="size-8 rounded-full bg-background/70 object-contain p-1"
                                                    />
                                                    <div class="min-w-0">
                                                        <p
                                                            class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                                        >
                                                            {{
                                                                participantButtonAbbr(
                                                                    standaloneRounds
                                                                        .championship
                                                                        .participants[0],
                                                                    'TBD',
                                                                )
                                                            }}
                                                        </p>
                                                        <div
                                                            class="mt-0.5 flex items-start gap-2"
                                                        >
                                                            <span
                                                                v-if="
                                                                    participantSeed(
                                                                        standaloneRounds
                                                                            .championship
                                                                            .participants[0],
                                                                    )
                                                                "
                                                                class="inline-flex min-w-7 justify-center rounded-full border border-border/70 bg-background/70 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground"
                                                            >
                                                                {{
                                                                    participantSeed(
                                                                        standaloneRounds
                                                                            .championship
                                                                            .participants[0],
                                                                    )
                                                                }}
                                                            </span>
                                                            <div
                                                                class="min-w-0"
                                                            >
                                                                <p
                                                                    class="text-base leading-5 font-semibold text-foreground"
                                                                >
                                                                    {{
                                                                        participantButtonLabel(
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[0],
                                                                            'Semifinal winner advances here',
                                                                        )
                                                                    }}
                                                                </p>
                                                                <p
                                                                    v-if="
                                                                        slotWinProbability(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[0],
                                                                        ) !==
                                                                        null
                                                                    "
                                                                    class="text-[11px]"
                                                                    :class="
                                                                        slotWinProbabilityClass(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[0],
                                                                        )
                                                                    "
                                                                >
                                                                    {{
                                                                        slotWinProbability(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[0],
                                                                        )
                                                                    }}% ML
                                                                </p>
                                                                <p
                                                                    v-if="
                                                                        resultBadgeLabel(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[0],
                                                                        )
                                                                    "
                                                                    class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-[10px] tracking-[0.14em] uppercase"
                                                                    :class="
                                                                        resultBadgeClass(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[0],
                                                                        )
                                                                    "
                                                                >
                                                                    {{
                                                                        resultBadgeLabel(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[0],
                                                                        )
                                                                    }}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </button>

                                            <button
                                                type="button"
                                                class="w-full rounded-xl border px-3 py-2.5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                                :class="
                                                    resultToneClass(
                                                        standaloneRounds.championship,
                                                        standaloneRounds
                                                            .championship
                                                            .participants[1],
                                                    )
                                                "
                                                :disabled="
                                                    isBracketLocked ||
                                                    !resolveSlotParticipant(
                                                        standaloneRounds
                                                            .championship
                                                            .participants[1],
                                                    )
                                                "
                                                @click="
                                                    selectWinner(
                                                        standaloneRounds.championship,
                                                        standaloneRounds
                                                            .championship
                                                            .participants[1],
                                                    )
                                                "
                                            >
                                                <div
                                                    class="flex items-center gap-2.5"
                                                >
                                                    <img
                                                        v-if="
                                                            participantLogo(
                                                                standaloneRounds
                                                                    .championship
                                                                    .participants[1],
                                                            )
                                                        "
                                                        :src="
                                                            participantLogo(
                                                                standaloneRounds
                                                                    .championship
                                                                    .participants[1],
                                                            )!
                                                        "
                                                        :alt="
                                                            participantButtonLabel(
                                                                standaloneRounds
                                                                    .championship
                                                                    .participants[1],
                                                                'Semifinal winner advances here',
                                                            )
                                                        "
                                                        class="size-8 rounded-full bg-background/70 object-contain p-1"
                                                    />
                                                    <div class="min-w-0">
                                                        <p
                                                            class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                                        >
                                                            {{
                                                                participantButtonAbbr(
                                                                    standaloneRounds
                                                                        .championship
                                                                        .participants[1],
                                                                    'TBD',
                                                                )
                                                            }}
                                                        </p>
                                                        <div
                                                            class="mt-0.5 flex items-start gap-2"
                                                        >
                                                            <span
                                                                v-if="
                                                                    participantSeed(
                                                                        standaloneRounds
                                                                            .championship
                                                                            .participants[1],
                                                                    )
                                                                "
                                                                class="inline-flex min-w-7 justify-center rounded-full border border-border/70 bg-background/70 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground"
                                                            >
                                                                {{
                                                                    participantSeed(
                                                                        standaloneRounds
                                                                            .championship
                                                                            .participants[1],
                                                                    )
                                                                }}
                                                            </span>
                                                            <div
                                                                class="min-w-0"
                                                            >
                                                                <p
                                                                    class="text-base leading-5 font-semibold text-foreground"
                                                                >
                                                                    {{
                                                                        participantButtonLabel(
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[1],
                                                                            'Semifinal winner advances here',
                                                                        )
                                                                    }}
                                                                </p>
                                                                <p
                                                                    v-if="
                                                                        slotWinProbability(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[1],
                                                                        ) !==
                                                                        null
                                                                    "
                                                                    class="text-[11px]"
                                                                    :class="
                                                                        slotWinProbabilityClass(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[1],
                                                                        )
                                                                    "
                                                                >
                                                                    {{
                                                                        slotWinProbability(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[1],
                                                                        )
                                                                    }}% ML
                                                                </p>
                                                                <p
                                                                    v-if="
                                                                        resultBadgeLabel(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[1],
                                                                        )
                                                                    "
                                                                    class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-[10px] tracking-[0.14em] uppercase"
                                                                    :class="
                                                                        resultBadgeClass(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[1],
                                                                        )
                                                                    "
                                                                >
                                                                    {{
                                                                        resultBadgeLabel(
                                                                            standaloneRounds.championship,
                                                                            standaloneRounds
                                                                                .championship
                                                                                .participants[1],
                                                                        )
                                                                    }}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </button>
                                        </div>

                                        <p
                                            class="mt-3 text-[11px] leading-5 text-muted-foreground"
                                        >
                                            {{
                                                formatVenue(
                                                    standaloneRounds
                                                        .championship.game,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </article>

                    <article
                        v-for="region in bracketRegions"
                        :id="`region-${region.id}`"
                        :key="region.id"
                        class="relative overflow-hidden rounded-[2rem] border border-border/70 bg-card/80 p-4 shadow-[0_20px_60px_-32px_rgba(15,23,42,0.18)] backdrop-blur sm:p-6"
                    >
                        <div
                            class="absolute inset-0 bg-gradient-to-br"
                            :class="
                                accentByRegion[region.name] ??
                                accentByRegion.Unassigned
                            "
                        />
                        <div class="relative space-y-5">
                            <div
                                class="flex flex-col gap-4 xl:grid xl:grid-cols-[220px_minmax(0,1fr)] xl:items-start"
                            >
                                <div class="space-y-4">
                                    <div
                                        class="flex items-center justify-between gap-3 xl:block"
                                    >
                                        <div>
                                            <p
                                                class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                                            >
                                                Region
                                            </p>
                                            <h3
                                                class="text-2xl font-semibold text-foreground"
                                            >
                                                {{ region.name }}
                                            </h3>
                                        </div>
                                        <div
                                            class="rounded-full border border-border/70 bg-background/45 px-3 py-1 text-[11px] tracking-[0.22em] text-muted-foreground uppercase xl:mt-4 xl:inline-flex"
                                        >
                                            {{
                                                regionCompletion(region).picked
                                            }}/{{
                                                regionCompletion(region).total
                                            }}
                                            picked
                                        </div>
                                    </div>

                                    <div class="hidden space-y-3 xl:block">
                                        <div
                                            class="rounded-2xl border border-border/70 bg-background/45 p-3"
                                        >
                                            <p
                                                class="text-[11px] font-semibold tracking-[0.2em] text-foreground/80 uppercase"
                                            >
                                                {{
                                                    regionGuidanceLabel(region)
                                                }}
                                            </p>
                                            <p
                                                class="mt-2 text-sm leading-6 text-muted-foreground"
                                            >
                                                {{
                                                    regionGuidanceDetail(region)
                                                }}
                                            </p>
                                        </div>
                                        <div
                                            class="h-2 rounded-full bg-accent/70"
                                        >
                                            <div
                                                class="h-full rounded-full bg-primary/80 transition-[width]"
                                                :style="{
                                                    width: `${regionCompletion(region).pct}%`,
                                                }"
                                            />
                                        </div>
                                        <p
                                            class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                        >
                                            {{ regionCompletion(region).pct }}%
                                            complete
                                        </p>
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div
                                        class="grid gap-2 sm:grid-cols-2 lg:hidden"
                                    >
                                        <section
                                            v-for="round in region.rounds"
                                            :key="`mobile-${region.id}-${round.key}`"
                                            class="rounded-[1.35rem] border p-3 sm:p-3.5"
                                            :class="
                                                roundSectionClass(
                                                    region,
                                                    round.key,
                                                )
                                            "
                                        >
                                            <div
                                                class="mb-3 flex items-center justify-between gap-3 border-b border-border/60 pb-2"
                                            >
                                                <div>
                                                    <div
                                                        class="flex items-center gap-2"
                                                    >
                                                        <p
                                                            class="text-[11px] font-semibold tracking-[0.22em] text-muted-foreground uppercase"
                                                        >
                                                            {{ round.label }}
                                                        </p>
                                                        <span
                                                            class="rounded-full border border-border/70 bg-background/45 px-2 py-0.5 text-[9px] tracking-[0.14em] text-muted-foreground uppercase"
                                                        >
                                                            {{
                                                                roundHeaderBadge(
                                                                    region,
                                                                    round.key,
                                                                )
                                                            }}
                                                        </span>
                                                    </div>
                                                    <p
                                                        class="mt-1 text-[10px] tracking-[0.14em] text-muted-foreground uppercase"
                                                    >
                                                        {{
                                                            roundMeta(
                                                                region,
                                                                round.key,
                                                            ).picked
                                                        }}/{{
                                                            round.matchups
                                                                .length
                                                        }}
                                                        picked
                                                    </p>
                                                    <p
                                                        v-if="
                                                            isAuthenticated &&
                                                            currentBracket
                                                        "
                                                        class="mt-1 text-[10px] tracking-[0.14em] text-muted-foreground uppercase"
                                                    >
                                                        {{
                                                            roundMeta(
                                                                region,
                                                                round.key,
                                                            ).summaryLabel
                                                        }}
                                                    </p>
                                                </div>
                                                <div class="text-right">
                                                    <p
                                                        class="text-[11px] text-muted-foreground"
                                                    >
                                                        {{
                                                            round.matchups
                                                                .length
                                                        }}
                                                    </p>
                                                    <p
                                                        v-if="
                                                            isAuthenticated &&
                                                            currentBracket
                                                        "
                                                        class="mt-1 text-[10px] font-semibold text-muted-foreground"
                                                    >
                                                        {{
                                                            roundMeta(
                                                                region,
                                                                round.key,
                                                            ).summary
                                                                .pointsEarned
                                                        }}/{{
                                                            roundMeta(
                                                                region,
                                                                round.key,
                                                            ).summary
                                                                .possiblePoints
                                                        }}
                                                        pts
                                                    </p>
                                                </div>
                                            </div>

                                            <div class="space-y-3">
                                                <div
                                                    v-for="matchup in round.matchups"
                                                    :key="`mobile-card-${matchup.id}`"
                                                    class="rounded-2xl border border-border/70 bg-card/70 p-2.5 backdrop-blur"
                                                >
                                                    <div
                                                        v-if="
                                                            matchup.participants.some(
                                                                (slot) =>
                                                                    slot.sourceMatchupId,
                                                            )
                                                        "
                                                        class="mb-1.5 inline-flex rounded-full border border-dashed border-border/70 bg-card/60 px-2.5 py-1 text-[9px] tracking-[0.16em] text-foreground/75 uppercase"
                                                    >
                                                        {{
                                                            isRoundOf64PlayInCard(
                                                                matchup,
                                                            )
                                                                ? 'First Four'
                                                                : 'Play-in winner feeds this slot'
                                                        }}
                                                    </div>
                                                    <div
                                                        v-if="
                                                            compactMatchupSummary(
                                                                matchup,
                                                            )
                                                        "
                                                        class="mb-2 text-[10px] leading-4 text-foreground/70"
                                                        :class="
                                                            isRoundOf64PlayInCard(
                                                                matchup,
                                                            )
                                                                ? 'line-clamp-2'
                                                                : ''
                                                        "
                                                    >
                                                        {{
                                                            compactMatchupSummary(
                                                                matchup,
                                                            )
                                                        }}
                                                    </div>

                                                    <div class="space-y-2">
                                                        <button
                                                            type="button"
                                                            class="w-full rounded-xl border px-2.5 py-2 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                                            :class="
                                                                selectedSlotResultStatus(
                                                                    matchup,
                                                                    matchup
                                                                        .participants[0],
                                                                )
                                                                    ? resultToneClass(
                                                                          matchup,
                                                                          matchup
                                                                              .participants[0],
                                                                      )
                                                                    : slotIsPlaceholder(
                                                                            matchup
                                                                                .participants[0],
                                                                        )
                                                                      ? 'border-border/60 bg-card/55 text-muted-foreground hover:border-border/80 hover:bg-card/70'
                                                                      : 'border-border/70 bg-card/75 hover:border-border hover:bg-accent/60'
                                                            "
                                                            :disabled="
                                                                isBracketLocked ||
                                                                !resolveSlotParticipant(
                                                                    matchup
                                                                        .participants[0],
                                                                )
                                                            "
                                                            @click="
                                                                selectWinner(
                                                                    matchup,
                                                                    matchup
                                                                        .participants[0],
                                                                )
                                                            "
                                                        >
                                                            <div
                                                                class="flex items-center gap-2.5"
                                                            >
                                                                <img
                                                                    v-if="
                                                                        participantLogo(
                                                                            matchup
                                                                                .participants[0],
                                                                        )
                                                                    "
                                                                    :src="
                                                                        participantLogo(
                                                                            matchup
                                                                                .participants[0],
                                                                        )!
                                                                    "
                                                                    :alt="
                                                                        participantButtonLabel(
                                                                            matchup
                                                                                .participants[0],
                                                                            'Winner advances here',
                                                                        )
                                                                    "
                                                                    class="size-8 rounded-full bg-background/70 object-contain p-1"
                                                                />
                                                                <div
                                                                    class="min-w-0"
                                                                >
                                                                    <p
                                                                        v-if="
                                                                            !slotIsPlaceholder(
                                                                                matchup
                                                                                    .participants[0],
                                                                            )
                                                                        "
                                                                        class="text-[10px] tracking-[0.16em] text-muted-foreground uppercase"
                                                                    >
                                                                        {{
                                                                            participantButtonAbbr(
                                                                                matchup
                                                                                    .participants[0],
                                                                                'TBD',
                                                                            )
                                                                        }}
                                                                    </p>
                                                                    <div
                                                                        class="mt-0.5 flex items-start gap-2"
                                                                    >
                                                                        <span
                                                                            v-if="
                                                                                participantSeed(
                                                                                    matchup
                                                                                        .participants[0],
                                                                                )
                                                                            "
                                                                            class="inline-flex min-w-6 justify-center rounded-full border border-border/70 bg-card/80 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground"
                                                                        >
                                                                            {{
                                                                                participantSeed(
                                                                                    matchup
                                                                                        .participants[0],
                                                                                )
                                                                            }}
                                                                        </span>
                                                                        <div
                                                                            class="min-w-0"
                                                                        >
                                                                            <p
                                                                                class="text-sm leading-5 font-semibold"
                                                                                :class="
                                                                                    slotIsPlaceholder(
                                                                                        matchup
                                                                                            .participants[0],
                                                                                    )
                                                                                        ? 'text-muted-foreground'
                                                                                        : 'text-foreground'
                                                                                "
                                                                            >
                                                                                {{
                                                                                    participantButtonLabel(
                                                                                        matchup
                                                                                            .participants[0],
                                                                                        'Winner advances here',
                                                                                    )
                                                                                }}
                                                                            </p>
                                                                            <p
                                                                                v-if="
                                                                                    slotWinProbability(
                                                                                        matchup,
                                                                                        matchup
                                                                                            .participants[0],
                                                                                    ) !==
                                                                                    null
                                                                                "
                                                                                class="text-[11px]"
                                                                                :class="
                                                                                    slotWinProbabilityClass(
                                                                                        matchup,
                                                                                        matchup
                                                                                            .participants[0],
                                                                                    )
                                                                                "
                                                                            >
                                                                                {{
                                                                                    slotWinProbability(
                                                                                        matchup,
                                                                                        matchup
                                                                                            .participants[0],
                                                                                    )
                                                                                }}%
                                                                                ML
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </button>

                                                        <button
                                                            type="button"
                                                            class="w-full rounded-xl border px-2.5 py-2 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                                            :class="
                                                                selectedSlotResultStatus(
                                                                    matchup,
                                                                    matchup
                                                                        .participants[1],
                                                                )
                                                                    ? resultToneClass(
                                                                          matchup,
                                                                          matchup
                                                                              .participants[1],
                                                                      )
                                                                    : slotIsPlaceholder(
                                                                            matchup
                                                                                .participants[1],
                                                                        )
                                                                      ? 'border-border/60 bg-card/55 text-muted-foreground hover:border-border/80 hover:bg-card/70'
                                                                      : 'border-border/70 bg-card/75 hover:border-border hover:bg-accent/60'
                                                            "
                                                            :disabled="
                                                                isBracketLocked ||
                                                                !resolveSlotParticipant(
                                                                    matchup
                                                                        .participants[1],
                                                                )
                                                            "
                                                            @click="
                                                                selectWinner(
                                                                    matchup,
                                                                    matchup
                                                                        .participants[1],
                                                                )
                                                            "
                                                        >
                                                            <div
                                                                class="flex items-center gap-2.5"
                                                            >
                                                                <img
                                                                    v-if="
                                                                        participantLogo(
                                                                            matchup
                                                                                .participants[1],
                                                                        )
                                                                    "
                                                                    :src="
                                                                        participantLogo(
                                                                            matchup
                                                                                .participants[1],
                                                                        )!
                                                                    "
                                                                    :alt="
                                                                        participantButtonLabel(
                                                                            matchup
                                                                                .participants[1],
                                                                            'Winner advances here',
                                                                        )
                                                                    "
                                                                    class="size-8 rounded-full bg-background/70 object-contain p-1"
                                                                />
                                                                <div
                                                                    class="min-w-0"
                                                                >
                                                                    <p
                                                                        v-if="
                                                                            !slotIsPlaceholder(
                                                                                matchup
                                                                                    .participants[1],
                                                                            )
                                                                        "
                                                                        class="text-[10px] tracking-[0.16em] text-muted-foreground uppercase"
                                                                    >
                                                                        {{
                                                                            participantButtonAbbr(
                                                                                matchup
                                                                                    .participants[1],
                                                                                'TBD',
                                                                            )
                                                                        }}
                                                                    </p>
                                                                    <div
                                                                        class="mt-0.5 flex items-start gap-2"
                                                                    >
                                                                        <span
                                                                            v-if="
                                                                                participantSeed(
                                                                                    matchup
                                                                                        .participants[1],
                                                                                )
                                                                            "
                                                                            class="inline-flex min-w-6 justify-center rounded-full border border-border/70 bg-card/80 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground"
                                                                        >
                                                                            {{
                                                                                participantSeed(
                                                                                    matchup
                                                                                        .participants[1],
                                                                                )
                                                                            }}
                                                                        </span>
                                                                        <div
                                                                            class="min-w-0"
                                                                        >
                                                                            <p
                                                                                class="text-sm leading-5 font-semibold"
                                                                                :class="
                                                                                    slotIsPlaceholder(
                                                                                        matchup
                                                                                            .participants[1],
                                                                                    )
                                                                                        ? 'text-muted-foreground'
                                                                                        : 'text-foreground'
                                                                                "
                                                                            >
                                                                                {{
                                                                                    participantButtonLabel(
                                                                                        matchup
                                                                                            .participants[1],
                                                                                        'Winner advances here',
                                                                                    )
                                                                                }}
                                                                            </p>
                                                                            <p
                                                                                v-if="
                                                                                    slotWinProbability(
                                                                                        matchup,
                                                                                        matchup
                                                                                            .participants[1],
                                                                                    ) !==
                                                                                    null
                                                                                "
                                                                                class="text-[11px]"
                                                                                :class="
                                                                                    slotWinProbabilityClass(
                                                                                        matchup,
                                                                                        matchup
                                                                                            .participants[1],
                                                                                    )
                                                                                "
                                                                            >
                                                                                {{
                                                                                    slotWinProbability(
                                                                                        matchup,
                                                                                        matchup
                                                                                            .participants[1],
                                                                                    )
                                                                                }}%
                                                                                ML
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </section>
                                    </div>

                                    <div
                                        class="hidden overflow-x-auto pb-2 [scrollbar-width:thin] lg:block"
                                    >
                                        <div
                                            class="grid min-w-[1060px] grid-cols-4 gap-4 xl:gap-5"
                                        >
                                            <div
                                                v-for="round in region.rounds"
                                                :key="`${region.id}-${round.key}`"
                                                class="flex flex-col gap-4"
                                                :class="
                                                    roundColumnMinHeightClass(
                                                        round.key,
                                                    )
                                                "
                                            >
                                                <div
                                                    class="rounded-2xl border px-3 py-2"
                                                    :class="
                                                        roundSectionClass(
                                                            region,
                                                            round.key,
                                                        )
                                                    "
                                                >
                                                    <div
                                                        class="flex items-start justify-between gap-3"
                                                    >
                                                        <div>
                                                            <div
                                                                class="flex items-center gap-2"
                                                            >
                                                                <p
                                                                    class="text-[11px] font-semibold tracking-[0.24em] text-muted-foreground uppercase"
                                                                >
                                                                    {{
                                                                        round.label
                                                                    }}
                                                                </p>
                                                                <span
                                                                    class="rounded-full border border-border/70 bg-background/45 px-2 py-0.5 text-[9px] tracking-[0.14em] text-muted-foreground uppercase"
                                                                >
                                                                    {{
                                                                        roundHeaderBadge(
                                                                            region,
                                                                            round.key,
                                                                        )
                                                                    }}
                                                                </span>
                                                            </div>
                                                            <p
                                                                class="mt-1 text-[10px] tracking-[0.14em] text-muted-foreground uppercase"
                                                            >
                                                                {{
                                                                    roundMeta(
                                                                        region,
                                                                        round.key,
                                                                    ).picked
                                                                }}/{{
                                                                    round
                                                                        .matchups
                                                                        .length
                                                                }}
                                                                picked
                                                            </p>
                                                            <p
                                                                v-if="
                                                                    isAuthenticated &&
                                                                    currentBracket
                                                                "
                                                                class="mt-1 text-[10px] tracking-[0.14em] text-muted-foreground uppercase"
                                                            >
                                                                {{
                                                                    roundMeta(
                                                                        region,
                                                                        round.key,
                                                                    )
                                                                        .summaryLabel
                                                                }}
                                                            </p>
                                                        </div>
                                                        <p
                                                            v-if="
                                                                isAuthenticated &&
                                                                currentBracket
                                                            "
                                                            class="text-[10px] font-semibold text-muted-foreground"
                                                        >
                                                            {{
                                                                roundMeta(
                                                                    region,
                                                                    round.key,
                                                                ).summary
                                                                    .pointsEarned
                                                            }}/{{
                                                                roundMeta(
                                                                    region,
                                                                    round.key,
                                                                ).summary
                                                                    .possiblePoints
                                                            }}
                                                            pts
                                                        </p>
                                                    </div>
                                                </div>

                                                <div
                                                    class="grid flex-1 content-start gap-2.5 overflow-visible xl:gap-3"
                                                    :class="
                                                        roundColumnGridClass(
                                                            round.key,
                                                        )
                                                    "
                                                >
                                                    <div
                                                        v-for="(
                                                            matchup,
                                                            matchupIndex
                                                        ) in round.matchups"
                                                        :key="matchup.id"
                                                        class="relative self-start rounded-2xl border border-border/70 bg-card/70 p-2 backdrop-blur xl:p-2.5"
                                                        :style="
                                                            roundTrackStyle(
                                                                round.key,
                                                                matchupIndex,
                                                            )
                                                        "
                                                    >
                                                        <div
                                                            v-if="
                                                                matchup.participants.some(
                                                                    (slot) =>
                                                                        slot.sourceMatchupId,
                                                                )
                                                            "
                                                            class="mb-1 rounded-xl border border-dashed border-border/70 bg-card/60 px-2 py-1 text-[9px] tracking-[0.16em] text-foreground/75 uppercase"
                                                        >
                                                            {{
                                                                isRoundOf64PlayInCard(
                                                                    matchup,
                                                                )
                                                                    ? 'First Four'
                                                                    : 'Play-in winner feeds this slot'
                                                            }}
                                                        </div>
                                                        <div
                                                            v-if="
                                                                compactMatchupSummary(
                                                                    matchup,
                                                                )
                                                            "
                                                            class="mb-1 text-[9px] leading-3 text-foreground/70"
                                                            :class="
                                                                isRoundOf64PlayInCard(
                                                                    matchup,
                                                                )
                                                                    ? 'line-clamp-1'
                                                                    : ''
                                                            "
                                                        >
                                                            {{
                                                                compactMatchupSummary(
                                                                    matchup,
                                                                )
                                                            }}
                                                        </div>

                                                        <div
                                                            :class="
                                                                isRoundOf64PlayInCard(
                                                                    matchup,
                                                                )
                                                                    ? 'space-y-1'
                                                                    : 'space-y-1.25'
                                                            "
                                                        >
                                                            <button
                                                                type="button"
                                                                class="w-full rounded-xl border text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                                                :class="[
                                                                    isRoundOf64PlayInCard(
                                                                        matchup,
                                                                    )
                                                                        ? 'px-2 py-1'
                                                                        : 'px-2.5 py-1',
                                                                    resultToneClass(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[0],
                                                                    ),
                                                                    slotIsPlaceholder(
                                                                        matchup
                                                                            .participants[0],
                                                                    )
                                                                        ? 'border-border/60 bg-card/55 text-muted-foreground hover:border-border/80 hover:bg-card/70'
                                                                        : '',
                                                                ]"
                                                                :disabled="
                                                                    isBracketLocked ||
                                                                    !resolveSlotParticipant(
                                                                        matchup
                                                                            .participants[0],
                                                                    )
                                                                "
                                                                @click="
                                                                    selectWinner(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[0],
                                                                    )
                                                                "
                                                            >
                                                                <div
                                                                    class="flex items-center gap-2"
                                                                >
                                                                    <img
                                                                        v-if="
                                                                            participantLogo(
                                                                                matchup
                                                                                    .participants[0],
                                                                            )
                                                                        "
                                                                        :src="
                                                                            participantLogo(
                                                                                matchup
                                                                                    .participants[0],
                                                                            )!
                                                                        "
                                                                        :alt="
                                                                            participantButtonLabel(
                                                                                matchup
                                                                                    .participants[0],
                                                                                'Winner advances here',
                                                                            )
                                                                        "
                                                                        class="size-6 rounded-full bg-background/70 object-contain p-1"
                                                                    />
                                                                    <div
                                                                        class="min-w-0"
                                                                    >
                                                                        <p
                                                                            v-if="
                                                                                !slotIsPlaceholder(
                                                                                    matchup
                                                                                        .participants[0],
                                                                                )
                                                                            "
                                                                            class="text-[10px] tracking-[0.16em] text-muted-foreground uppercase"
                                                                        >
                                                                            {{
                                                                                participantButtonAbbr(
                                                                                    matchup
                                                                                        .participants[0],
                                                                                    'TBD',
                                                                                )
                                                                            }}
                                                                        </p>
                                                                        <div
                                                                            class="mt-0.5 flex items-start gap-1.5"
                                                                        >
                                                                            <span
                                                                                v-if="
                                                                                    participantSeed(
                                                                                        matchup
                                                                                            .participants[0],
                                                                                    )
                                                                                "
                                                                                class="inline-flex min-w-5 justify-center rounded-full border border-border/70 bg-card/80 px-1 py-0.5 text-[9px] leading-none font-semibold text-muted-foreground"
                                                                            >
                                                                                {{
                                                                                    participantSeed(
                                                                                        matchup
                                                                                            .participants[0],
                                                                                    )
                                                                                }}
                                                                            </span>
                                                                            <div
                                                                                class="min-w-0"
                                                                            >
                                                                                <p
                                                                                    class="font-semibold"
                                                                                    :class="[
                                                                                        isRoundOf64PlayInCard(
                                                                                            matchup,
                                                                                        )
                                                                                            ? 'text-[13px] leading-4'
                                                                                            : 'text-sm leading-4',
                                                                                        slotIsPlaceholder(
                                                                                            matchup
                                                                                                .participants[0],
                                                                                        )
                                                                                            ? 'text-muted-foreground'
                                                                                            : 'text-foreground',
                                                                                    ]"
                                                                                >
                                                                                    {{
                                                                                        participantButtonLabel(
                                                                                            matchup
                                                                                                .participants[0],
                                                                                            'Winner advances here',
                                                                                        )
                                                                                    }}
                                                                                </p>
                                                                                <p
                                                                                    v-if="
                                                                                        slotWinProbability(
                                                                                            matchup,
                                                                                            matchup
                                                                                                .participants[0],
                                                                                        ) !==
                                                                                        null
                                                                                    "
                                                                                    class="text-[10px]"
                                                                                    :class="
                                                                                        slotWinProbabilityClass(
                                                                                            matchup,
                                                                                            matchup
                                                                                                .participants[0],
                                                                                        )
                                                                                    "
                                                                                >
                                                                                    {{
                                                                                        slotWinProbability(
                                                                                            matchup,
                                                                                            matchup
                                                                                                .participants[0],
                                                                                        )
                                                                                    }}%
                                                                                    ML
                                                                                </p>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </button>

                                                            <button
                                                                type="button"
                                                                class="w-full rounded-xl border text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                                                :class="[
                                                                    isRoundOf64PlayInCard(
                                                                        matchup,
                                                                    )
                                                                        ? 'px-2 py-1'
                                                                        : 'px-2.5 py-1',
                                                                    resultToneClass(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[1],
                                                                    ),
                                                                    slotIsPlaceholder(
                                                                        matchup
                                                                            .participants[1],
                                                                    )
                                                                        ? 'border-border/60 bg-card/55 text-muted-foreground hover:border-border/80 hover:bg-card/70'
                                                                        : '',
                                                                ]"
                                                                :disabled="
                                                                    isBracketLocked ||
                                                                    !resolveSlotParticipant(
                                                                        matchup
                                                                            .participants[1],
                                                                    )
                                                                "
                                                                @click="
                                                                    selectWinner(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[1],
                                                                    )
                                                                "
                                                            >
                                                                <div
                                                                    class="flex items-center gap-2"
                                                                >
                                                                    <img
                                                                        v-if="
                                                                            participantLogo(
                                                                                matchup
                                                                                    .participants[1],
                                                                            )
                                                                        "
                                                                        :src="
                                                                            participantLogo(
                                                                                matchup
                                                                                    .participants[1],
                                                                            )!
                                                                        "
                                                                        :alt="
                                                                            participantButtonLabel(
                                                                                matchup
                                                                                    .participants[1],
                                                                                'Winner advances here',
                                                                            )
                                                                        "
                                                                        class="size-6 rounded-full bg-background/70 object-contain p-1"
                                                                    />
                                                                    <div
                                                                        class="min-w-0"
                                                                    >
                                                                        <p
                                                                            v-if="
                                                                                !slotIsPlaceholder(
                                                                                    matchup
                                                                                        .participants[1],
                                                                                )
                                                                            "
                                                                            class="text-[10px] tracking-[0.16em] text-muted-foreground uppercase"
                                                                        >
                                                                            {{
                                                                                participantButtonAbbr(
                                                                                    matchup
                                                                                        .participants[1],
                                                                                    'TBD',
                                                                                )
                                                                            }}
                                                                        </p>
                                                                        <div
                                                                            class="mt-0.5 flex items-start gap-1.5"
                                                                        >
                                                                            <span
                                                                                v-if="
                                                                                    participantSeed(
                                                                                        matchup
                                                                                            .participants[1],
                                                                                    )
                                                                                "
                                                                                class="inline-flex min-w-5 justify-center rounded-full border border-border/70 bg-card/80 px-1 py-0.5 text-[9px] leading-none font-semibold text-muted-foreground"
                                                                            >
                                                                                {{
                                                                                    participantSeed(
                                                                                        matchup
                                                                                            .participants[1],
                                                                                    )
                                                                                }}
                                                                            </span>
                                                                            <div
                                                                                class="min-w-0"
                                                                            >
                                                                                <p
                                                                                    class="font-semibold"
                                                                                    :class="[
                                                                                        isRoundOf64PlayInCard(
                                                                                            matchup,
                                                                                        )
                                                                                            ? 'text-[13px] leading-4'
                                                                                            : 'text-sm leading-4',
                                                                                        slotIsPlaceholder(
                                                                                            matchup
                                                                                                .participants[1],
                                                                                        )
                                                                                            ? 'text-muted-foreground'
                                                                                            : 'text-foreground',
                                                                                    ]"
                                                                                >
                                                                                    {{
                                                                                        participantButtonLabel(
                                                                                            matchup
                                                                                                .participants[1],
                                                                                            'Winner advances here',
                                                                                        )
                                                                                    }}
                                                                                </p>
                                                                                <p
                                                                                    v-if="
                                                                                        slotWinProbability(
                                                                                            matchup,
                                                                                            matchup
                                                                                                .participants[1],
                                                                                        ) !==
                                                                                        null
                                                                                    "
                                                                                    class="text-[10px]"
                                                                                    :class="
                                                                                        slotWinProbabilityClass(
                                                                                            matchup,
                                                                                            matchup
                                                                                                .participants[1],
                                                                                        )
                                                                                    "
                                                                                >
                                                                                    {{
                                                                                        slotWinProbability(
                                                                                            matchup,
                                                                                            matchup
                                                                                                .participants[1],
                                                                                        )
                                                                                    }}%
                                                                                    ML
                                                                                </p>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>

                    <article
                        v-if="standaloneRounds.firstFour.length"
                        class="relative overflow-hidden rounded-[2rem] border border-border/70 bg-card/80 p-4 shadow-[0_20px_60px_-32px_rgba(15,23,42,0.18)] backdrop-blur sm:p-6"
                    >
                        <div
                            class="absolute inset-0 bg-gradient-to-br from-foreground/[0.04] via-transparent to-transparent dark:from-foreground/[0.06]"
                        />
                        <div class="relative">
                            <div
                                class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between"
                            >
                                <div>
                                    <p
                                        class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                                    >
                                        Opening Round
                                    </p>
                                    <h3
                                        class="mt-2 text-xl font-semibold text-foreground sm:text-2xl"
                                    >
                                        {{ roundLabels.first_four }}
                                    </h3>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                                <div
                                    v-for="matchup in standaloneRounds.firstFour"
                                    :key="matchup.id"
                                    class="rounded-2xl border border-border/70 bg-card/70 p-3 backdrop-blur"
                                >
                                    <div
                                        class="mb-3 inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] font-semibold tracking-[0.22em] text-foreground/75 uppercase"
                                    >
                                        Winner to
                                        {{ firstFourDestinationLabel(matchup) }}
                                    </div>

                                    <div class="space-y-2">
                                        <button
                                            type="button"
                                            class="w-full rounded-xl border px-3 py-2 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                            :class="
                                                resultToneClass(
                                                    matchup,
                                                    matchup.participants[0],
                                                )
                                            "
                                            :disabled="
                                                isBracketLocked ||
                                                !resolveSlotParticipant(
                                                    matchup.participants[0],
                                                )
                                            "
                                            @click="
                                                selectWinner(
                                                    matchup,
                                                    matchup.participants[0],
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-2.5"
                                            >
                                                <img
                                                    v-if="
                                                        participantLogo(
                                                            matchup
                                                                .participants[0],
                                                        )
                                                    "
                                                    :src="
                                                        participantLogo(
                                                            matchup
                                                                .participants[0],
                                                        )!
                                                    "
                                                    :alt="
                                                        participantButtonLabel(
                                                            matchup
                                                                .participants[0],
                                                            'TBD',
                                                        )
                                                    "
                                                    class="size-8 rounded-full bg-background/70 object-contain p-1"
                                                />
                                                <div class="min-w-0">
                                                    <p
                                                        class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                                    >
                                                        {{
                                                            participantButtonAbbr(
                                                                matchup
                                                                    .participants[0],
                                                                'TBD',
                                                            )
                                                        }}
                                                    </p>
                                                    <div
                                                        class="mt-0.5 flex items-start gap-2"
                                                    >
                                                        <span
                                                            v-if="
                                                                participantSeed(
                                                                    matchup
                                                                        .participants[0],
                                                                )
                                                            "
                                                            class="inline-flex min-w-7 justify-center rounded-full border border-border/70 bg-card/80 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground"
                                                        >
                                                            {{
                                                                participantSeed(
                                                                    matchup
                                                                        .participants[0],
                                                                )
                                                            }}
                                                        </span>
                                                        <div class="min-w-0">
                                                            <p
                                                                class="text-sm leading-5 font-semibold text-foreground"
                                                            >
                                                                {{
                                                                    participantButtonLabel(
                                                                        matchup
                                                                            .participants[0],
                                                                        'TBD',
                                                                    )
                                                                }}
                                                            </p>
                                                            <p
                                                                v-if="
                                                                    slotWinProbability(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[0],
                                                                    ) !== null
                                                                "
                                                                class="text-[11px]"
                                                                :class="
                                                                    slotWinProbabilityClass(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[0],
                                                                    )
                                                                "
                                                            >
                                                                {{
                                                                    slotWinProbability(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[0],
                                                                    )
                                                                }}% ML
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </button>

                                        <button
                                            type="button"
                                            class="w-full rounded-xl border px-3 py-2 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                            :class="
                                                resultToneClass(
                                                    matchup,
                                                    matchup.participants[1],
                                                )
                                            "
                                            :disabled="
                                                isBracketLocked ||
                                                !resolveSlotParticipant(
                                                    matchup.participants[1],
                                                )
                                            "
                                            @click="
                                                selectWinner(
                                                    matchup,
                                                    matchup.participants[1],
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-2.5"
                                            >
                                                <img
                                                    v-if="
                                                        participantLogo(
                                                            matchup
                                                                .participants[1],
                                                        )
                                                    "
                                                    :src="
                                                        participantLogo(
                                                            matchup
                                                                .participants[1],
                                                        )!
                                                    "
                                                    :alt="
                                                        participantButtonLabel(
                                                            matchup
                                                                .participants[1],
                                                            'TBD',
                                                        )
                                                    "
                                                    class="size-8 rounded-full bg-background/70 object-contain p-1"
                                                />
                                                <div class="min-w-0">
                                                    <p
                                                        class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                                                    >
                                                        {{
                                                            participantButtonAbbr(
                                                                matchup
                                                                    .participants[1],
                                                                'TBD',
                                                            )
                                                        }}
                                                    </p>
                                                    <div
                                                        class="mt-0.5 flex items-start gap-2"
                                                    >
                                                        <span
                                                            v-if="
                                                                participantSeed(
                                                                    matchup
                                                                        .participants[1],
                                                                )
                                                            "
                                                            class="inline-flex min-w-7 justify-center rounded-full border border-border/70 bg-card/80 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground"
                                                        >
                                                            {{
                                                                participantSeed(
                                                                    matchup
                                                                        .participants[1],
                                                                )
                                                            }}
                                                        </span>
                                                        <div class="min-w-0">
                                                            <p
                                                                class="text-sm leading-5 font-semibold text-foreground"
                                                            >
                                                                {{
                                                                    participantButtonLabel(
                                                                        matchup
                                                                            .participants[1],
                                                                        'TBD',
                                                                    )
                                                                }}
                                                            </p>
                                                            <p
                                                                v-if="
                                                                    slotWinProbability(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[1],
                                                                    ) !== null
                                                                "
                                                                class="text-[11px]"
                                                                :class="
                                                                    slotWinProbabilityClass(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[1],
                                                                    )
                                                                "
                                                            >
                                                                {{
                                                                    slotWinProbability(
                                                                        matchup,
                                                                        matchup
                                                                            .participants[1],
                                                                    )
                                                                }}% ML
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </button>
                                    </div>

                                    <p
                                        class="mt-3 text-[11px] leading-5 text-muted-foreground"
                                    >
                                        {{ formatVenue(matchup.game) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>

                <div
                    v-else
                    class="rounded-[2rem] border border-dashed border-border/70 bg-card/55 p-8 text-sm leading-6 text-muted-foreground"
                >
                    No NCAA tournament games are currently available for the
                    bracket board. Once the synced CBB games have tournament
                    metadata, this view will populate from the database
                    automatically.
                </div>
            </section>
        </main>

        <Dialog
            :open="resetConfirmOpen"
            @update:open="resetConfirmOpen = $event"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Reset this bracket?</DialogTitle>
                    <DialogDescription>
                        This clears every winner you have selected in the active
                        bracket. Your bracket name and group will stay the same.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2 sm:justify-end">
                    <Button
                        variant="ghost"
                        class="border border-border/70 bg-card/60 text-foreground hover:bg-accent/70"
                        @click="resetConfirmOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="confirmResetBracket">
                        Reset Picks
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
