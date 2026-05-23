<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AnalyzeReasonCodesCommand extends Command
{
    protected $signature = 'nfl:analyze-reason-codes
                            {--season= : Season to analyze (defaults to config nfl.season.default)}
                            {--from-season= : Analyze starting with this NFL season}
                            {--to-season= : Analyze through this NFL season}
                            {--codes=* : Require these reason codes to appear together}
                            {--min-games=20 : Minimum games for generated combinations}
                            {--top=25 : Number of generated combinations to show}
                            {--max-size=3 : Maximum generated combination size}
                            {--max-codes-per-game=14 : Maximum eligible generated-combo codes per prediction}';

    protected $description = 'Analyze NFL prediction hit rates by reason-code combinations';

    public function handle(): int
    {
        try {
            $scope = $this->resolveScope();
            $minGames = max(1, (int) $this->option('min-games'));
            $top = max(1, (int) $this->option('top'));
            $maxSize = max(1, min(5, (int) $this->option('max-size')));
            $maxCodesPerGame = max(4, (int) $this->option('max-codes-per-game'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $predictions = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function ($query) use ($scope) {
                $query->where('status', 'STATUS_FINAL');

                if ($scope['season'] !== null) {
                    $query->where('season', $scope['season']);
                }

                if ($scope['from_season'] !== null) {
                    $query->where('season', '>=', $scope['from_season']);
                }

                if ($scope['to_season'] !== null) {
                    $query->where('season', '<=', $scope['to_season']);
                }
            })
            ->get()
            ->filter(fn (Prediction $prediction): bool => ! empty($this->reasonCodes($prediction)))
            ->values();

        if ($predictions->isEmpty()) {
            $this->warn('No predictions with reason codes found for '.$scope['label']);

            return Command::SUCCESS;
        }

        $requiredCodes = collect($this->option('codes'))
            ->flatMap(fn (string $value): array => preg_split('/[,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $code): string => trim($code))
            ->filter()
            ->unique()
            ->values();

        $this->info("Analyzing {$predictions->count()} predictions with reason codes from {$scope['label']}...");
        $this->newLine();

        if ($requiredCodes->isNotEmpty()) {
            $matches = $predictions
                ->filter(fn (Prediction $prediction): bool => $requiredCodes->every(
                    fn (string $code): bool => in_array($code, $this->reasonCodes($prediction), true)
                ))
                ->values();

            $this->info('Required Reason-Code Set');
            $this->table(
                ['Reason Codes', 'Games', 'Winner Acc', 'Avg Trust', 'Spread MAE', 'Seasons'],
                [$this->summaryRow($requiredCodes->implode(' + '), $matches)]
            );
            $this->newLine();
        }

        $this->info("Top Reason-Code Combinations (minimum {$minGames} games)");
        $this->table(
            ['Reason Codes', 'Games', 'Winner Acc', 'Avg Trust', 'Spread MAE', 'Seasons'],
            $this->combinationRows($predictions, $maxSize, $minGames, $top, $maxCodesPerGame)
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{season:?int,from_season:?int,to_season:?int,label:string}
     */
    protected function resolveScope(): array
    {
        $season = $this->option('season');
        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');

        if ($season !== null && ($fromSeason !== null || $toSeason !== null)) {
            throw new \InvalidArgumentException('Use either --season or --from-season/--to-season, not both.');
        }

        if ($fromSeason !== null || $toSeason !== null) {
            $start = (int) ($fromSeason ?? $toSeason);
            $end = (int) ($toSeason ?? $fromSeason);

            if ($start > $end) {
                throw new \InvalidArgumentException('--from-season must be less than or equal to --to-season.');
            }

            return [
                'season' => null,
                'from_season' => $start,
                'to_season' => $end,
                'label' => "seasons {$start}-{$end}",
            ];
        }

        $resolvedSeason = (int) ($season ?? config('nfl.season.default'));

        return [
            'season' => $resolvedSeason,
            'from_season' => null,
            'to_season' => null,
            'label' => "season {$resolvedSeason}",
        ];
    }

    /**
     * @param  Collection<int, Prediction>  $predictions
     * @return array<int, array<int, mixed>>
     */
    protected function combinationRows(Collection $predictions, int $maxSize, int $minGames, int $top, int $maxCodesPerGame): array
    {
        $groups = [];
        $standaloneCounts = [];

        foreach ($predictions as $prediction) {
            foreach ($this->reasonCodes($prediction) as $code) {
                $standaloneCounts[$code] = ($standaloneCounts[$code] ?? 0) + 1;
            }
        }

        foreach ($predictions as $prediction) {
            $codes = collect($this->reasonCodes($prediction))
                ->filter(fn (string $code): bool => ($standaloneCounts[$code] ?? 0) >= $minGames)
                ->sortByDesc(fn (string $code): int => $standaloneCounts[$code] ?? 0)
                ->take($maxCodesPerGame)
                ->sort()
                ->values()
                ->all();
            $limit = min($maxSize, count($codes));

            for ($size = 2; $size <= $limit; $size++) {
                foreach ($this->combinations($codes, $size) as $combination) {
                    $key = implode('|', $combination);
                    $groups[$key] ??= collect();
                    $groups[$key]->push($prediction);
                }
            }
        }

        return collect($groups)
            ->filter(fn (Collection $rows): bool => $rows->count() >= $minGames)
            ->map(fn (Collection $rows, string $key): array => $this->summaryRow(str_replace('|', ' + ', $key), $rows))
            ->sort(function (array $left, array $right): int {
                $leftAccuracy = (float) rtrim((string) $left[2], '%');
                $rightAccuracy = (float) rtrim((string) $right[2], '%');

                if ($leftAccuracy !== $rightAccuracy) {
                    return $rightAccuracy <=> $leftAccuracy;
                }

                return (int) $right[1] <=> (int) $left[1];
            })
            ->take($top)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $items
     * @return array<int, array<int, string>>
     */
    protected function combinations(array $items, int $size): array
    {
        if ($size === 1) {
            return array_map(fn (string $item): array => [$item], $items);
        }

        $result = [];
        $count = count($items);

        for ($i = 0; $i <= $count - $size; $i++) {
            $head = $items[$i];
            $tailCombinations = $this->combinations(array_slice($items, $i + 1), $size - 1);

            foreach ($tailCombinations as $tail) {
                $result[] = array_merge([$head], $tail);
            }
        }

        return $result;
    }

    /**
     * @param  iterable<int, Prediction>  $predictions
     * @return array<int, mixed>
     */
    protected function summaryRow(string $label, iterable $predictions): array
    {
        $rows = collect($predictions)->values();
        $count = $rows->count();
        $correct = $rows->filter(fn (Prediction $prediction): bool => $this->predictionWinnerCorrect($prediction))->count();
        $spreadErrors = $rows
            ->map(fn (Prediction $prediction): ?float => $this->spreadError($prediction))
            ->filter(fn (?float $error): bool => $error !== null)
            ->values();
        $seasons = $rows
            ->map(fn (Prediction $prediction): ?int => $prediction->game?->season !== null ? (int) $prediction->game->season : null)
            ->filter()
            ->unique()
            ->sort()
            ->implode(', ');

        return [
            $label,
            $count,
            $count > 0 ? round(($correct / $count) * 100, 1).'%' : 'n/a',
            $count > 0 ? round((float) $rows->avg(fn (Prediction $prediction): float => (float) data_get($prediction->model_metadata, 'analysis_layer.trust_score', 0)), 1) : 'n/a',
            $spreadErrors->isNotEmpty() ? round((float) $spreadErrors->avg(), 2) : 'n/a',
            $seasons !== '' ? $seasons : 'n/a',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function reasonCodes(Prediction $prediction): array
    {
        $codes = (array) data_get($prediction->model_metadata, 'analysis_layer.reason_codes', []);

        return collect($codes)
            ->map(fn (mixed $code): string => (string) $code)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function predictionWinnerCorrect(Prediction $prediction): bool
    {
        $game = $prediction->game;
        if (! $game || $game->home_score === null || $game->away_score === null) {
            return false;
        }

        $homeWon = (float) $game->home_score > (float) $game->away_score;
        $predictedHomeWin = (float) $prediction->win_probability > 0.5;

        return $predictedHomeWin === $homeWon;
    }

    protected function spreadError(Prediction $prediction): ?float
    {
        $game = $prediction->game;
        if (! $game || $game->home_score === null || $game->away_score === null || $prediction->predicted_spread === null) {
            return null;
        }

        return abs(((float) $game->home_score - (float) $game->away_score) - (float) $prediction->predicted_spread);
    }
}
