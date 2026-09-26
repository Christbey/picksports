<?php

namespace App\Console\Commands\CFB;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\PlayerProp;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DailyBoardCommand extends Command
{
    protected $signature = 'cfb:daily-board {--date= : Central game date; defaults to today}';

    protected $description = 'Publish one timestamped daily report containing CFB forecasts, eligible player props, and coverage gaps';

    public function handle(PlayerPropAnalyzer $analyzer): int
    {
        $date = $this->option('date') ?? now('America/Chicago')->toDateString();
        if (Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->fails()) {
            $this->error('A valid Central game date is required.');

            return self::FAILURE;
        }
        $games = CfbPropEligibility::onDate(Game::with(['homeTeam', 'awayTeam']), $date)->get();
        $predictions = CanonicalPrediction::with('markets')->where('sport', 'cfb')->where('phase', 'pregame')
            ->where('publication_state', 'published')->whereIn('sport_event_id', $games->pluck('sport_event_id')->filter())
            ->orderByDesc('revision')->get()->unique('sport_event_id')->keyBy('sport_event_id');
        $quotes = PlayerProp::whereIn('game_id', $games->modelKeys())->where('is_current', true)->get();
        $recommendations = $analyzer->precomputedRecommendations(sport: 'CFB', dateFilter: $date, limit: 150);
        $props = $recommendations->map(function (array $row): array {
            $prop = $row['prop'];

            return ['quote_id' => $prop->id, 'game_id' => $prop->game_id, 'player_id' => $prop->player_id,
                'player' => $prop->player_name, 'market' => $prop->market, 'side' => $row['recommendation'],
                'line' => (float) $prop->line, 'price' => $row['odds'], 'bookmaker' => $prop->bookmaker,
                'fetched_at' => $prop->fetched_at?->toIso8601String(),
                'source_updated_at' => data_get($prop->raw_data, 'last_update'),
                'score' => $row['confidence'], 'score_is_probability' => false,
                'evidence' => data_get($prop->confidence_decomposition, 'cfb_context.evidence'),
                'cover_record' => data_get($prop->confidence_decomposition, 'cover_record')];
        })->values();
        $board = ['date' => $date, 'timezone' => 'America/Chicago', 'generated_at' => now()->toIso8601String(),
            'probability_status' => 'uncalibrated',
            'coverage' => ['games' => $games->count(), 'forecasts' => $predictions->count(),
                'current_prop_quotes' => $quotes->count(), 'linked_prop_quotes' => $quotes->whereNotNull('player_id')->count(),
                'fresh_prop_quotes' => $quotes->filter(fn ($p) => $p->fetched_at?->gte(now()->subMinutes(90)))->count(),
                'games_with_prop_quotes' => $quotes->pluck('game_id')->unique()->count(),
                'latest_prop_fetch' => $quotes->max('fetched_at')?->toIso8601String(),
                'eligible_props_in_report' => $props->count(), 'prop_report_limit' => 150,
                'hold_reasons' => $quotes->flatMap(fn ($p) => data_get($p->confidence_decomposition, 'analysis_disposition.reason_codes', []))->countBy()->all()],
            'games' => $games->map(function (Game $game) use ($predictions, $quotes, $props): array {
                $prediction = $predictions->get($game->sport_event_id);
                $markets = $prediction?->markets;
                $spread = $markets?->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home')?->projected_line;
                $total = $markets?->firstWhere('market_type', 'total')?->projected_line;
                $count = $quotes->where('game_id', $game->id)->count();

                return ['game_id' => $game->id, 'away' => $game->awayTeam?->school, 'home' => $game->homeTeam?->school,
                    'kickoff' => CfbPropEligibility::kickoff($game)->setTimezone('America/Chicago')->toIso8601String(),
                    'status' => $game->status, 'prediction_id' => $prediction?->id,
                    'forecast_generated_at' => $prediction?->generated_at?->toIso8601String(),
                    'home_spread' => $spread === null ? null : (float) $spread,
                    'total' => $total === null ? null : (float) $total,
                    'away_points' => $spread === null || $total === null ? null : round(($total + $spread) / 2, 1),
                    'home_points' => $spread === null || $total === null ? null : round(($total - $spread) / 2, 1),
                    'input_quality' => data_get($prediction?->output_metadata, 'input_quality'),
                    'prop_quote_count' => $count, 'eligible_prop_count' => $props->where('game_id', $game->id)->count(),
                    'prop_status' => $count === 0 ? 'no_current_quotes' : ($props->where('game_id', $game->id)->isEmpty() ? 'no_eligible_recommendations' : 'available')];
            })->values()->all(), 'player_props' => $props->all()];
        $path = "cfb/reports/{$date}/daily-board.json";
        $disk = config('cfb.data.source_disk', 'local');
        if (! Storage::disk($disk)->put($path, json_encode($board, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))) {
            $this->error('Daily board could not be saved.');

            return self::FAILURE;
        }
        $this->line(json_encode(['disk' => $disk, 'path' => $path, ...$board], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
