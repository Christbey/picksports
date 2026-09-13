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
use Illuminate\Support\Facades\Cache;

class ResearchPipeline
{
    public function review(Game $game, bool $research = true): ?ResearchRevision
    {
        return Cache::lock('nfl-research-game:'.$game->id, 300)->get(function () use ($game, $research) {
            $game->loadMissing(['homeTeam', 'awayTeam', 'prediction']);
            $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
            if (! $kickoff || $kickoff->lte(now()) || $game->status !== 'STATUS_SCHEDULED') {
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
            $ids = array_column(app(EvidencePacket::class)->researchDocuments($packet), 'id');
            $changed = $report && ($ids !== data_get($report->raw_payload, 'document_ids', []) || hash('sha256', json_encode($preview['outputs'])) !== data_get($report->raw_payload, 'candidate_hash'));
            if ($research && (! $report || ($report->status !== 'ready' && $report->researched_at->lt(now()->subMinutes(45))) || ! $report->expires_at || $report->expires_at->lte(now()) || $changed)) {
                $report = app(NflWebContextResearchService::class)->research($game)['report'];
            }
            $holds = $packet['holds'];
            if (! $report || $report->status !== 'ready' || ! $report->expires_at || $report->expires_at->lte(now()) || (! $research && $changed)) {
                $holds[] = 'research_incomplete_or_stale';
            }
            if (! $game->prediction) {
                $holds[] = 'original_prediction_missing';
            }
            if (data_get($preview, 'model_metadata.true_epa.reason') === 'missing_net_true_epa') {
                $holds[] = 'missing_true_epa';
            }
            if (! $game->odds_updated_at || $game->odds_updated_at->lt(now()->subMinutes(30))) {
                $holds[] = 'market_stale';
            }
            $decision = data_get($report?->raw_payload, 'decision_research', []);
            if (empty($decision['supporting']) || empty($decision['opposing'])) {
                $holds[] = 'two_sided_research_missing';
            }
            if (! empty($decision['unresolved'])) {
                $holds[] = 'unresolved_research_questions';
            }
            $analysis = data_get($preview, 'model_metadata.analysis_layer', []);
            $eligibility = app(RecommendationEligibility::class)->evaluate($analysis, $preview['model_metadata'], $holds);
            $original = ResearchRevision::where('game_id', $game->id)->oldest('id')->first();
            $baseline = $original?->baseline ?? $game->prediction?->only(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score', 'model_version', 'updated_at']) ?? [];
            $market = $this->quotes($game);
            if ($market === []) {
                $eligibility = app(RecommendationEligibility::class)->evaluate($analysis, $preview['model_metadata'], [...$holds, 'spread_quote_missing']);
            }
            $props = app(PlayerPropAnalyzer::class)->previewNflGame($game);
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
                'facts' => $report?->facts ?? [],
                'risk_flags' => $report?->risk_flags ?? [],
                'model_signals' => ['qb' => data_get($preview, 'model_metadata.qb_form'), 'trenches' => data_get($preview, 'model_metadata.line_matchup'), 'injuries' => data_get($preview, 'model_metadata.depth_chart_injuries')],
                'numeric_adjustment_policy' => 'Existing model weights only; narrative interpretation is not a calibrated adjustment.',
                'source_report_id' => $report?->id,
                'previous_revision_id' => $previous?->id,
                'change' => $previous ? ['spread' => round($preview['outputs']['predicted_spread'] - ($previous->revised['predicted_spread'] ?? 0), 2), 'total' => round($preview['outputs']['predicted_total'] - ($previous->revised['predicted_total'] ?? 0), 2), 'eligibility_changed' => data_get($previous->brief, 'eligibility.status') !== $eligibility['status']] : ['initial' => true],
            ];
            $evidence = ['documents' => array_map(fn ($d) => array_diff_key($d, ['text' => true]), $packet['documents']), 'availability' => $packet['availability'], 'report_id' => $report?->id];
            $hash = hash('sha256', json_encode([$baseline, $preview['outputs'], $preview['model_version'], $evidence, $market, $eligibility, $props]));

            return ResearchRevision::firstOrCreate(['game_id' => $game->id, 'input_hash' => $hash], ['report_id' => $report?->id, 'baseline' => $baseline, 'revised' => [...$preview['outputs'], 'model_version' => $preview['model_version']], 'evidence' => $evidence, 'brief' => $brief, 'market' => $market, 'created_at' => now()]);
        });
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

        return $quotes;
    }

    public function grade(ResearchRevision $revision): bool
    {
        $game = Game::find($revision->game_id);
        if (! $game || $game->status !== 'STATUS_FINAL' || $game->home_score === null || $game->away_score === null) {
            return false;
        }
        $margin = $game->home_score - $game->away_score;
        $total = $game->home_score + $game->away_score;
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
                $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
                $closing = $kickoff ? MarketQuote::where('sport', 'nfl')->where('game_id', $game->id)->where('market_key', 'spreads')->where('bookmaker_key', $q['bookmaker'])->where('side', $side)->where('is_pregame', true)->where('captured_at', '<', $kickoff)->where('captured_at', '>=', $kickoff->subMinutes(30))->latest('captured_at')->first() : null;
                $results[$variant]['quotes'][] = [...$q, 'closing_line' => $closing?->line, 'closing_quote_id' => $closing?->id, 'clv_points' => $closing ? $q['line'] - (float) $closing->line : null, 'result' => $cover == 0 ? 'push' : ($cover > 0 ? 'win' : 'loss'), 'hypothetical_units' => $profit];
            }
        }
        $props = [];
        foreach ($revision->brief['player_props'] ?? [] as $snapshot) {
            $prop = PlayerProp::find($snapshot['prop_id']);
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
        $revision->update(['evaluation' => ['player_props' => $props, 'actual_margin' => $margin, 'actual_total' => $total, 'variants' => $results, 'eligible_at_capture' => data_get($revision->brief, 'eligibility.eligible', false), 'clv_status' => 'per_quote_when_matching_book_pregame_quote_within_30_minutes_exists', 'note' => 'Hypothetical one-unit returns per quote; not placed bets. Use one revision per game for aggregate evaluation.'], 'graded_at' => now()]);

        return true;
    }
}
