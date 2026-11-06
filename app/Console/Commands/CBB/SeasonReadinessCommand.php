<?php

namespace App\Console\Commands\CBB;

use App\Models\CBB\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\CBB\CbbDataQuality;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Sports\SportsDateWindowService;
use App\Support\BasketballSeason;
use Illuminate\Console\Command;

class SeasonReadinessCommand extends Command
{
    protected $signature = 'cbb:season-readiness {--season=} {--date=} {--json}';

    protected $description = 'Report CBB game, box-score, forecast, and provenance coverage without fetching or changing data';

    public function handle(CbbDataQuality $quality, SportsDateWindowService $dates): int
    {
        $query = $quality->realGames(Game::query())->where('season', (int) ($this->option('season') ?: BasketballSeason::forDate()));
        $dates->applyGameDateWindow($query, $dates->forDate($this->option('date')));
        $games = (clone $query)->with(['sportEvent', 'prediction', 'teamStats'])->withCount(['teamStats', 'playerStats', 'plays'])->get();
        $upcoming = $games->filter(function (Game $game) use ($dates): bool {
            $start = $game->sportEvent?->starts_at ?? $dates->gameDateTimeUtc($game->getRawOriginal('game_date'), $game->game_time);

            return in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true) && $start !== null && $start->gt(now());
        });
        $finals = $games->where('status', 'STATUS_FINAL');
        $reconciled = $finals->filter(fn (Game $game): bool => $quality->boxScoreIssues($game, $game->teamStats) === [])->count();
        $snapshots = PredictionFeatureSnapshot::query()->where('sport', 'cbb')->where('prediction_table', 'cbb_predictions')
            ->whereIn('game_id', $games->pluck('id'))->orderByDesc('id')->get()->unique('prediction_id');
        $native = app(PredictionDeliverySource::class)->usesCanonical('cbb');
        $available = $native ? app(CanonicalSportPredictionQuery::class)->queryForSport('cbb')->reorder()->pluck('cbb_games.id')->all() : [];
        $marketCoverage = $native ? app(CanonicalSportPredictionQuery::class)->queryForSport('cbb')
            ->whereIn('cbb_games.id', $upcoming->pluck('id'))->get()
            ->map(fn ($prediction) => (array) data_get($prediction->calculationRun?->inputSnapshot?->inputs, 'pregame_market.market_coverage', [])) : collect();
        $missing = $upcoming->filter(fn (Game $game): bool => $native ? ! in_array($game->id, $available, true) : $game->prediction === null)->count();
        $issues = [];
        if ($missing > 0) {
            $issues[] = 'missing_upcoming_predictions';
        }
        if ($reconciled < $finals->count()) {
            $issues[] = 'unreconciled_final_box_scores';
        }
        if ($finals->where('player_stats_count', 0)->isNotEmpty()) {
            $issues[] = 'missing_final_player_stats';
        }
        if ($finals->where('plays_count', 0)->isNotEmpty()) {
            $issues[] = 'missing_final_plays';
        }
        $provisional = $snapshots->filter(fn ($snapshot): bool => (bool) data_get($snapshot->model_metadata, 'provisional', true))->count();
        $report = [
            'checked_at' => now()->toIso8601String(), 'date' => $dates->parseLocalDate($this->option('date'))->toDateString(),
            'season' => (int) ($this->option('season') ?: BasketballSeason::forDate()),
            'status' => $issues !== [] ? 'attention_required' : ($provisional > 0 ? 'ready_with_provisional_inputs' : 'ready'),
            'forecast_source' => $native ? 'canonical' : 'legacy',
            'games' => $games->count(), 'upcoming' => $upcoming->count(), 'upcoming_missing_predictions' => $missing,
            'finals' => $finals->count(), 'reconciled_finals' => $reconciled,
            'finals_without_player_stats' => $finals->where('player_stats_count', 0)->count(),
            'finals_without_plays' => $finals->where('plays_count', 0)->count(),
            'detail_failures' => $games->where('details_status', 'failed')->count(),
            'observed_pregame_snapshots' => $snapshots->where('pregame_safe', true)->count(),
            'reconstructed_snapshots' => $snapshots->filter(fn ($snapshot): bool => data_get($snapshot->lineage_metadata, 'run_type') === 'historical_reconstruction')->count(),
            'provisional_predictions' => $provisional,
            'upcoming_frozen_spread_lines' => $marketCoverage->where('spread', true)->count(),
            'upcoming_frozen_total_lines' => $marketCoverage->where('total', true)->count(),
            'upcoming_missing_odds' => $upcoming->whereNull('odds_updated_at')->count(),
            'upcoming_stale_odds' => $upcoming->filter(fn (Game $game): bool => $game->odds_updated_at !== null && $game->odds_updated_at->lt(now()->subHours(36)))->count(),
            'issues' => $issues,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Measure', 'Value'], collect($report)->map(fn ($value, $key): array => [$key, is_array($value) ? implode(', ', $value) : (string) $value])->all());
        }

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
