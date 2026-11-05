export type Team = {
    id: number | null;
    name: string;
    abbreviation: string | null;
    logo?: string | null;
};

export type TournamentGame = {
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

export type TournamentRound = {
    key: string;
    label: string;
    games: TournamentGame[];
};

export type TournamentRegion = {
    id: string;
    name: string;
    rounds: TournamentRound[];
};

export type BracketParticipant = {
    id: string;
    name: string;
    abbreviation: string | null;
    logo?: string | null;
    seed?: number | null;
};

export type BracketSlot = {
    participant: BracketParticipant | null;
    sourceMatchupId?: string;
    placeholderLabel?: string;
    placeholderAbbreviation?: string;
};

export type BracketMatchup = {
    id: string;
    roundKey: string;
    label: string;
    game: TournamentGame | null;
    participants: [BracketSlot, BracketSlot];
};

export type BracketRegion = {
    id: string;
    name: string;
    rounds: {
        key: string;
        label: string;
        matchups: BracketMatchup[];
    }[];
};

export type BracketResult = {
    status: 'correct' | 'incorrect' | 'pending' | 'unpicked';
    round_key: string;
    points: number;
    possible_points: number;
    picked_id: string | null;
    winning_id: string | null;
};

export type SavedBracket = {
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

export type Group = {
    id: number;
    public_id: string;
    name: string;
    type: string;
    sport: string | null;
    season: number | null;
    owner_id: number | null;
};

export type LeaderboardEntry = {
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

export type ApiResource<T> = {
    data: T;
};

export type ApiMutationError = {
    data?: {
        lock_at?: unknown;
        message?: unknown;
    } | null;
    status?: number;
};
