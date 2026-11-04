import { watch } from 'vue';
import { trackViewItem } from '@/lib/analytics';

type TrackedTeam =
    | { abbreviation?: string | null; name?: string | null }
    | null
    | undefined;
type GameViewOptions = {
    gameId: () => number;
    sport: () => string;
    awayTeam: () => TrackedTeam;
    homeTeam: () => TrackedTeam;
};

export function useGameViewTracking(options: GameViewOptions): void {
    watch(
        () => [
            options.gameId(),
            options.awayTeam()?.abbreviation ??
                options.awayTeam()?.name ??
                null,
            options.homeTeam()?.abbreviation ??
                options.homeTeam()?.name ??
                null,
        ],
        () => {
            const awayLabel =
                options.awayTeam()?.abbreviation ??
                options.awayTeam()?.name ??
                'Away';
            const homeLabel =
                options.homeTeam()?.abbreviation ??
                options.homeTeam()?.name ??
                'Home';
            trackViewItem({
                itemId: options.gameId(),
                itemName: `${awayLabel} @ ${homeLabel}`,
                sport: options.sport(),
                homeTeam: homeLabel,
                awayTeam: awayLabel,
            });
        },
        { immediate: true },
    );
}
