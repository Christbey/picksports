<?php

namespace App\Console\Commands\WNBA;

use App\Models\WNBA\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestSpreadsCommand extends Command
{
    private int $missingSpreadLine = 0;

    protected $signature = 'wnba:backtest-spreads
        {--season= : Filter by season}
        {--limit=500 : Limit number of most recent graded/final predictions to inspect}
        {--json : Output the report as JSON}
        {--detailed : Show biggest market disagreements}';

    protected $description = 'Backtest WNBA spread predictions against stored market spreads and final margins';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No final WNBA predictions with spread market data found for the selected scope.');

            return self::SUCCESS;
        }

        $summary = $this->summarize($rows);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('WNBA Spreads Backtest');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->line('Rows with spread line: '.$summary['count']);
        $this->line('Rows missing spread line: '.$summary['missing_spread_line']);
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
                ['All threshold ATS', "{$summary['threshold_record']['wins']}-{$summary['threshold_record']['losses']}-{$summary['threshold_record']['pushes']}"],
                ['All threshold ATS rate', number_format($summary['threshold_record']['win_rate'], 1).'%'],
                ['Gate-qualified ATS', "{$summary['gate_record']['wins']}-{$summary['gate_record']['losses']}-{$summary['gate_record']['pushes']}"],
                ['Gate-qualified ATS rate', number_format($summary['gate_record']['win_rate'], 1).'%'],
            ]
        );

        $this->newLine();
        $this->info('ATS By Edge Bucket');
        $this->table(
            ['Bucket', 'Bets', 'Avg Edge', 'Win Rate'],
            $summary['edge_buckets']
        );

        $this->newLine();
        $this->info('ATS By Pick Type');
        $this->table(
            ['Type', 'Bets', 'Record', 'Win Rate'],
            $summary['pick_type_buckets']
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
                ['Game', 'Model', 'Market', 'Actual', 'Pick', 'Type', 'Result', 'Edge'],
                $summary['biggest_disagreements']
            );
        }

        return self::SUCCESS;
    }

    private function loadRows(): Collection
    {
        $this->missingSpreadLine = 0;

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
            ->map(function (Prediction $prediction): ?array {
                $game = $prediction->game;
                if (! $game) {
                    return null;
                }

                $homeSpread = $this->homeSpreadLine((array) $game->odds_data, $game->homeTeam);
                if ($homeSpread === null) {
                    $this->missingSpreadLine++;

                    return null;
                }

                $marketSpread = -$homeSpread;
                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $modelSpread = (float) $prediction->predicted_spread;
                $edge = abs($modelSpread - $marketSpread);
                $pick = $modelSpread > $marketSpread ? 'home' : 'away';
                $coverMargin = $pick === 'home'
                    ? ($actualMargin - $marketSpread)
                    : ((-$actualMargin) + $marketSpread);
                $result = abs($coverMargin) < 0.0001
                    ? 'push'
                    : ($coverMargin > 0 ? 'win' : 'loss');
                $isFavorite = $pick === 'home' ? $homeSpread < 0 : $homeSpread > 0;

                return [
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'home_team' => $this->teamName($game->homeTeam),
                    'away_team' => $this->teamName($game->awayTeam),
                    'model_spread' => $modelSpread,
                    'market_spread' => $marketSpread,
                    'market_home_line' => $homeSpread,
                    'actual_margin' => $actualMargin,
                    'pick' => $pick,
                    'pick_type' => $isFavorite ? 'favorite' : 'underdog',
                    'result' => $result,
                    'won' => $result === 'win',
                    'push' => $result === 'push',
                    'edge' => $edge,
                    'passes_gate' => $this->passesSpreadGate($edge, $isFavorite, (float) $prediction->confidence_score),
                    'winner_correct' => (bool) $prediction->winner_correct,
                    'confidence_score' => (float) $prediction->confidence_score,
                    'model_error' => abs($actualMargin - $modelSpread),
                    'market_error' => abs($actualMargin - $marketSpread),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(Collection $rows): array
    {
        $threshold = (float) config('wnba.betting.edge_thresholds.spread', 2.0);
        $thresholdRows = $rows->filter(fn (array $row) => (float) $row['edge'] >= $threshold)->values();
        $gateRows = $rows->filter(fn (array $row) => (bool) $row['passes_gate'])->values();

        return [
            'count' => $rows->count(),
            'missing_spread_line' => $this->missingSpreadLine,
            'avg_model_spread' => round((float) $rows->avg('model_spread'), 2),
            'avg_market_spread' => round((float) $rows->avg('market_spread'), 2),
            'avg_actual_margin' => round((float) $rows->avg('actual_margin'), 2),
            'model_mae' => round((float) $rows->avg('model_error'), 2),
            'market_mae' => round((float) $rows->avg('market_error'), 2),
            'threshold_record' => $this->record($thresholdRows),
            'gate_record' => $this->record($gateRows),
            'edge_buckets' => $this->edgeBuckets($thresholdRows),
            'pick_type_buckets' => $this->pickTypeBuckets($thresholdRows),
            'confidence_buckets' => $this->confidenceBuckets($rows),
            'biggest_disagreements' => $thresholdRows
                ->sortByDesc('edge')
                ->take(10)
                ->map(fn (array $row) => [
                    $row['game'],
                    $this->formatSpreadLine((float) $row['model_spread'], (string) $row['home_team'], (string) $row['away_team']),
                    $this->formatSportsbookSpreadLine((float) $row['market_home_line'], (string) $row['home_team'], (string) $row['away_team']),
                    number_format((float) $row['actual_margin'], 1),
                    $this->formatBetPick((string) $row['pick'], (float) $row['market_home_line'], (string) $row['home_team'], (string) $row['away_team']),
                    $row['pick_type'],
                    strtoupper((string) $row['result']),
                    number_format((float) $row['edge'], 1),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{bets:int,wins:int,losses:int,pushes:int,win_rate:float}
     */
    private function record(Collection $rows): array
    {
        $wins = $rows->where('won', true)->count();
        $pushes = $rows->where('push', true)->count();
        $losses = $rows->count() - $wins - $pushes;

        return [
            'bets' => $rows->count(),
            'wins' => $wins,
            'losses' => $losses,
            'pushes' => $pushes,
            'win_rate' => ($wins + $losses) > 0 ? round(($wins / ($wins + $losses)) * 100, 1) : 0.0,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function edgeBuckets(Collection $rows): array
    {
        $buckets = [
            '<3.0' => fn (float $edge) => $edge < 3.0,
            '3.0-4.9' => fn (float $edge) => $edge >= 3.0 && $edge < 5.0,
            '5.0+' => fn (float $edge) => $edge >= 5.0,
        ];

        return $this->bucketRows($rows, $buckets, fn (Collection $group, string $label): array => [
            $label,
            (string) $group->count(),
            number_format((float) $group->avg('edge'), 1),
            number_format($this->record($group)['win_rate'], 1).'%',
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function pickTypeBuckets(Collection $rows): array
    {
        $buckets = [
            'favorite' => fn (string $type) => $type === 'favorite',
            'underdog' => fn (string $type) => $type === 'underdog',
        ];

        return $this->bucketRows($rows, $buckets, function (Collection $group, string $label): array {
            $record = $this->record($group);

            return [
                $label,
                (string) $group->count(),
                "{$record['wins']}-{$record['losses']}-{$record['pushes']}",
                number_format($record['win_rate'], 1).'%',
            ];
        }, 'pick_type');
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

        return $this->bucketRows($rows, $buckets, fn (Collection $group, string $label): array => [
            $label,
            (string) $group->count(),
            number_format(((float) $group->where('winner_correct', true)->count() / max(1, $group->count())) * 100, 1).'%',
            number_format((float) $group->avg('model_error'), 2),
        ], 'confidence_score');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, callable>  $buckets
     * @return array<int, array<int, string>>
     */
    private function bucketRows(Collection $rows, array $buckets, callable $mapper, string $field = 'edge'): array
    {
        $table = [];
        foreach ($buckets as $label => $filter) {
            $group = $rows->filter(fn (array $row): bool => $filter($row[$field]))->values();
            if ($group->isEmpty()) {
                continue;
            }

            $table[] = $mapper($group, $label);
        }

        return $table;
    }

    private function passesSpreadGate(float $edge, bool $isFavorite, float $winnerConfidence): bool
    {
        if (! (bool) config('wnba.betting.spread_gate.enabled', true)) {
            return $edge >= (float) config('wnba.betting.edge_thresholds.spread', 2.0);
        }

        if ($isFavorite && $winnerConfidence >= (float) config('wnba.betting.spread_gate.block_favorite_confidence', 80.0)) {
            return false;
        }

        $validatedMin = (float) config('wnba.betting.spread_gate.validated_min_edge', 3.0);
        $validatedMax = (float) config('wnba.betting.spread_gate.validated_max_edge', 5.0);
        $underdogMin = (float) config('wnba.betting.spread_gate.underdog_min_edge', 2.5);
        $underdogMax = (float) config('wnba.betting.spread_gate.underdog_max_edge', 5.0);

        return ($edge >= $validatedMin && $edge < $validatedMax)
            || (! $isFavorite && $edge >= $underdogMin && $edge < $underdogMax);
    }

    private function homeSpreadLine(array $oddsData, mixed $homeTeam): ?float
    {
        $markets = [];
        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) === 'spreads') {
                    $markets[] = [
                        'bookmaker' => $bookmaker['key'] ?? $bookmaker['title'] ?? null,
                        'market' => $market,
                    ];
                }
            }
        }

        $preferred = ['pinnacle', 'draftkings', 'fanduel', 'betmgm', 'caesars', 'espnbet', 'betrivers', 'betonlineag'];
        usort($markets, function (array $left, array $right) use ($preferred): int {
            $leftIndex = array_search($left['bookmaker'], $preferred, true);
            $rightIndex = array_search($right['bookmaker'], $preferred, true);

            return ($leftIndex === false ? 999 : $leftIndex) <=> ($rightIndex === false ? 999 : $rightIndex);
        });

        foreach ($markets as $wrapped) {
            foreach (($wrapped['market']['outcomes'] ?? []) as $outcome) {
                if (! is_array($outcome) || ! isset($outcome['point'])) {
                    continue;
                }

                if ($this->teamMatchesOutcome($homeTeam, (string) ($outcome['name'] ?? ''))) {
                    return (float) $outcome['point'];
                }
            }
        }

        return null;
    }

    private function teamMatchesOutcome(mixed $team, string $outcomeName): bool
    {
        $haystack = $this->normalizeTeamName($outcomeName);

        foreach ($this->teamNameCandidates($team) as $candidate) {
            $normalized = $this->normalizeTeamName($candidate);

            if ($normalized !== '' && ($haystack === $normalized || str_contains($haystack, $normalized))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function teamNameCandidates(mixed $team): array
    {
        if (! $team) {
            return [];
        }

        return array_values(array_filter([
            $team->display_name ?? null,
            trim((string) ($team->location ?? $team->school ?? '').' '.(string) ($team->name ?? $team->mascot ?? '')),
            $team->location ?? $team->school ?? null,
            $team->name ?? $team->mascot ?? null,
            $team->short_display_name ?? null,
            $team->abbreviation ?? null,
        ], fn (mixed $value): bool => trim((string) $value) !== ''));
    }

    private function normalizeTeamName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['.', '-', '_'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return str_replace([
            'la sparks',
            'ny liberty',
            'lv aces',
        ], [
            'los angeles sparks',
            'new york liberty',
            'las vegas aces',
        ], $normalized);
    }

    private function teamName(mixed $team): string
    {
        $location = trim((string) ($team->location ?? $team->school ?? ''));
        $name = trim((string) ($team->name ?? $team->mascot ?? ''));
        $fullName = trim("{$location} {$name}");

        return $fullName !== '' ? $fullName : (string) ($team->abbreviation ?? 'UNK');
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
            return $homeSpread < 0
                ? "Bet {$homeTeam} ".number_format($homeSpread, 1)
                : "Bet {$homeTeam} +".number_format($homeSpread, 1);
        }

        if ($homeSpread < 0) {
            return "Bet {$awayTeam} +".number_format(abs($homeSpread), 1);
        }

        if ($homeSpread > 0) {
            return "Bet {$awayTeam} -".number_format($homeSpread, 1);
        }

        return 'Bet PK';
    }
}
