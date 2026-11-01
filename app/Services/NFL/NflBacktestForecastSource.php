<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Support\NflModelVersion;
use Illuminate\Support\Collection;

class NflBacktestForecastSource
{
    /** @return Collection<int, object> */
    public function forMarket(string $market, ?int $season = null, ?int $fromSeason = null, ?int $toSeason = null, ?string $version = null, int $limit = 0): Collection
    {
        if (! in_array($market, ['spread', 'total', 'moneyline'], true)) {
            throw new \InvalidArgumentException('Backtest market must be spread, total or moneyline.');
        }
        $rows = collect();
        $query = Game::query()->select(['id', 'sport_event_id', 'home_team_id', 'away_team_id', 'home_score', 'away_score'])
            ->with(['sportEvent:id,starts_at', 'homeTeam:id,abbreviation', 'awayTeam:id,abbreviation'])
            ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->whereHas('sportEvent', fn ($query) => $query->where('starts_at', '<', now()))
            ->when($season !== null, fn ($query) => $query->where('season', $season))
            ->when($fromSeason !== null, fn ($query) => $query->where('season', '>=', $fromSeason))
            ->when($toSeason !== null, fn ($query) => $query->where('season', '<=', $toSeason));
        foreach ($query->lazyById(250)->chunk(25) as $batch) {
            $games = $batch->collect()->keyBy('id');
            foreach (app(NflEvidenceForecastSource::class)->forGames($games, $version ?? NflModelVersion::current(), includeMetadata: $market !== 'spread') as $forecast) {
                $game = $games->get($forecast->game_id);
                if (! $forecast->game_start_at->eq($game->sportEvent?->starts_at)) {
                    continue;
                }
                $forecast->game = $game;
                $forecast->confidence_score = $this->number($forecast->outputs['confidence_score'] ?? null);
                if ($market === 'moneyline') {
                    $probability = $this->number($forecast->outputs['win_probability'] ?? null);
                    $quote = $forecast->market_context['moneyline'] ?? [];
                    $home = $this->number($quote['price'] ?? null);
                    $away = $this->number(data_get($quote, 'opposite_quote.price'));
                    $trust = $this->number(data_get($forecast->model_metadata, 'analysis_layer.trust_score')) ?? $forecast->confidence_score;
                    if ($probability === null || $probability < 0 || $probability > 1 || $trust === null
                        || $home === null || $away === null || abs($home) < 100 || abs($away) < 100
                        || ($quote['market_key'] ?? null) !== 'h2h' || ($quote['side'] ?? null) !== 'home'
                        || data_get($quote, 'opposite_quote.market_key') !== 'h2h' || data_get($quote, 'opposite_quote.side') !== 'away') {
                        continue;
                    }
                    $forecast->projection = $probability;
                    $forecast->prices = ['home' => $home, 'away' => $away];
                    $forecast->trust_score = $trust;
                } else {
                    $projection = $this->number($forecast->outputs['predicted_'.$market] ?? null);
                    $line = $this->number($forecast->market_context[$market === 'spread' ? 'market_home_margin' : 'market_total'] ?? null);
                    if ($projection === null || $line === null) {
                        continue;
                    }
                    $forecast->projection = $projection;
                    $forecast->market_line = $market === 'spread' ? -$line : $line;
                }
                $rows->push($forecast);
            }
        }
        $rows = $rows->sortByDesc(fn (object $row) => [$row->generated_at->timestamp, $row->canonical_prediction_id])->values();

        return $limit > 0 ? $rows->take($limit)->values() : $rows;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
