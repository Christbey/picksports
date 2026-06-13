<?php

namespace App\Console\Commands\Sports;

use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Support\SportCatalog;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyzePlayerPropsCommand extends Command
{
    protected $signature = 'sports:analyze-player-props
        {--sport= : Sport to analyze: mlb, nba, nfl, cbb, wnba}
        {--season= : Optional season filter}
        {--min-games=3 : Minimum player game sample}
        {--window-days= : Active game window length}
        {--only-missing : Only analyze active games with props but without recommendation-ready outputs}';

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
        $gameIds = $context->marketReadyGameIds;

        if ((bool) $this->option('only-missing')) {
            $originalGameCount = count($gameIds);
            $gameIds = $this->filterGamesMissingRecommendationOutputs($sport, $gameIds);

            $this->info(sprintf(
                'Only-missing mode: %d/%d active game(s) need recommendation analysis.',
                count($gameIds),
                $originalGameCount,
            ));
        }

        $this->info(sprintf(
            'Analyzing %s player props for %d active game(s) in %s stage.',
            strtoupper($sport),
            count($gameIds),
            $context->stage,
        ));

        foreach ($gameIds as $gameId) {
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

    /**
     * @param  array<int, int>  $gameIds
     * @return array<int, int>
     */
    private function filterGamesMissingRecommendationOutputs(string $sport, array $gameIds): array
    {
        $propsTable = config("validation.sports.{$sport}.tables.player_props");

        if (! is_string($propsTable) || ! Schema::hasTable($propsTable)) {
            return $gameIds;
        }

        return collect($gameIds)
            ->filter(fn (int $gameId): bool => $this->hasRawProps($propsTable, $gameId) && ! $this->hasRecommendationReadyProps($propsTable, $gameId))
            ->values()
            ->all();
    }

    private function hasRawProps(string $propsTable, int $gameId): bool
    {
        return DB::table($propsTable)->where('game_id', $gameId)->exists();
    }

    private function hasRecommendationReadyProps(string $propsTable, int $gameId): bool
    {
        $requiredColumns = [
            'recommended_side',
            'confidence_score',
            'predicted_over_probability',
            'market_over_probability',
            'edge_probability',
            'data_quality_score',
        ];

        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn($propsTable, $column)) {
                return false;
            }
        }

        return DB::table($propsTable)
            ->where('game_id', $gameId)
            ->whereNotNull('recommended_side')
            ->whereNotNull('confidence_score')
            ->whereNotNull('predicted_over_probability')
            ->whereNotNull('market_over_probability')
            ->whereNotNull('edge_probability')
            ->whereNotNull('data_quality_score')
            ->exists();
    }
}
