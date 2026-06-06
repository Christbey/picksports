<?php

namespace App\Console\Commands\Sports;

use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Support\SportCatalog;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;

class AnalyzePlayerPropsCommand extends Command
{
    protected $signature = 'sports:analyze-player-props
        {--sport= : Sport to analyze: mlb, nba, nfl, cbb, wnba}
        {--season= : Optional season filter}
        {--min-games=3 : Minimum player game sample}
        {--window-days= : Active game window length}';

    protected $description = 'Analyze active player props and persist recommendation fields for validation and API payloads';

    /**
     * @var array<string, string>
     */
    private array $sportLabels = [
        'mlb' => 'MLB',
        'nba' => 'NBA',
        'nfl' => 'NFL',
        'cbb' => 'CBB',
        'wnba' => 'WNBA',
    ];

    public function handle(PlayerPropAnalyzer $analyzer, SeasonStageService $seasonStageService): int
    {
        $sport = strtolower((string) $this->option('sport'));

        if (! isset($this->sportLabels[$sport]) || ! in_array($sport, SportCatalog::PLAYER_PROPS, true)) {
            $this->error('Unsupported player prop sport: '.$sport);

            return self::FAILURE;
        }

        $season = $this->option('season');
        $windowDays = $this->option('window-days');
        $context = $seasonStageService->context(
            $sport,
            $season !== null && $season !== '' ? (int) $season : null,
            null,
            $windowDays !== null && $windowDays !== '' ? (int) $windowDays : null,
        );
        $minGames = max(1, (int) $this->option('min-games'));
        $totalRecommendations = 0;

        $this->info(sprintf(
            'Analyzing %s player props for %d active game(s) in %s stage.',
            strtoupper($sport),
            count($context->activeGameIds),
            $context->stage,
        ));

        foreach ($context->activeGameIds as $gameId) {
            $recommendations = $analyzer->analyzeProps($this->sportLabels[$sport], $minGames, gameFilter: $gameId);
            $totalRecommendations += $recommendations->count();
            $this->line("Game {$gameId}: {$recommendations->count()} recommendation(s).");
        }

        if ($totalRecommendations > 0) {
            app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
        }

        $this->info("Player prop analysis complete. {$totalRecommendations} recommendation(s) persisted.");

        return self::SUCCESS;
    }
}
