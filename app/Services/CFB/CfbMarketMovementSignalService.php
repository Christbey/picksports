<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;
use App\Models\MarketQuote;
use App\Support\Odds\MarketSpread;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CfbMarketMovementSignalService
{
    /**
     * @return array<string, mixed>|null
     */
    public function spreadContext(Game $game, float $modelHomeMargin, ?CarbonInterface $asOf = null): ?array
    {
        if (! (bool) config('cfb.predictions.market_movement.enabled', true)) {
            return null;
        }

        $asOf ??= now();
        $quotes = $this->pregameSpreadQuotes($game, $asOf);
        $points = $quotes->isEmpty()
            ? $this->fallbackCurrentPoint($game, $asOf)
            : $this->consensusPoints($quotes);

        if ($points->isEmpty()) {
            return null;
        }

        $open = $points->first();
        $current = $points->last();
        $closing = $this->closingPoint($game, $asOf);
        $modelSide = $this->modelSide($modelHomeMargin);
        $movement = $this->difference($current['home_margin'] ?? null, $open['home_margin'] ?? null);
        $lineValueFromOpen = $this->lineValueForSide(
            $modelSide,
            $open['home_margin'] ?? null,
            $current['home_margin'] ?? null,
        );
        $closingLineValue = $closing === null
            ? null
            : $this->lineValueForSide(
                $modelSide,
                $current['home_margin'] ?? null,
                $closing['home_margin'] ?? null,
            );
        $bookRange = $this->floatOrNull($current['bookmaker_home_line_range'] ?? null);

        return [
            'source' => $quotes->isEmpty() ? 'game_odds_latest' : 'market_quotes_consensus',
            'spread_conventions' => [
                'bookmaker_home_line' => MarketSpread::BOOKMAKER_HOME_LINE_CONVENTION,
                'home_margin' => MarketSpread::HOME_MARGIN_CONVENTION,
            ],
            'model_home_margin' => round($modelHomeMargin, 3),
            'model_pick_side' => $modelSide,
            'open_bookmaker_home_line' => $this->roundNullable($open['bookmaker_home_line'] ?? null),
            'open_home_margin' => $this->roundNullable($open['home_margin'] ?? null),
            'open_captured_at' => $open['captured_at'] ?? null,
            'open_book_count' => (int) ($open['book_count'] ?? 0),
            'current_bookmaker_home_line' => $this->roundNullable($current['bookmaker_home_line'] ?? null),
            'current_home_margin' => $this->roundNullable($current['home_margin'] ?? null),
            'current_captured_at' => $current['captured_at'] ?? null,
            'current_book_count' => (int) ($current['book_count'] ?? 0),
            'closing_bookmaker_home_line' => $this->roundNullable($closing['bookmaker_home_line'] ?? null),
            'closing_home_margin' => $this->roundNullable($closing['home_margin'] ?? null),
            'closing_captured_at' => $closing['captured_at'] ?? null,
            'line_movement_home_margin' => $this->roundNullable($movement),
            'line_value_from_open' => $this->roundNullable($lineValueFromOpen),
            'closing_line_value_points' => $this->roundNullable($closingLineValue),
            'closing_line_value_bucket' => $this->lineValueBucket($closingLineValue),
            'line_moved_toward_model' => $lineValueFromOpen === null ? null : $lineValueFromOpen > 0,
            'line_moved_against_model' => $lineValueFromOpen === null ? null : $lineValueFromOpen < 0,
            'model_edge_vs_current' => $this->roundNullable($this->difference(
                $modelHomeMargin,
                $current['home_margin'] ?? null,
            )),
            'current_bookmaker_home_line_range' => $this->roundNullable($bookRange),
            'snapshot_count' => $points->count(),
            'confidence_adjustment' => $this->confidenceAdjustment($lineValueFromOpen, $bookRange),
            'risk_flags' => $this->riskFlags($lineValueFromOpen, $bookRange, $current),
        ];
    }

    public function currentBookmakerHomeLine(Game $game, ?CarbonInterface $asOf = null): ?float
    {
        $context = $this->spreadContext($game, 0.0, $asOf);

        return $this->floatOrNull($context['current_bookmaker_home_line'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function withClosingLineValue(Game $game, array $context, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();
        $closing = $this->closingPoint($game, $asOf);
        $modelSide = is_string($context['model_pick_side'] ?? null)
            ? (string) $context['model_pick_side']
            : $this->modelSide((float) ($context['model_home_margin'] ?? 0.0));
        $entryHomeMargin = $this->floatOrNull($context['current_home_margin'] ?? null);
        $closingHomeMargin = $this->floatOrNull($closing['home_margin'] ?? null);
        $closingLineValue = $this->lineValueForSide($modelSide, $entryHomeMargin, $closingHomeMargin);

        return [
            ...$context,
            'closing_bookmaker_home_line' => $this->roundNullable($closing['bookmaker_home_line'] ?? null),
            'closing_home_margin' => $this->roundNullable($closingHomeMargin),
            'closing_captured_at' => $closing['captured_at'] ?? null,
            'closing_line_value_points' => $this->roundNullable($closingLineValue),
            'closing_line_value_bucket' => $this->lineValueBucket($closingLineValue),
        ];
    }

    /**
     * @return Collection<int, MarketQuote>
     */
    private function pregameSpreadQuotes(Game $game, CarbonInterface $asOf): Collection
    {
        if (! Schema::hasTable('market_quotes')) {
            return collect();
        }

        return MarketQuote::query()
            ->where('sport', 'cfb')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->id)
            ->where('market_key', 'spreads')
            ->where('side', 'home')
            ->whereNotNull('bookmaker_home_line')
            ->where('captured_at', '<=', Carbon::instance($asOf instanceof Carbon ? $asOf : $asOf->toMutable()))
            ->where(function ($query): void {
                $query->where('is_pregame', true)->orWhereNull('is_pregame');
            })
            ->orderBy('captured_at')
            ->get();
    }

    /**
     * @param  Collection<int, MarketQuote>  $quotes
     * @return Collection<int, array<string, mixed>>
     */
    private function consensusPoints(Collection $quotes): Collection
    {
        return $quotes
            ->groupBy(fn (MarketQuote $quote): string => $quote->captured_at?->toIso8601String() ?? (string) $quote->id)
            ->map(function (Collection $group): array {
                $lines = $group
                    ->map(fn (MarketQuote $quote): ?float => $this->floatOrNull($quote->bookmaker_home_line))
                    ->filter(fn (?float $line): bool => $line !== null)
                    ->values();
                $bookmakerHomeLine = $this->median($lines->all());

                return [
                    'bookmaker_home_line' => $bookmakerHomeLine,
                    'home_margin' => $bookmakerHomeLine === null
                        ? null
                        : MarketSpread::bookmakerHomeLineToHomeMargin($bookmakerHomeLine),
                    'captured_at' => $group->first()?->captured_at?->toIso8601String(),
                    'book_count' => $group->pluck('bookmaker_key')->filter()->unique()->count(),
                    'bookmaker_home_line_range' => $lines->isEmpty()
                        ? null
                        : (float) $lines->max() - (float) $lines->min(),
                ];
            })
            ->filter(fn (array $point): bool => $point['bookmaker_home_line'] !== null)
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function closingPoint(Game $game, CarbonInterface $asOf): ?array
    {
        $gameStart = $this->gameStartAt($game);
        $asOfCarbon = Carbon::instance($asOf instanceof Carbon ? $asOf : $asOf->toMutable());

        if ($gameStart !== null && $asOfCarbon->lt($gameStart)) {
            return null;
        }

        $quotes = $this->pregameSpreadQuotes($game, $asOf);

        return $quotes->isEmpty()
            ? null
            : $this->consensusPoints($quotes)->last();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fallbackCurrentPoint(Game $game, CarbonInterface $asOf): Collection
    {
        $bookmakerHomeLine = $this->bookmakerHomeLineFromOddsData($game);

        if ($bookmakerHomeLine === null) {
            return collect();
        }

        return collect([[
            'bookmaker_home_line' => $bookmakerHomeLine,
            'home_margin' => MarketSpread::bookmakerHomeLineToHomeMargin($bookmakerHomeLine),
            'captured_at' => $asOf->toIso8601String(),
            'book_count' => 1,
            'bookmaker_home_line_range' => 0.0,
        ]]);
    }

    private function bookmakerHomeLineFromOddsData(Game $game): ?float
    {
        $oddsData = $game->odds_data;

        if (! is_array($oddsData) || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        $homeNames = $this->teamNames($game->homeTeam);

        foreach ($oddsData['bookmakers'] as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (! is_numeric($outcome['point'] ?? null)) {
                        continue;
                    }

                    if ($this->outcomeMatchesTeam((string) ($outcome['name'] ?? ''), $homeNames)) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    private function gameStartAt(Game $game): ?Carbon
    {
        if ($game->game_date === null) {
            return null;
        }

        $date = $game->game_date instanceof Carbon
            ? $game->game_date->toDateString()
            : Carbon::parse((string) $game->game_date)->toDateString();
        $time = is_string($game->game_time) && trim($game->game_time) !== ''
            ? trim($game->game_time)
            : '00:00:00';

        return Carbon::parse("{$date} {$time}", (string) config('app.timezone', 'UTC'));
    }

    private function modelSide(float $modelHomeMargin): string
    {
        $threshold = (float) config('cfb.predictions.market_movement.pick_side_threshold', 0.5);

        return match (true) {
            $modelHomeMargin >= $threshold => 'home',
            $modelHomeMargin <= -$threshold => 'away',
            default => 'toss_up',
        };
    }

    private function lineValueForSide(string $side, mixed $entryHomeMargin, mixed $targetHomeMargin): ?float
    {
        $entry = $this->floatOrNull($entryHomeMargin);
        $target = $this->floatOrNull($targetHomeMargin);

        if ($entry === null || $target === null || ! in_array($side, ['home', 'away'], true)) {
            return null;
        }

        return $side === 'home'
            ? $target - $entry
            : $entry - $target;
    }

    private function confidenceAdjustment(?float $lineValueFromOpen, ?float $bookRange): float
    {
        if (! (bool) config('cfb.predictions.market_movement.apply_confidence_adjustment', true)) {
            return 0.0;
        }

        $minMovement = (float) config('cfb.predictions.market_movement.min_movement_points', 1.0);
        if ($lineValueFromOpen === null || abs($lineValueFromOpen) < $minMovement) {
            return 0.0;
        }

        $adjustment = $lineValueFromOpen > 0
            ? (float) config('cfb.predictions.market_movement.confidence_boost_toward_model', 1.5)
            : -1 * (float) config('cfb.predictions.market_movement.confidence_penalty_against_model', 2.0);

        if ($bookRange !== null && $bookRange >= (float) config('cfb.predictions.market_movement.book_disagreement_threshold', 1.0)) {
            $adjustment -= (float) config('cfb.predictions.market_movement.book_disagreement_penalty', 1.0);
        }

        $maxAdjustment = (float) config('cfb.predictions.market_movement.max_confidence_adjustment', 3.0);

        return round(max(-$maxAdjustment, min($maxAdjustment, $adjustment)), 2);
    }

    /**
     * @return list<string>
     */
    private function riskFlags(?float $lineValueFromOpen, ?float $bookRange, array $current): array
    {
        $flags = [];
        $minMovement = (float) config('cfb.predictions.market_movement.min_movement_points', 1.0);

        if ($lineValueFromOpen !== null && $lineValueFromOpen <= -$minMovement) {
            $flags[] = 'market_moved_against_model';
        }

        if ($lineValueFromOpen !== null && $lineValueFromOpen >= $minMovement) {
            $flags[] = 'market_moved_toward_model';
        }

        if ($bookRange !== null && $bookRange >= (float) config('cfb.predictions.market_movement.book_disagreement_threshold', 1.0)) {
            $flags[] = 'book_consensus_disagreement';
        }

        if ((int) ($current['book_count'] ?? 0) < (int) config('cfb.predictions.market_movement.min_current_books', 1)) {
            $flags[] = 'thin_market_consensus';
        }

        return $flags;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        $values = array_values(array_filter($values, fn (mixed $value): bool => is_numeric($value)));

        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private function difference(mixed $a, mixed $b): ?float
    {
        $left = $this->floatOrNull($a);
        $right = $this->floatOrNull($b);

        return $left === null || $right === null ? null : $left - $right;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function roundNullable(mixed $value): ?float
    {
        $float = $this->floatOrNull($value);

        return $float === null ? null : round($float, 3);
    }

    private function lineValueBucket(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $value >= 2.0 => 'strong_positive',
            $value >= 0.25 => 'positive',
            $value <= -2.0 => 'strong_negative',
            $value <= -0.25 => 'negative',
            default => 'neutral',
        };
    }

    /**
     * @return array<int, string>
     */
    private function teamNames(?object $team): array
    {
        if (! $team) {
            return [];
        }

        return collect([
            $team->name ?? null,
            $team->display_name ?? null,
            $team->short_display_name ?? null,
            $team->school ?? null,
            $team->abbreviation ?? null,
            $team->location ?? null,
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => strtolower(trim($value)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $teamNames
     */
    private function outcomeMatchesTeam(string $outcomeName, array $teamNames): bool
    {
        $normalizedOutcome = strtolower(trim($outcomeName));

        if ($normalizedOutcome === '') {
            return false;
        }

        foreach ($teamNames as $teamName) {
            if ($normalizedOutcome === $teamName
                || str_contains($normalizedOutcome, $teamName)
                || str_contains($teamName, $normalizedOutcome)
            ) {
                return true;
            }
        }

        return false;
    }
}
