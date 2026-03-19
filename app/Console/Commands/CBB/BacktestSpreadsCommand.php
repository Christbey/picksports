<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\GeneratePrediction;
use App\Models\CBB\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestSpreadsCommand extends Command
{
    protected $signature = 'cbb:backtest-spreads
        {--season= : Filter by season}
        {--limit=250 : Limit number of most recent graded/final predictions to inspect}
        {--stored : Use stored predicted spreads instead of recomputing with the current model}
        {--detailed : Show biggest market disagreements}';

    protected $description = 'Backtest CBB spread predictions against market spreads and final margins';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No final CBB predictions with spread market data found for the selected scope.');

            return self::SUCCESS;
        }

        $summary = $this->summarize($rows);

        $this->info('CBB Spreads Backtest');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->line('Rows: '.$summary['count']);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Avg model spread', number_format($summary['avg_model_spread'], 1)],
                ['Avg market spread', number_format($summary['avg_market_spread'], 1)],
                ['Avg actual margin', number_format($summary['avg_actual_margin'], 1)],
                ['Model bias vs market', $this->signed($summary['avg_model_spread'] - $summary['avg_market_spread'], 1)],
                ['Model bias vs actual', $this->signed($summary['avg_model_spread'] - $summary['avg_actual_margin'], 1)],
                ['Market MAE', number_format($summary['market_mae'], 2)],
                ['Model MAE', number_format($summary['model_mae'], 2)],
                ['ATS record', "{$summary['wins']}-{$summary['losses']}-{$summary['pushes']}"],
                ['ATS win rate', number_format($summary['win_rate'], 1).'%'],
                ['Home-side bets', "{$summary['home_side_bets']} (".number_format($summary['home_side_rate'], 1)."%)"],
                ['Away-side bets', "{$summary['away_side_bets']} (".number_format($summary['away_side_rate'], 1)."%)"],
            ]
        );

        $this->newLine();
        $this->info('ATS By Edge Bucket');
        $this->table(
            ['Bucket', 'Bets', 'Avg Edge', 'Win Rate'],
            $summary['edge_buckets']
        );

        $this->newLine();
        $this->info('Winner Accuracy By Confidence');
        $this->table(
            ['Bucket', 'Games', 'Winner Accuracy', 'Avg Spread Error'],
            $summary['confidence_buckets']
        );

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Biggest Market Disagreements');
            $this->table(
                ['Game', 'Model', 'Market', 'Actual', 'Pick', 'Result', 'Edge'],
                $summary['biggest_misses']
            );
        }

        return self::SUCCESS;
    }

    private function loadRows(): Collection
    {
        $generator = app(GeneratePrediction::class);

        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('predicted_spread')
            ->whereHas('game', function ($query) {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');

                if ($this->option('season')) {
                    $query->where('season', (int) $this->option('season'));
                }
            })
            ->latest();

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(function (Prediction $prediction) use ($generator): ?array {
                $game = $prediction->game;
                if (! $game) {
                    return null;
                }

                if (! is_numeric($prediction->vegas_spread)) {
                    return null;
                }

                $marketSportsbookSpread = (float) $prediction->vegas_spread;
                $marketSpread = -$marketSportsbookSpread;
                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $modelSpread = $this->option('stored')
                    ? (float) $prediction->predicted_spread
                    : $this->recomputedSpread($generator, $game, $prediction);
                $edge = abs($modelSpread - $marketSpread);
                $pick = $modelSpread > $marketSpread ? 'home' : 'away';
                $coverMargin = $pick === 'home'
                    ? ($actualMargin - $marketSpread)
                    : ((-$actualMargin) + $marketSpread);
                $result = abs($coverMargin) < 0.0001
                    ? 'push'
                    : ($coverMargin > 0 ? 'win' : 'loss');
                $winnerCorrect = ($actualMargin > 0 && $modelSpread > 0)
                    || ($actualMargin < 0 && $modelSpread < 0);

                return [
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'home_team' => $this->teamName($game->homeTeam),
                    'away_team' => $this->teamName($game->awayTeam),
                    'model_spread' => $modelSpread,
                    'market_spread' => $marketSpread,
                    'market_sportsbook_spread' => $marketSportsbookSpread,
                    'actual_margin' => $actualMargin,
                    'pick' => $pick,
                    'result' => $result,
                    'won' => $result === 'win',
                    'push' => $result === 'push',
                    'edge' => $edge,
                    'winner_correct' => $winnerCorrect,
                    'confidence_score' => (float) $prediction->confidence_score,
                    'model_error' => abs($actualMargin - $modelSpread),
                ];
            })
            ->filter()
            ->values();
    }

    private function recomputedSpread(GeneratePrediction $generator, object $game, Prediction $prediction): float
    {
        $simulatedGame = clone $game;
        $simulatedGame->status = 'STATUS_SCHEDULED';

        $preview = $generator->preview($simulatedGame);

        return (float) ($preview['predicted_spread'] ?? $prediction->predicted_spread);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(Collection $rows): array
    {
        $threshold = (float) config('cbb.betting.edge_thresholds.spread');
        $recommended = $rows->filter(fn (array $row) => (float) $row['edge'] >= $threshold)->values();
        $wins = $recommended->where('won', true)->count();
        $pushes = $recommended->where('push', true)->count();
        $losses = $recommended->count() - $wins - $pushes;
        $homeSideBets = $recommended->where('pick', 'home')->count();
        $awaySideBets = $recommended->where('pick', 'away')->count();

        return [
            'count' => $rows->count(),
            'wins' => $wins,
            'losses' => $losses,
            'pushes' => $pushes,
            'win_rate' => ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) * 100 : 0.0,
            'home_side_bets' => $homeSideBets,
            'away_side_bets' => $awaySideBets,
            'home_side_rate' => $recommended->count() > 0 ? ($homeSideBets / $recommended->count()) * 100 : 0.0,
            'away_side_rate' => $recommended->count() > 0 ? ($awaySideBets / $recommended->count()) * 100 : 0.0,
            'avg_model_spread' => (float) $rows->avg('model_spread'),
            'avg_market_spread' => (float) $rows->avg('market_spread'),
            'avg_actual_margin' => (float) $rows->avg('actual_margin'),
            'model_mae' => (float) $rows->avg('model_error'),
            'market_mae' => (float) $rows->map(fn (array $row) => abs($row['actual_margin'] - $row['market_spread']))->avg(),
            'edge_buckets' => $this->edgeBuckets($recommended),
            'confidence_buckets' => $this->confidenceBuckets($rows),
            'biggest_misses' => $recommended
                ->sortByDesc('edge')
                ->take(10)
                ->map(fn (array $row) => [
                    $row['game'],
                    $this->formatSpreadLine((float) $row['model_spread'], (string) $row['home_team'], (string) $row['away_team']),
                    $this->formatSportsbookSpreadLine((float) $row['market_sportsbook_spread'], (string) $row['home_team'], (string) $row['away_team']),
                    number_format((float) $row['actual_margin'], 1),
                    $this->formatBetPick((string) $row['pick'], (float) $row['market_sportsbook_spread'], (string) $row['home_team'], (string) $row['away_team']),
                    strtoupper((string) $row['result']),
                    number_format((float) $row['edge'], 1),
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function edgeBuckets(Collection $rows): array
    {
        $buckets = [
            '2.0-3.9' => fn (float $edge) => $edge >= 2.0 && $edge < 4.0,
            '4.0-5.9' => fn (float $edge) => $edge >= 4.0 && $edge < 6.0,
            '6.0+' => fn (float $edge) => $edge >= 6.0,
        ];

        $table = [];
        foreach ($buckets as $label => $filter) {
            $group = $rows->filter(fn (array $row) => $filter((float) $row['edge']))->values();
            if ($group->isEmpty()) {
                continue;
            }

            $wins = $group->where('won', true)->count();
            $losses = $group->where('push', false)->count() - $wins;
            $winRate = ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) * 100 : 0.0;

            $table[] = [
                $label,
                (string) $group->count(),
                number_format((float) $group->avg('edge'), 1),
                number_format($winRate, 1).'%',
            ];
        }

        return $table;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function confidenceBuckets(Collection $rows): array
    {
        $buckets = [
            '50-59' => fn (float $confidence) => $confidence < 60.0,
            '60-69' => fn (float $confidence) => $confidence >= 60.0 && $confidence < 70.0,
            '70-79' => fn (float $confidence) => $confidence >= 70.0 && $confidence < 80.0,
            '80+' => fn (float $confidence) => $confidence >= 80.0,
        ];

        $table = [];
        foreach ($buckets as $label => $filter) {
            $group = $rows->filter(fn (array $row) => $filter((float) $row['confidence_score']))->values();
            if ($group->isEmpty()) {
                continue;
            }

            $table[] = [
                $label,
                (string) $group->count(),
                number_format(((float) $group->where('winner_correct', true)->count() / max(1, $group->count())) * 100, 1).'%',
                number_format((float) $group->avg('model_error'), 2),
            ];
        }

        return $table;
    }

    private function teamName(mixed $team): string
    {
        return (string) ($team->abbreviation ?? $team->school ?? 'UNK');
    }

    private function signed(float $value, int $precision = 1): string
    {
        $formatted = number_format($value, $precision);

        return $value > 0 ? "+{$formatted}" : $formatted;
    }

    private function formatSpreadLine(float $spread, string $homeTeam, string $awayTeam): string
    {
        if ($spread > 0) {
            return "{$homeTeam} -".number_format($spread, 1);
        }

        if ($spread < 0) {
            return "{$awayTeam} -".number_format(abs($spread), 1);
        }

        return 'PK';
    }

    private function formatSportsbookSpreadLine(float $homeSpread, string $homeTeam, string $awayTeam): string
    {
        if ($homeSpread < 0) {
            return "{$homeTeam} ".number_format($homeSpread, 1);
        }

        if ($homeSpread > 0) {
            return "{$awayTeam} -".number_format($homeSpread, 1);
        }

        return 'PK';
    }

    private function formatBetPick(string $pick, float $homeSpread, string $homeTeam, string $awayTeam): string
    {
        if ($pick === 'home') {
            if ($homeSpread < 0) {
                return "Bet {$homeTeam} ".number_format($homeSpread, 1);
            }

            if ($homeSpread > 0) {
                return "Bet {$homeTeam} +".number_format($homeSpread, 1);
            }
        }

        if ($pick === 'away') {
            if ($homeSpread < 0) {
                return "Bet {$awayTeam} +".number_format(abs($homeSpread), 1);
            }

            if ($homeSpread > 0) {
                return "Bet {$awayTeam} -".number_format($homeSpread, 1);
            }
        }

        return 'Bet PK';
    }
}
