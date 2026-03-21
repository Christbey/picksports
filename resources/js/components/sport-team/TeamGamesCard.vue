<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { Game } from '@/types';

const props = defineProps<{
    title: string;
    games: Game[];
    teamId?: number;
    gameLink: (id: number) => any;
    getGameResult?: (game: Game) => string | null;
    getOpponent: (game: Game, isHome: boolean) => any;
    formatDate: (date: string | null) => string;
    showScore?: boolean;
}>();

type TeamGameStats = Record<string, unknown> & {
    team_id?: number;
    team_type?: string;
};

const getTeamStatsForGame = (game: Game): TeamGameStats | null => {
    if (
        !props.teamId ||
        !Array.isArray(game.team_stats) ||
        game.team_stats.length === 0
    )
        return null;

    const statsRows = game.team_stats as TeamGameStats[];
    const byTeamId = statsRows.find(
        (s) => Number(s.team_id) === Number(props.teamId),
    );
    if (byTeamId) return byTeamId;

    const isHome = game.home_team_id === props.teamId;
    const fallback = statsRows.find(
        (s) => s.team_type === (isHome ? 'home' : 'away'),
    );
    return fallback ?? null;
};

const toNum = (value: unknown): number | null => {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    }
    return null;
};

const statChipsForGame = (game: Game): string[] => {
    const stats = getTeamStatsForGame(game);
    if (!stats) return [];

    // Baseball profile
    if ('runs' in stats || 'hits' in stats || 'errors' in stats) {
        const runs = toNum(stats.runs);
        const hits = toNum(stats.hits);
        const errors = toNum(stats.errors);
        const chips: string[] = [];
        if (runs !== null || hits !== null || errors !== null) {
            chips.push(
                `R/H/E ${runs ?? '-'} / ${hits ?? '-'} / ${errors ?? '-'}`,
            );
        }
        const lob = toNum(stats.left_on_base);
        if (lob !== null) chips.push(`LOB ${lob}`);
        return chips;
    }

    // Football profile
    if (
        'total_yards' in stats ||
        'passing_yards' in stats ||
        'rushing_yards' in stats
    ) {
        const chips: string[] = [];
        const totalYards = toNum(stats.total_yards);
        const passYards = toNum(stats.passing_yards);
        const rushYards = toNum(stats.rushing_yards);
        const turnovers = toNum(stats.turnovers);
        if (totalYards !== null) chips.push(`YDS ${totalYards}`);
        if (passYards !== null) chips.push(`PASS ${passYards}`);
        if (rushYards !== null) chips.push(`RUSH ${rushYards}`);
        if (turnovers !== null) chips.push(`TO ${turnovers}`);
        return chips;
    }

    // Basketball profile
    if ('rebounds' in stats || 'assists' in stats || 'turnovers' in stats) {
        const chips: string[] = [];
        const rebounds = toNum(stats.rebounds);
        const assists = toNum(stats.assists);
        const turnovers = toNum(stats.turnovers);
        if (rebounds !== null) chips.push(`REB ${rebounds}`);
        if (assists !== null) chips.push(`AST ${assists}`);
        if (turnovers !== null) chips.push(`TO ${turnovers}`);
        return chips;
    }

    return [];
};
</script>

<template>
    <Card v-if="games.length > 0">
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="space-y-2">
                <Link
                    v-for="game in games"
                    :key="game.id"
                    :href="gameLink(game.id)"
                    class="flex items-center justify-between rounded-lg p-3 transition-colors hover:bg-muted/50"
                >
                    <div class="flex flex-1 items-center gap-3">
                        <span
                            v-if="showScore && getGameResult"
                            class="w-6 text-sm font-bold"
                            :class="
                                getGameResult(game) === 'W'
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-red-600 dark:text-red-400'
                            "
                        >
                            {{ getGameResult(game) }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{ game.home_team_id === teamId ? 'vs' : '@' }}
                        </span>
                        <span class="font-medium">
                            {{
                                getOpponent(game, game.home_team_id === teamId)
                                    ?.name
                            }}
                        </span>
                        <div
                            v-if="statChipsForGame(game).length > 0"
                            class="flex flex-wrap items-center gap-1"
                        >
                            <span
                                v-for="chip in statChipsForGame(game)"
                                :key="`${game.id}-${chip}`"
                                class="rounded bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                            >
                                {{ chip }}
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <span v-if="showScore" class="text-sm font-medium">
                            {{
                                game.home_team_id === teamId
                                    ? game.home_score
                                    : game.away_score
                            }}
                            -
                            {{
                                game.home_team_id === teamId
                                    ? game.away_score
                                    : game.home_score
                            }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{ formatDate(game.game_date) }}
                        </span>
                    </div>
                </Link>
            </div>
        </CardContent>
    </Card>
</template>
