<?php

namespace App\Console\Commands\CFB;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\MarketQuote;
use App\Services\CFB\Predictions\CfbStoredPregameQuote;
use App\Services\CFB\Scoring\CfbScoreDistribution;
use Illuminate\Console\Command;

class ScoringReportCommand extends Command
{
    protected $signature = 'cfb:scoring-report {--game=} {--bookmaker=}';

    protected $description = 'Report frozen team points and price-specific probabilities without approving bets';

    public function handle(): int
    {
        $game = Game::find($this->option('game'));
        if (! $game) {
            return self::FAILURE;
        }
        $prediction = CanonicalPrediction::where('sport_event_id', $game->sport_event_id)->where('publication_state', 'published')->with('calculationRun.inputSnapshot')->latest('revision')->first();
        $frozen = data_get($prediction?->calculationRun?->inputSnapshot?->inputs, 'scoring_challenger');
        if (($frozen['state'] ?? '') !== 'ready') {
            $this->line(json_encode(['game_id' => $game->id, 'state' => $frozen['state'] ?? 'unavailable', 'reason' => $frozen['reason'] ?? 'no_frozen_challenger']));

            return 2;
        }
        $distribution = new CfbScoreDistribution($frozen['scores'], $frozen['summary']['draws'], ($frozen['summary']['probability_standard_error_max'] ?? null) === null);
        $priced = [];
        $quotes = MarketQuote::where('sport', 'cfb')->where('game_id', $game->id)->where('is_pregame', true)
            ->where('captured_at', '<=', now())->where('created_at', '<=', now())->where('captured_at', '>', now()->subMinutes(30))->where('captured_at', '<', $game->sportEvent->starts_at)
            ->where('created_at', '<', $game->sportEvent->starts_at)->when($this->option('bookmaker'), fn ($q, $v) => $q->where('bookmaker_key', $v))->latest('id')->get();
        $seen = [];
        if ($game->sportEvent->starts_at->isFuture()) {
            foreach ($quotes as $quote) {
                $market = match ($quote->market_key) {
                    'spreads' => 'spread','totals' => 'total','h2h' => 'moneyline','team_totals' => 'team_total',default => null
                };
                if (! $market || ! is_numeric($quote->price) || abs((int) $quote->price) < 100 || ($market !== 'moneyline' && $quote->line === null)) {
                    continue;
                }
                $participant = data_get($quote->metadata, 'participant_side');
                if ($market === 'team_total' && ! in_array($participant, ['home', 'away'], true)) {
                    continue;
                }
                $key = CfbStoredPregameQuote::identity($quote);
                if (isset($seen[$key])) {
                    continue;
                } $seen[$key] = true;
                $priced[] = ['quote_id' => $quote->id, 'bookmaker' => $quote->bookmaker_key, 'market' => $market, 'side' => $quote->side, 'participant' => $participant, 'line' => $quote->line, 'price' => $quote->price,
                    'captured_at' => $quote->captured_at->toIso8601String(), ...$distribution->priced($market, $quote->side, (float) $quote->line, (int) $quote->price, $participant)];
            }
        }
        $this->line(json_encode(['game_id' => $game->id, 'forecast_generated_at' => $prediction->generated_at->toIso8601String(), 'artifact_id' => $frozen['artifact_id'],
            'summary' => $frozen['summary'], 'markets' => $priced, 'recommendations_approved' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
