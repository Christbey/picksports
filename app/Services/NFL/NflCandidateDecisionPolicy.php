<?php

namespace App\Services\NFL;

use App\Models\MarketQuote;
use App\Models\NFL\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class NflCandidateDecisionPolicy
{
    /**
     * Preserve model/data consensus independently of snapshot storage.
     *
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $configuration
     * @return Collection<string, array<string, mixed>>
     */
    public function candidateRecommendations(array $analysis, array $configuration = []): Collection
    {
        $proSignal = (array) ($analysis['pro_signal_layer'] ?? []);

        if (($analysis['applied'] ?? false) !== true
            || ($analysis['bet_classification'] ?? null) !== 'bet'
            || data_get($analysis, 'eligibility.eligible') !== true
            || ($proSignal['tier'] ?? null) !== 'official_candidate') {
            return collect();
        }

        return collect((array) ($proSignal['recommended_markets'] ?? []))
            ->filter(fn (mixed $recommendation): bool => is_array($recommendation)
                && ($recommendation['tier'] ?? null) === 'official_candidate')
            ->filter(fn (array $recommendation): bool => $this->marketKey($recommendation) !== null)
            ->filter(function (array $recommendation) use ($analysis, $configuration): bool {
                if ($this->marketKey($recommendation) !== 'spreads') {
                    return true;
                }
                $edge = data_get($analysis, 'calculated_edge.spread_points');

                return is_numeric($edge) && is_finite((float) $edge)
                    && abs((float) $edge) >= (float) ($configuration['minimum_spread_edge'] ?? config('nfl.predictions.analysis_layer.min_spread_edge', 2.0));
            })
            ->unique(fn (array $recommendation): string => $this->marketKey($recommendation))
            ->keyBy(fn (array $recommendation): string => $this->marketKey($recommendation));
    }

    /** @param array<string, mixed> $recommendation */
    public function marketKey(array $recommendation): ?string
    {
        return match (strtolower((string) ($recommendation['market'] ?? ''))) {
            'winner', 'moneyline', 'h2h' => 'h2h',
            'spread', 'spreads' => 'spreads',
            'total', 'totals' => 'totals',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $recommendation
     * @return array{market_type:string,market_key:string,side:string,line:float|null,edge:float|null,model_probability:float|null}|null
     */
    public function selection(?float $homeProbability, array $analysis, array $recommendation, array $configuration = []): ?array
    {
        $market = strtolower((string) ($recommendation['market'] ?? ''));
        $spreadEdge = $this->number(data_get($analysis, 'calculated_edge.spread_points'));
        $marketSpread = $this->number(data_get($analysis, 'calculated_edge.market_spread'));
        $totalEdge = $this->number(data_get($analysis, 'calculated_edge.total_points'));
        $marketTotal = $this->number(data_get($analysis, 'calculated_edge.market_total'));

        if ($market === 'winner') {
            if (! (bool) ($configuration['moneyline_play_enabled'] ?? config('nfl.betting.moneyline.play_enabled', false)) || $homeProbability === null) {
                return null;
            }

            $side = $homeProbability >= 0.5 ? 'home' : 'away';

            return [
                'market_type' => 'moneyline',
                'market_key' => 'h2h',
                'side' => $side,
                'line' => null,
                'edge' => null,
                'model_probability' => $side === 'home' ? $homeProbability : 1 - $homeProbability,
            ];
        }

        if ($market === 'spread' && $spreadEdge !== null && $marketSpread !== null) {
            $side = $spreadEdge >= 0 ? 'home' : 'away';

            return [
                'market_type' => 'spread',
                'market_key' => 'spreads',
                'side' => $side,
                'line' => $side === 'home' ? -$marketSpread : $marketSpread,
                'edge' => abs($spreadEdge),
                'model_probability' => null,
            ];
        }

        if ($market === 'total' && $totalEdge !== null && $marketTotal !== null) {
            return [
                'market_type' => 'total',
                'market_key' => 'totals',
                'side' => $totalEdge >= 0 ? 'over' : 'under',
                'line' => $marketTotal,
                'edge' => abs($totalEdge),
                'model_probability' => null,
            ];
        }

        return null;
    }

    /** @param array{market_type:string,market_key:string,side:string,line:float|null,edge:float|null,model_probability:float|null} $selection */
    public function entryQuote(
        Game $game,
        array $selection,
        CarbonImmutable $decidedAt,
        CarbonImmutable $gameStartAt,
    ): ?MarketQuote {
        $freshAfter = $decidedAt->subMinutes($this->maximumQuoteAgeMinutes());
        $query = MarketQuote::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->getKey())
            ->where('market_key', $selection['market_key'])
            ->where('side', $selection['side'])
            ->where('is_pregame', true)
            ->whereNotNull('price')
            ->where('captured_at', '>=', $freshAfter)
            ->where('captured_at', '<=', $decidedAt)
            ->where('captured_at', '<', $gameStartAt);

        if ($selection['market_type'] !== 'moneyline') {
            if ($selection['line'] === null) {
                return null;
            }

            $query->whereBetween('line', [$selection['line'] - 0.001, $selection['line'] + 0.001]);
        }

        return $query
            ->latest('captured_at')
            ->latest('id')
            ->get()
            ->first(fn (MarketQuote $quote): bool => $this->quoteObservedAt($quote)->betweenIncluded($freshAfter, $decidedAt)
                && $this->hasPairedQuote($quote, $freshAfter, $decidedAt, $gameStartAt));
    }

    private function hasPairedQuote(
        MarketQuote $quote,
        CarbonImmutable $freshAfter,
        CarbonImmutable $decidedAt,
        CarbonImmutable $gameStartAt,
    ): bool {
        $oppositeSide = match ($quote->side) {
            'home' => 'away',
            'away' => 'home',
            'over' => 'under',
            'under' => 'over',
            default => null,
        };

        if ($oppositeSide === null) {
            return false;
        }

        return MarketQuote::query()
            ->where('game_odds_snapshot_id', $quote->game_odds_snapshot_id)
            ->where('market_key', $quote->market_key)
            ->where('side', $oppositeSide)
            ->where('bookmaker_key', $quote->bookmaker_key)
            ->where('bookmaker_title', $quote->bookmaker_title)
            ->whereNotNull('price')
            ->get()
            ->contains(fn (MarketQuote $opposite): bool => $this->pairedLinesMatch($quote, $opposite)
                && $this->quoteObservedAt($opposite)->betweenIncluded($freshAfter, $decidedAt)
                && $this->quoteObservedAt($opposite)->lt($gameStartAt));
    }

    private function pairedLinesMatch(MarketQuote $quote, MarketQuote $opposite): bool
    {
        if ($quote->market_key === 'h2h') {
            return true;
        }

        if (! is_numeric($quote->line) || ! is_numeric($opposite->line)) {
            return false;
        }

        return $quote->market_key === 'spreads'
            ? abs((float) $quote->line + (float) $opposite->line) <= 0.001
            : abs((float) $quote->line - (float) $opposite->line) <= 0.001;
    }

    public function maximumQuoteAgeMinutes(): int
    {
        return max(1, (int) config('nfl_research.market_freshness_minutes', 2160));
    }

    private function quoteObservedAt(MarketQuote $quote): CarbonImmutable
    {
        $providerObservedAt = data_get($quote->metadata, 'provider_observed_at');
        if (is_string($providerObservedAt) && $providerObservedAt !== '') {
            try {
                return CarbonImmutable::parse($providerObservedAt);
            } catch (Throwable) {
                // Fall through to the persisted ingestion timestamp.
            }
        }

        return CarbonImmutable::instance($quote->captured_at);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
