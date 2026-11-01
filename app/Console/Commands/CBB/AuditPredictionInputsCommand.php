<?php

namespace App\Console\Commands\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\PlayerInjury;
use App\Models\CBB\TeamMetric;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\Basketball\BasketballBacktestForecastSource;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AuditPredictionInputsCommand extends Command
{
    protected $signature = 'cbb:audit-prediction-inputs
        {--forecast-source= : canonical or legacy (defaults to the active CBB source)}
        {--season= : Limit to a season}
        {--date= : Limit to a game date (YYYY-MM-DD)}
        {--game= : Audit a single game ID}
        {--days=7 : Number of upcoming days to audit when no specific game/date is provided}
        {--stale-odds-hours=8 : Mark odds as stale when older than this many hours}
        {--recent-finals=25 : Number of recent final games to sample for play completeness}
        {--include-placeholders : Include placeholder tournament slots in the game audit table}
        {--include-historical : Include canceled, postponed, and older historical scheduled games}
        {--show-ok : Include clean rows in the game audit table}';

    protected $description = 'Audit CBB prediction inputs for placeholders, odds, metrics, injuries, and play completeness';

    public function handle(): int
    {
        try {
            $this->forecastSource = app(BasketballBacktestForecastSource::class)->name('cbb', $this->option('forecast-source'));
        } catch (\InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $games = $this->gamesToAudit();
        $staleOddsHours = max(1, (int) $this->option('stale-odds-hours'));
        $includePlaceholders = (bool) $this->option('include-placeholders');
        $showOk = (bool) $this->option('show-ok');

        $this->info('CBB prediction input audit');
        if ($this->forecastSource === 'canonical') {
            $this->line('Forecast source: canonical availability; this audit grants no betting approval.');
        }
        $this->newLine();

        $this->renderGlobalSummary((int) $this->option('recent-finals'));
        $this->newLine();

        if ($games->isEmpty()) {
            $this->warn('No games matched the selected filters.');

            return self::SUCCESS;
        }

        $available = $this->forecastSource === 'canonical' ? $this->canonicalGameIds($games) : collect();
        $metrics = TeamMetric::query()->whereIn('season', $games->pluck('season')->unique())
            ->whereIn('team_id', $games->flatMap(fn ($game) => [$game->home_team_id, $game->away_team_id])->filter()->unique())
            ->selectRaw('season, team_id, COUNT(*) as metric_count')->groupBy('season', 'team_id')->get()
            ->keyBy(fn ($metric) => $metric->season.':'.$metric->team_id);
        $rows = $games
            ->map(function (Game $game) use ($staleOddsHours, $available, $metrics): array {
                $count = collect([$game->home_team_id, $game->away_team_id])->filter()->unique()
                    ->sum(fn ($id) => (int) ($metrics->get($game->season.':'.$id)?->metric_count ?? 0));

                return $this->auditGame($game, $staleOddsHours, $count, $this->forecastSource === 'canonical' ? $available->has($game->id) : $game->prediction !== null);
            })
            ->reject(fn (array $row) => ! $includePlaceholders && $row['is_placeholder'])
            ->values();

        $issueRows = $rows->filter(fn (array $row) => $row['issues_count'] > 0)->values();
        $displayRows = $showOk ? $rows : $issueRows;

        if ($displayRows->isEmpty()) {
            $this->info('No game-level input issues detected for the selected games.');

            return self::SUCCESS;
        }

        $this->table(
            ['Game ID', 'Matchup', 'Status', 'Issues', 'Flags'],
            $displayRows->map(fn (array $row) => [
                $row['game_id'],
                $row['matchup'],
                $row['status'],
                $row['issues_count'],
                implode(', ', $row['flags']),
            ])
        );

        $flagCounts = $issueRows
            ->flatMap(fn (array $row) => $row['flags'])
            ->countBy()
            ->sortDesc();

        if ($flagCounts->isNotEmpty()) {
            $this->newLine();
            $this->info('Issue counts');
            $this->table(
                ['Flag', 'Count'],
                $flagCounts->map(fn ($count, $flag) => [$flag, $count])->values()->all()
            );
        }

        return self::SUCCESS;
    }

    private string $forecastSource = 'legacy';

    private function canonicalGameIds(Collection $games): Collection
    {
        $ids = collect();
        foreach ($games->pluck('id')->chunk(250) as $batch) {
            $matches = app(CanonicalSportPredictionQuery::class)->queryForSport('cbb')
                ->whereIn('cbb_games.id', $batch)->where('predictions.generated_at', '<=', now())
                ->where('predictions.published_at', '<=', now())->reorder()->select('cbb_games.id')->pluck('id');
            foreach ($matches as $id) {
                $ids->put((int) $id, true);
            }
        }

        return $ids;
    }

    /**
     * @return Collection<int, Game>
     */
    private function gamesToAudit(): Collection
    {
        $query = Game::query()
            ->with(['homeTeam', 'awayTeam'])->when($this->forecastSource === 'legacy', fn ($query) => $query->with('prediction'))
            ->orderBy('game_date')
            ->orderBy('id');

        if ($gameId = $this->option('game')) {
            $query->whereKey($gameId);
        } else {
            if ($season = $this->option('season')) {
                $query->where('season', $season);
            }

            if ($date = $this->option('date')) {
                $query->whereDate('game_date', $date);
            } elseif (! $this->option('include-historical')) {
                $days = max(1, (int) $this->option('days'));
                $today = now()->startOfDay();
                $through = now()->copy()->startOfDay()->addDays($days);

                $query
                    ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME'])
                    ->whereDate('game_date', '>=', $today->toDateString())
                    ->whereDate('game_date', '<=', $through->toDateString());
            } else {
                $query->where('status', '!=', 'STATUS_FINAL');
            }
        }

        return $query->get();
    }

    /**
     * @return array{game_id:int,matchup:string,status:string,issues_count:int,flags:array<int,string>,is_placeholder:bool}
     */
    private function auditGame(Game $game, int $staleOddsHours, int $metricCount, bool $hasPrediction): array
    {
        $flags = [];
        $isPlaceholder = $this->isPlaceholderGame($game);

        if ($isPlaceholder) {
            $flags[] = 'placeholder_team';
        }

        $oddsData = $game->odds_data;
        if (! is_array($oddsData) || empty($oddsData['bookmakers'])) {
            $flags[] = 'no_odds';
        } else {
            $marketKeys = collect($oddsData['bookmakers'])
                ->flatMap(fn ($bookmaker) => is_array($bookmaker) ? ($bookmaker['markets'] ?? []) : [])
                ->map(fn ($market) => is_array($market) ? ($market['key'] ?? null) : null)
                ->filter()
                ->unique()
                ->values();

            foreach (['spreads', 'totals', 'h2h'] as $requiredMarket) {
                if (! $marketKeys->contains($requiredMarket)) {
                    $flags[] = "missing_{$requiredMarket}";
                }
            }
        }

        if ($game->odds_updated_at === null) {
            $flags[] = 'odds_not_timestamped';
        } elseif ($game->odds_updated_at->lt(now()->subHours($staleOddsHours))) {
            $flags[] = 'odds_stale';
        }

        if ($metricCount < 2) {
            $flags[] = 'missing_team_metrics';
        }

        if (! $hasPrediction) {
            $flags[] = 'missing_prediction_row';
        }

        return [
            'game_id' => (int) $game->id,
            'matchup' => $this->matchupLabel($game),
            'status' => (string) $game->status,
            'issues_count' => count($flags),
            'flags' => $flags,
            'is_placeholder' => $isPlaceholder,
        ];
    }

    private function renderGlobalSummary(int $recentFinals): void
    {
        $activeInjuries = PlayerInjury::query()->where('is_active', true)->count();
        $totalInjuries = PlayerInjury::query()->count();
        $sampleFinalGames = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->latest('game_date')
            ->limit(max(1, $recentFinals))
            ->get(['id', 'home_score', 'away_score']);

        $lastPlays = Play::query()->whereIn('game_id', $sampleFinalGames->pluck('id'))
            ->where('id', function ($query): void {
                $query->from('cbb_plays as latest')->select('latest.id')->whereColumn('latest.game_id', 'cbb_plays.game_id')
                    ->orderByDesc('latest.sequence_number')->limit(1);
            })->get(['game_id', 'home_score', 'away_score'])->keyBy('game_id');
        $completePlayGames = $sampleFinalGames->filter(function (Game $game) use ($lastPlays) {
            $lastPlay = $lastPlays->get($game->id);

            if (! $lastPlay) {
                return false;
            }

            return (int) ($lastPlay->home_score ?? -1) === (int) $game->home_score
                && (int) ($lastPlay->away_score ?? -1) === (int) $game->away_score;
        })->count();

        $this->table(
            ['Check', 'Value', 'Status'],
            [
                ['cbb_player_injuries rows', $totalInjuries, $totalInjuries > 0 ? 'ok' : 'warning'],
                ['active injuries', $activeInjuries, $activeInjuries > 0 ? 'ok' : 'warning'],
                [
                    'recent finals with complete final play score',
                    sprintf('%d / %d', $completePlayGames, $sampleFinalGames->count()),
                    $sampleFinalGames->isNotEmpty() && $completePlayGames === $sampleFinalGames->count() ? 'ok' : 'warning',
                ],
            ]
        );
    }

    private function matchupLabel(Game $game): string
    {
        $away = $game->awayTeam?->abbreviation ?? $game->away_team_display_name ?? 'AWAY';
        $home = $game->homeTeam?->abbreviation ?? $game->home_team_display_name ?? 'HOME';

        return "{$away} @ {$home}";
    }

    private function isPlaceholderGame(Game $game): bool
    {
        return $this->isPlaceholderTeam($game->homeTeam)
            || $this->isPlaceholderTeam($game->awayTeam)
            || str_starts_with((string) ($game->espn_event_id ?? ''), 'placeholder:');
    }

    private function isPlaceholderTeam(?object $team): bool
    {
        if (! $team) {
            return true;
        }

        $school = strtoupper(trim((string) ($team->school ?? '')));
        $abbreviation = strtoupper(trim((string) ($team->abbreviation ?? '')));
        $espnId = (int) ($team->espn_id ?? 0);

        return in_array($school, ['TBD', 'TBD2'], true)
            || in_array($abbreviation, ['TBD', 'TBD2', 'WFF', 'FF'], true)
            || $espnId < 0;
    }
}
