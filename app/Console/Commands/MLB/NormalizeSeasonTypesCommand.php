<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Game;
use App\Support\MlbSeasonTypeResolver;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;

class NormalizeSeasonTypesCommand extends Command
{
    protected $signature = 'mlb:normalize-season-types
        {--season= : Season to normalize}
        {--dry-run : Preview changes without writing}';

    protected $description = 'Normalize MLB season_type values using season context and opener inference';

    public function handle(): int
    {
        $season = $this->option('season');
        $season = is_numeric($season) ? (int) $season : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = Game::query()
            ->when($season !== null, fn ($builder) => $builder->where('season', $season))
            ->orderBy('id');

        $checked = 0;
        $updated = 0;

        $query->chunkById(200, function ($games) use (&$checked, &$updated, $dryRun): void {
            foreach ($games as $game) {
                $checked++;

                $normalizedSeasonType = MlbSeasonTypeResolver::normalize(
                    seasonType: $game->season_type,
                    week: is_numeric($game->week) ? (int) $game->week : null,
                    gameDate: $game->game_date,
                    season: is_numeric($game->season) ? (int) $game->season : null,
                );

                if ((string) $game->season_type === $normalizedSeasonType) {
                    continue;
                }

                $updated++;

                if (! $dryRun) {
                    $game->forceFill(['season_type' => $normalizedSeasonType])->save();
                }
            }
        });

        if (! $dryRun && $updated > 0) {
            $sportsViewCache = app(SportsViewCache::class);
            foreach ([
                SportsViewCache::SEGMENT_TEAM_TRENDS,
                SportsViewCache::SEGMENT_TEAM_METRICS_INDEX,
                SportsViewCache::SEGMENT_TEAM_METRICS_BY_TEAM,
            ] as $segment) {
                $sportsViewCache->bustSegment($segment);
            }
        }

        $this->info(sprintf(
            '%s %d of %d MLB game season types.',
            $dryRun ? 'Would normalize' : 'Normalized',
            $updated,
            $checked
        ));

        return self::SUCCESS;
    }
}
