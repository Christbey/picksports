<?php

namespace App\Services\NFL\Research;

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\MarketQuote;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\ResearchRevision;
use App\Models\SportsGameContextReport;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class ResearchPipeline
{
    public function review(Game $game, bool $research = true): ?ResearchRevision
    {
        return Cache::lock('nfl-research-game:'.$game->id, 300)->get(function () use ($game, $research) {
            $game->loadMissing(['homeTeam', 'awayTeam', 'prediction']);
            $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
            if (! $kickoff
                || $kickoff->lte(now())
                || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
                return null;
            }
            $packet = app(EvidencePacket::class)->forGame($game);
            // Snapshot-only overlay: existing configured injury weights remain the sole numerical adjustment.
            $game->setAttribute('research_availability', $packet['availability']);
            $preview = app(GeneratePredictionFromHistoricalElo::class)->preview($game);
            if (! $preview) {
                return null;
            }
            $game->setAttribute('research_candidate', [...$preview['outputs'], 'model_metadata' => $preview['model_metadata']]);
            $report = SportsGameContextReport::where('sport', 'nfl')->where('game_id', $game->id)->latest('id')->first();
            $selectedDocuments = app(EvidencePacket::class)->researchDocuments($packet);
            $ids = array_column($selectedDocuments, 'id');
            $candidateHash = $this->candidateContextHash([...$preview['outputs'], 'model_metadata' => $preview['model_metadata']]);
            $changed = $report && ($ids !== data_get($report->raw_payload, 'document_ids', []) || app(EvidencePacket::class)->contextHash($packet) !== data_get($report->raw_payload, 'evidence_context_hash') || $candidateHash !== data_get($report->raw_payload, 'candidate_hash'));
            if ($research && (! $report || ($report->status !== 'ready' && $report->researched_at->lt(now()->subMinutes(45))) || ! $report->expires_at || $report->expires_at->lte(now()) || $changed)) {
                $report = app(NflWebContextResearchService::class)->research($game)['report'];
                $changed = false;
            }
            $holds = $packet['holds'];
            if (! $report || $report->status !== 'ready' || ! $report->expires_at || $report->expires_at->lte(now()) || $changed) {
                $holds[] = 'research_incomplete_or_stale';
            }
            if (! $game->prediction) {
                $holds[] = 'original_prediction_missing';
            }
            $market = $this->quotes($game);
            if (! $this->marketIsFresh($game, $market)) {
                $holds[] = 'market_stale';
            }
            $decision = data_get($report?->raw_payload, 'decision_research', []);
            if (empty($decision['supporting']) || empty($decision['opposing'])) {
                $holds[] = 'two_sided_research_missing';
            }
            if (app(ResearchUncertaintyPolicy::class)->holdsFor($decision['unresolved'] ?? [], 'spread') !== []) {
                $holds[] = 'unresolved_research_questions';
            }
            $analysis = data_get($preview, 'model_metadata.analysis_layer', []);
            $eligibility = app(RecommendationEligibility::class)->evaluate($analysis, $preview['model_metadata'], $holds);
            $original = ResearchRevision::where('game_id', $game->id)->oldest('id')->first();
            $baseline = $original?->baseline ?? $game->prediction?->only(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score', 'model_version', 'updated_at']) ?? [];
            if ($market === []) {
                $eligibility = app(RecommendationEligibility::class)->evaluate($analysis, $preview['model_metadata'], [...$holds, 'spread_quote_missing']);
            }
            $props = app(PlayerPropAnalyzer::class)->previewNflGame($game);
            $propHolds = app(ResearchUncertaintyPolicy::class)->holdsFor($decision['unresolved'] ?? [], 'props');
            if ($propHolds !== []) {
                $props = array_map(fn ($prop) => [...$prop, 'status' => 'hold', 'research_holds' => $propHolds], $props);
            }
            $previous = ResearchRevision::where('game_id', $game->id)->latest('id')->first();
            $brief = [
                'game' => $game->short_name ?: $game->name,
                'kickoff_at' => $kickoff->toIso8601String(),
                'eligibility' => $eligibility,
                'supporting' => $decision['supporting'] ?? [],
                'opposing' => $decision['opposing'] ?? [],
                'player_props' => $props,
                'prop_angles' => $decision['prop_angles'] ?? [],
                'unresolved' => $decision['unresolved'] ?? [],
                'market_holds' => collect(['spread', 'total', 'moneyline', 'props'])->mapWithKeys(fn ($scope) => [$scope => app(ResearchUncertaintyPolicy::class)->holdsFor($decision['unresolved'] ?? [], $scope)])->all(),
                'facts' => $report?->facts ?? [],
                'risk_flags' => $report?->risk_flags ?? [],
                'model_signals' => ['qb' => data_get($preview, 'model_metadata.qb_form'), 'trenches' => data_get($preview, 'model_metadata.line_matchup'), 'injuries' => data_get($preview, 'model_metadata.depth_chart_injuries')],
                'numeric_adjustment_policy' => 'Existing model weights only; narrative interpretation is not a calibrated adjustment.',
                'source_report_id' => $report?->id,
                'source_coverage' => $packet['source_coverage'] ?? [],
                'previous_revision_id' => $previous?->id,
                'change' => $previous ? ['spread' => round($preview['outputs']['predicted_spread'] - ($previous->revised['predicted_spread'] ?? 0), 2), 'total' => round($preview['outputs']['predicted_total'] - ($previous->revised['predicted_total'] ?? 0), 2), 'eligibility_changed' => data_get($previous->brief, 'eligibility.status') !== $eligibility['status']] : ['initial' => true],
            ];
            $evidence = ['documents' => array_map(fn ($d) => array_diff_key($d, ['text' => true]), $selectedDocuments), 'availability' => $packet['availability'], 'report_id' => $report?->id];
            $materialHash = $this->revisionHash($preview, $evidence, $market, $eligibility, $props, $brief);
            // Preserve semantic deduplication while its immutable evidence is
            // current. Once that report expires, link the refreshed evidence in
            // a new revision even when the model recommendation is unchanged.
            $renewEvidence = $previous && $report && $previous->report_id !== $report->id
                && $report->expires_at?->gt(now())
                && ! SportsGameContextReport::query()->where('sport', 'nfl')->where('game_id', $game->id)
                    ->whereKey($previous->report_id)->where('expires_at', '>', now())->exists();
            if ($previous && ! $renewEvidence && data_get($previous->brief, 'material_hash', $previous->input_hash) === $materialHash) {
                return $previous;
            }
            $brief['material_hash'] = $materialHash;
            $hash = $materialHash;
            if (ResearchRevision::where('game_id', $game->id)->where('input_hash', $hash)->exists()) {
                // A recovered state may match an older semantic revision. Preserve
                // the state transition while subsequent identical runs deduplicate.
                $hash = hash('sha256', $materialHash.'|after|'.($previous?->id ?? 0));
            }

            return ResearchRevision::firstOrCreate(['game_id' => $game->id, 'input_hash' => $hash], ['report_id' => $report?->id, 'baseline' => $baseline, 'revised' => [...$preview['outputs'], 'model_version' => $preview['model_version']], 'evidence' => $evidence, 'brief' => $brief, 'market' => $market, 'created_at' => now()]);
        });
    }

    /** @param array<string, mixed> $candidate */
    public function candidateContextHash(array $candidate): string
    {
        $metadata = (array) ($candidate['model_metadata'] ?? []);

        return hash('sha256', json_encode([
            'version' => 3,
            'uncertainty_policy_version' => ResearchUncertaintyPolicy::VERSION,
            'outputs' => [
                'spread' => $this->bucket($candidate['predicted_spread'] ?? null, (float) config('nfl_research.material_revision.spread_increment', 0.5)),
                'total' => $this->bucket($candidate['predicted_total'] ?? null, (float) config('nfl_research.material_revision.total_increment', 0.5)),
                'probability' => $this->bucket($candidate['win_probability'] ?? null, (float) config('nfl_research.material_revision.probability_increment', 0.01)),
            ],
            'true_epa' => Arr::only((array) data_get($metadata, 'true_epa', []), ['enabled', 'applied', 'reason', 'source']),
            'quarterbacks' => collect(['home', 'away'])->mapWithKeys(fn ($side) => [$side => Arr::only((array) data_get($metadata, 'qb_form.'.$side, []), ['qb_id', 'qb_name', 'reason', 'replaced_unavailable_qb_ids'])])->all(),
            'analysis' => [
                'classification' => data_get($metadata, 'analysis_layer.bet_classification'),
                'raw_classification' => data_get($metadata, 'analysis_layer.raw_bet_classification'),
                'risk_flags' => $this->sortedStrings((array) data_get($metadata, 'analysis_layer.risk_flags', [])),
                'eligibility' => data_get($metadata, 'analysis_layer.eligibility.status'),
            ],
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $evidence
     * @param  array<int, array<string, mixed>>  $market
     * @param  array<string, mixed>  $eligibility
     * @param  array<int, array<string, mixed>>  $props
     * @param  array<string, mixed>  $brief
     */
    private function revisionHash(array $preview, array $evidence, array $market, array $eligibility, array $props, array $brief): string
    {
        $spreadIncrement = (float) config('nfl_research.material_revision.spread_increment', 0.5);
        $totalIncrement = (float) config('nfl_research.material_revision.total_increment', 0.5);
        $priceIncrement = (float) config('nfl_research.material_revision.price_increment', 10);

        $documents = collect($evidence['documents'] ?? [])->map(fn (array $document): array => Arr::only($document, ['id', 'team', 'url', 'content_hash']))->sortBy('id')->values()->all();
        $availability = collect($evidence['availability'] ?? [])->map(fn (array $fact): array => Arr::only($fact, ['player_id', 'status', 'document_id']))->sortBy('player_id')->values()->all();
        $quotes = collect($market)->map(fn (array $quote): array => [
            'bookmaker' => $quote['bookmaker'] ?? null,
            'side' => $quote['side'] ?? null,
            'line' => $this->bucket($quote['line'] ?? null, $spreadIncrement),
            'price' => $this->bucket($quote['price'] ?? null, $priceIncrement),
        ])->sortBy(fn (array $quote): string => implode('|', $quote))->values()->all();
        $materialProps = collect($props)->map(fn (array $prop): array => [
            'prop_id' => $prop['prop_id'] ?? null,
            'line' => $this->bucket($prop['line'] ?? null, 0.5),
            'over_price' => $this->bucket($prop['over_price'] ?? null, $priceIncrement),
            'under_price' => $this->bucket($prop['under_price'] ?? null, $priceIncrement),
            'baseline_side' => data_get($prop, 'baseline.recommended_side'),
            'revised_side' => data_get($prop, 'revised.recommendation'),
            'status' => $prop['status'] ?? null,
        ])->sortBy('prop_id')->values()->all();
        $decisionEvidence = collect(['supporting', 'opposing'])->mapWithKeys(fn (string $side): array => [
            $side => collect($brief[$side] ?? [])->map(fn (array $item): array => [
                'source_url' => $item['source_url'] ?? null,
                'scope' => $item['scope'] ?? null,
                'claim' => $this->normalizedMaterialText($item['claim'] ?? null),
                'question' => $this->normalizedMaterialText($item['question'] ?? null),
                'interpretation' => $this->normalizedMaterialText($item['interpretation'] ?? null),
            ])->sortBy(fn (array $item): string => json_encode($item, JSON_UNESCAPED_SLASHES))->values()->all(),
        ])->all();
        $unresolved = collect($brief['unresolved'] ?? [])->map(fn (array $item): array => [
            'source_url' => $item['source_url'] ?? null,
            'scope' => $item['scope'] ?? null,
            'blocking' => $item['blocking'] ?? true,
            'claim' => $this->normalizedMaterialText($item['claim'] ?? null),
            'question' => $this->normalizedMaterialText($item['question'] ?? null),
        ])->sortBy(fn (array $item): string => json_encode($item, JSON_UNESCAPED_SLASHES))->values()->all();
        $facts = collect($brief['facts'] ?? [])->map(function ($item): array {
            $fact = is_array($item) ? $item : ['claim' => $item];

            return [
                'category' => $fact['category'] ?? null,
                'team_side' => $fact['team_side'] ?? null,
                'certainty' => $fact['certainty'] ?? null,
                'claim' => $this->normalizedMaterialText($fact['claim'] ?? $fact['fact'] ?? null),
                'source_urls' => collect($fact['source_urls'] ?? [])->filter(fn ($url): bool => is_string($url))->sort()->values()->all(),
            ];
        })->sortBy(fn (array $item): string => json_encode($item, JSON_UNESCAPED_SLASHES))->values()->all();

        return hash('sha256', json_encode([
            'version' => 3,
            'candidate' => $this->candidateContextHash([...$preview['outputs'], 'model_metadata' => $preview['model_metadata']]),
            'model_version' => $preview['model_version'],
            'documents' => $documents,
            'availability' => $availability,
            'decision_evidence' => $decisionEvidence,
            'situational_signals' => [
                'risk_flags' => $this->sortedStrings((array) ($brief['risk_flags'] ?? [])),
                'unresolved' => $unresolved,
                'facts' => $facts,
            ],
            'market' => $quotes,
            'eligibility' => Arr::only($eligibility, ['status', 'data_reasons', 'model_reasons']),
            'props' => $materialProps,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function normalizedMaterialText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        return $text !== '' ? $text : null;
    }

    private function bucket(mixed $value, float $increment): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        if ($increment <= 0) {
            return (float) $value;
        }

        return round(round(((float) $value) / $increment) * $increment, 6);
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private function sortedStrings(array $values): array
    {
        return collect($values)->filter(fn ($value) => is_string($value) && $value !== '')->unique()->sort()->values()->all();
    }

    public function quotes(Game $game): array
    {
        $books = data_get($game->odds_data, 'bookmakers', []);
        $home = $game->odds_data['home_team'] ?? null;
        $quotes = [];
        foreach ($books as $book) {
            foreach ($book['markets'] ?? [] as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }
                foreach ($market['outcomes'] ?? [] as $outcome) {
                    if (! is_numeric($outcome['point'] ?? null) || ! is_numeric($outcome['price'] ?? null) || ! $home) {
                        continue;
                    }
                    $quotes[] = ['bookmaker' => $book['key'], 'side' => $outcome['name'] === $home ? 'home' : 'away', 'line' => (float) $outcome['point'], 'price' => (int) $outcome['price'], 'observed_at' => $market['last_update'] ?? $game->odds_updated_at?->toIso8601String()];
                }
            }
        }

        return $this->freshPairedSpreadQuotes($quotes);
    }

    /** Fresh, same-book paired prices; never substitute web lines for the feed. */
    public function additionalMarketQuotes(Game $game, string $key): array
    {
        if (! in_array($key, ['totals', 'h2h'], true)) {
            return [];
        }
        $result = [];
        $freshAfter = now()->subMinutes(max(1, (int) config('nfl_research.market_freshness_minutes', 60)));
        foreach (data_get($game->odds_data, 'bookmakers', []) as $book) {
            if (empty($book['key'])) {
                continue;
            }
            foreach ($book['markets'] ?? [] as $market) {
                if (($market['key'] ?? null) !== $key) {
                    continue;
                }
                $observedAt = $market['last_update'] ?? $game->odds_updated_at?->toIso8601String();
                try {
                    if (! is_string($observedAt) || ! Carbon::parse($observedAt)->betweenIncluded($freshAfter, now())) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
                $names = $key === 'totals'
                    ? ['over' => 'Over', 'under' => 'Under']
                    : ['home' => data_get($game->odds_data, 'home_team'), 'away' => data_get($game->odds_data, 'away_team')];
                $pair = [];
                foreach ($names as $side => $name) {
                    $outcome = collect($market['outcomes'] ?? [])->first(fn ($row) => $name && ($row['name'] ?? null) === $name);
                    if (! is_numeric($outcome['price'] ?? null) || abs((float) $outcome['price']) < 100
                        || ($key === 'totals' && ! is_numeric($outcome['point'] ?? null))) {
                        continue;
                    }
                    $pair[] = ['bookmaker' => $book['key'], 'side' => $side, 'line' => $key === 'totals' ? (float) $outcome['point'] : null, 'price' => (int) $outcome['price'], 'observed_at' => $observedAt];
                }
                if (count($pair) === 2 && ($key !== 'totals' || abs($pair[0]['line'] - $pair[1]['line']) <= 0.001)) {
                    array_push($result, ...$pair);
                }
            }
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>>|null $quotes */
    public function marketIsFresh(Game $game, ?array $quotes = null): bool
    {
        $quotes ??= $this->quotes($game);

        return count($this->freshPairedSpreadQuotes($quotes)) >= 2;
    }

    /**
     * @param  array<int, array<string, mixed>>  $quotes
     * @return array<int, array<string, mixed>>
     */
    private function freshPairedSpreadQuotes(array $quotes): array
    {
        $freshAfter = now()->subMinutes(max(1, (int) config('nfl_research.market_freshness_minutes', 60)));
        $fresh = collect($quotes)->filter(function (array $quote) use ($freshAfter): bool {
            if (! in_array($quote['side'] ?? null, ['home', 'away'], true)
                || ! filled($quote['bookmaker'] ?? null)
                || ! is_numeric($quote['line'] ?? null)
                || ! is_numeric($quote['price'] ?? null)
                || ! is_string($quote['observed_at'] ?? null)
                || trim((string) $quote['observed_at']) === '') {
                return false;
            }

            try {
                return Carbon::parse((string) $quote['observed_at'])->betweenIncluded($freshAfter, now());
            } catch (Throwable) {
                return false;
            }
        });

        return $fresh
            ->groupBy('bookmaker')
            ->flatMap(function ($bookQuotes) {
                $home = $bookQuotes->where('side', 'home');
                $away = $bookQuotes->where('side', 'away');

                return $home->flatMap(function (array $homeQuote) use ($away) {
                    $opposite = $away->first(fn (array $awayQuote): bool => abs(
                        (float) $homeQuote['line'] + (float) $awayQuote['line'],
                    ) <= 0.001);

                    return $opposite === null ? [] : [$homeQuote, $opposite];
                });
            })
            ->unique(fn (array $quote): string => implode('|', [
                $quote['bookmaker'],
                $quote['side'],
                $quote['line'],
                $quote['price'],
                $quote['observed_at'],
            ]))
            ->values()
            ->all();
    }

    public function grade(ResearchRevision $revision): bool
    {
        $game = Game::find($revision->game_id);
        if (! $game || $game->status !== 'STATUS_FINAL' || $game->home_score === null || $game->away_score === null) {
            return false;
        }
        $margin = $game->home_score - $game->away_score;
        $total = $game->home_score + $game->away_score;
        $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        $closingQuotes = $kickoff ? MarketQuote::query()->where('sport', 'nfl')->where('game_id', $game->id)
            ->where('market_key', 'spreads')->where('is_pregame', true)
            ->where('captured_at', '<', $kickoff)->where('captured_at', '>=', $kickoff->copy()->subMinutes(30))
            ->orderByDesc('captured_at')->orderByDesc('id')->get()
            ->unique(fn (MarketQuote $quote): string => $quote->bookmaker_key.':'.$quote->side)
            ->keyBy(fn (MarketQuote $quote): string => $quote->bookmaker_key.':'.$quote->side) : collect();
        $playerProps = PlayerProp::query()->whereIn('id', collect($revision->brief['player_props'] ?? [])->pluck('prop_id'))->get()->keyBy('id');
        $results = [];
        foreach (['baseline', 'revised'] as $variant) {
            $p = $revision->$variant;
            if (! isset($p['predicted_spread'], $p['predicted_total'], $p['win_probability'])) {
                continue;
            }
            $prob = (float) $p['win_probability'];
            $results[$variant] = ['spread_absolute_error' => abs($p['predicted_spread'] - $margin), 'total_absolute_error' => abs($p['predicted_total'] - $total), 'brier' => $margin === 0 ? null : ($prob - ($margin > 0 ? 1 : 0)) ** 2, 'quotes' => []];
            foreach ($revision->market as $q) {
                $homeLine = $q['side'] === 'home' ? $q['line'] : -$q['line'];
                $side = $p['predicted_spread'] + $homeLine > 0 ? 'home' : 'away';
                if ($side !== $q['side'] || $p['predicted_spread'] + $homeLine == 0 || $q['price'] == 0) {
                    continue;
                }
                $cover = ($side === 'home' ? $margin : -$margin) + $q['line'];
                $profit = $cover == 0 ? 0 : ($cover > 0 ? ($q['price'] > 0 ? $q['price'] / 100 : 100 / abs($q['price'])) : -1);
                $closing = $closingQuotes->get($q['bookmaker'].':'.$side);
                $results[$variant]['quotes'][] = [...$q, 'closing_line' => $closing?->line, 'closing_quote_id' => $closing?->id, 'clv_points' => $closing ? $q['line'] - (float) $closing->line : null, 'result' => $cover == 0 ? 'push' : ($cover > 0 ? 'win' : 'loss'), 'hypothetical_units' => $profit];
            }
        }
        $props = [];
        foreach ($revision->brief['player_props'] ?? [] as $snapshot) {
            $prop = $playerProps->get($snapshot['prop_id']);
            if (! $prop?->graded_at || $prop->actual_value === null) {
                $props[] = ['prop_id' => $snapshot['prop_id'], 'status' => 'pending_player_stats'];

                continue;
            }
            $variants = [];
            foreach (['baseline' => 'recommended_side', 'revised' => 'recommendation'] as $variant => $key) {
                $side = strtolower((string) data_get($snapshot, $variant.'.'.$key));
                if (! in_array($side, ['over', 'under'], true)) {
                    continue;
                }
                $price = $snapshot[$side.'_price'];
                if (! $price) {
                    continue;
                }
                $diff = ((float) $prop->actual_value - $snapshot['line']) * ($side === 'over' ? 1 : -1);
                $variants[$variant] = ['result' => $diff == 0 ? 'push' : ($diff > 0 ? 'win' : 'loss'), 'hypothetical_units' => $diff == 0 ? 0 : ($diff < 0 ? -1 : ($price > 0 ? $price / 100 : 100 / abs($price)))];
            }
            $props[] = ['prop_id' => $snapshot['prop_id'], 'status' => 'graded', 'actual_value' => $prop->actual_value, 'variants' => $variants];
        }
        $propsPending = collect($props)->contains(fn (array $prop): bool => ($prop['status'] ?? null) === 'pending_player_stats');
        $revision->update(['evaluation' => ['player_props' => $props, 'actual_margin' => $margin, 'actual_total' => $total, 'variants' => $results, 'eligible_at_capture' => data_get($revision->brief, 'eligibility.eligible', false), 'clv_status' => 'per_quote_when_matching_book_pregame_quote_within_30_minutes_exists', 'note' => 'Hypothetical one-unit returns per quote; not placed bets. Use one revision per game for aggregate evaluation.'], 'graded_at' => $propsPending ? null : now()]);

        return ! $propsPending;
    }
}
