<?php

namespace App\Services\CFB\Predictions;

use App\Models\CFB\Game;
use App\Models\CFB\TeamStat;
use App\Models\MarketQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** One frozen evidence bundle for many signals; overlapping windows are not independent samples. */
class CfbHistoricalSignalEvidenceBuilder
{
    public const SCHEMA = 'cfb-historical-signals-v1';

    public function build(Game $target, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt): array
    {
        $asOf = $capturedAt->min($cutoffAt);
        $teamIds = [(int) $target->home_team_id, (int) $target->away_team_id];
        $games = Game::query()->select(['id', 'sport_event_id', 'season', 'week', 'game_date', 'game_time',
            'home_team_id', 'away_team_id', 'home_score', 'away_score', 'neutral_site', 'conference_game', 'updated_at', 'created_at'])
            ->where('id', '!=', $target->id)->where('status', 'STATUS_FINAL')
            ->whereBetween('season', [$target->season - 3, $target->season])
            // A date-only historical record cannot establish same-day game completion before capture.
            ->whereDate('game_date', '<', $asOf->toDateString())
            ->where('created_at', '<=', $capturedAt)->where('created_at', '<', $cutoffAt)
            ->where('updated_at', '<=', $capturedAt)->where('updated_at', '<', $cutoffAt)
            ->whereNotNull('home_score')->whereNotNull('away_score')->whereColumn('home_score', '!=', 'away_score')
            ->where('home_score', '>=', 0)->where('away_score', '>=', 0)
            ->where(fn ($q) => $q->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds))
            ->with(['sportEvent' => fn ($q) => $q->select(['id', 'starts_at'])
                ->where('created_at', '<=', $capturedAt)->where('updated_at', '<=', $capturedAt)
                ->where('created_at', '<', $cutoffAt)->where('updated_at', '<', $cutoffAt)])->orderByDesc('game_date')->orderByDesc('game_time')->orderByDesc('id')->get();
        $stats = TeamStat::query()->whereIn('game_id', $games->modelKeys())
            ->where('created_at', '<=', $capturedAt)->where('created_at', '<', $cutoffAt)
            ->where('updated_at', '<=', $capturedAt)->where('updated_at', '<', $cutoffAt)
            ->get()->keyBy(fn ($stat) => $stat->game_id.':'.$stat->team_id);
        // Rank in SQL so years of line movement do not become thousands of hydrated quote models.
        $rankedQuotes = MarketQuote::query()->select(['market_quotes.id', 'market_quotes.game_id',
            'market_quotes.market_key', 'market_quotes.side', 'market_quotes.line', 'market_quotes.captured_at', 'market_quotes.created_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY market_quotes.game_id, market_quotes.market_key, market_quotes.side ORDER BY market_quotes.created_at DESC, market_quotes.id DESC) AS quote_rank')
            ->join('cfb_games as history_games', 'history_games.id', '=', 'market_quotes.game_id')
            ->join('sport_events as history_events', 'history_events.id', '=', 'history_games.sport_event_id')
            ->where('market_quotes.sport', 'cfb')->where('market_quotes.game_table', 'cfb_games')
            ->whereIn('market_quotes.game_id', $games->modelKeys())->where('market_quotes.is_pregame', true)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('market_key', 'spreads')->where('side', 'home'))
                ->orWhere(fn ($q) => $q->where('market_key', 'totals')->where('side', 'over')))
            ->where('market_quotes.created_at', '<=', $capturedAt)->where('market_quotes.captured_at', '<=', $capturedAt)
            ->where('market_quotes.created_at', '<', $cutoffAt)->where('market_quotes.captured_at', '<', $cutoffAt)
            ->where('history_events.created_at', '<=', $capturedAt)->where('history_events.updated_at', '<=', $capturedAt)
            ->where('history_events.created_at', '<', $cutoffAt)->where('history_events.updated_at', '<', $cutoffAt)
            ->whereColumn('market_quotes.created_at', '<', 'history_events.starts_at')
            ->whereColumn('market_quotes.captured_at', '<', 'history_events.starts_at');
        $quotes = MarketQuote::query()->fromSub($rankedQuotes, 'market_quotes')->where('quote_rank', 1)
            ->get()->groupBy('game_id');
        $result = ['schema_version' => self::SCHEMA, 'captured_at' => $capturedAt->toIso8601String(),
            'cutoff_at' => $cutoffAt->toIso8601String(), 'policy' => 'observed_prior_dates_only_last_stored_pregame_quotes',
            'historical_scope' => ['seasons' => [$target->season - 3, (int) $target->season], 'opponents' => 'all_stored_opponents', 'season_types' => 'regular_and_postseason'],
            'limitations' => ['same_day_results_excluded_without_completion_evidence', 'overlapping_windows_are_correlated',
                'missing_box_scores_or_closing_quotes_do_not_become_zero', 'descriptive_associations_not_causal_effects'],
            'latest_source_available_at' => null];
        $latest = null;
        foreach (['home' => $teamIds[0], 'away' => $teamIds[1]] as $side => $teamId) {
            $teamGames = $games->filter(fn ($game) => (int) $game->home_team_id === $teamId || (int) $game->away_team_id === $teamId);
            $records = $teamGames->map(fn ($game) => $this->record($game, $teamId, $stats, $quotes->get($game->id, collect())));
            $windows = ['current_season' => $records->where('season', $target->season),
                'prior_season' => $records->where('season', $target->season - 1), 'last3' => $records->take(3),
                'last5' => $records->take(5), 'last10' => $records->take(10),
                'home' => $records->where('home', true)->where('neutral', false),
                'away' => $records->where('home', false)->where('neutral', false),
                'neutral' => $records->where('neutral', true), 'conference' => $records->where('conference', true),
                'nonconference' => $records->where('conference', false)];
            $latestGame = $teamGames->first();
            $result[$side] = ['team_id' => $teamId, 'windows' => collect($windows)->map(fn ($sample) => $this->summarize($sample))->all(),
                'rest_days' => ['value' => $latestGame?->sportEvent?->starts_at
                    ? (int) floor($latestGame->sportEvent->starts_at->diffInSeconds($cutoffAt) / 86400) : null,
                    'sample_games' => $latestGame?->sportEvent?->starts_at ? 1 : 0, 'source_game_id' => $latestGame?->id,
                    'definition' => 'days_between_kickoffs_not_days_off']];
            foreach ($records as $record) {
                foreach ($record['values'] as $value) {
                    if ($value !== null && ($latest === null || $value['observed_at'] > $latest)) {
                        $latest = $value['observed_at'];
                    }
                }
            }
        }
        $result['latest_source_available_at'] = $latest;

        return $result;
    }

    private function record(Game $game, int $teamId, Collection $stats, Collection $quotes): array
    {
        $home = (int) $game->home_team_id === $teamId;
        $for = (float) ($home ? $game->home_score : $game->away_score);
        $against = (float) ($home ? $game->away_score : $game->home_score);
        $own = $stats->get($game->id.':'.$teamId);
        $opponent = $stats->get($game->id.':'.($home ? $game->away_team_id : $game->home_team_id));
        $observed = $game->updated_at->toIso8601String();
        $value = static fn ($numerator, $denominator = 1, $at = null, $source = null) => $numerator === null || $denominator === null || $denominator <= 0
            ? null : ['numerator' => (float) $numerator, 'denominator' => (float) $denominator, 'observed_at' => $at ?? $observed, 'source_id' => $source];
        $number = static fn ($row, $key) => is_numeric($row?->{$key}) ? (float) $row->{$key} : null;
        $sum = static fn (...$numbers) => in_array(null, $numbers, true) ? null : array_sum($numbers);
        $values = ['points_per_game' => $value($for), 'points_allowed_per_game' => $value($against),
            'margin_per_game' => $value($for - $against), 'total_points_per_game' => $value($for + $against),
            'win_rate' => $value($for > $against ? 1 : 0)];
        foreach (['passing_yards', 'rushing_yards', 'total_yards', 'sacks_allowed', 'penalty_yards', 'first_downs'] as $field) {
            $values[$field.'_per_game'] = $value($number($own, $field), 1, $own?->updated_at?->toIso8601String(), $own?->id);
        }
        foreach (['total_yards' => 'yards_allowed_per_game', 'passing_yards' => 'passing_yards_allowed_per_game', 'rushing_yards' => 'rushing_yards_allowed_per_game'] as $field => $metric) {
            $values[$metric] = $value($number($opponent, $field), 1, $opponent?->updated_at?->toIso8601String(), $opponent?->id);
        }
        foreach (['yards_per_play' => ['total_yards', ['passing_attempts', 'rushing_attempts']],
            'passing_yards_per_attempt' => ['passing_yards', ['passing_attempts']],
            'rushing_yards_per_attempt' => ['rushing_yards', ['rushing_attempts']],
            'completion_rate' => ['passing_completions', ['passing_attempts']],
            'third_down_rate' => ['third_down_conversions', ['third_down_attempts']],
            'fourth_down_rate' => ['fourth_down_conversions', ['fourth_down_attempts']],
            'red_zone_score_rate' => ['red_zone_scores', ['red_zone_attempts']]] as $metric => [$numerator, $denominators]) {
            $values[$metric] = $value($number($own, $numerator), $sum(...array_map(fn ($field) => $number($own, $field), $denominators)), $own?->updated_at?->toIso8601String(), $own?->id);
        }
        $values['opponent_yards_per_play'] = $value($number($opponent, 'total_yards'), $sum($number($opponent, 'passing_attempts'), $number($opponent, 'rushing_attempts')), $opponent?->updated_at?->toIso8601String(), $opponent?->id);
        $turnovers = $sum($number($own, 'interceptions'), $number($own, 'fumbles_lost'));
        $takeaways = $sum($number($opponent, 'interceptions'), $number($opponent, 'fumbles_lost'));
        $values['turnovers_per_game'] = $value($turnovers, 1, $own?->updated_at?->toIso8601String(), $own?->id);
        $values['takeaways_per_game'] = $value($takeaways, 1, $opponent?->updated_at?->toIso8601String(), $opponent?->id);
        $values['turnover_margin_per_game'] = $value($turnovers === null || $takeaways === null ? null : $takeaways - $turnovers,
            1, max($own?->updated_at?->toIso8601String() ?? '', $opponent?->updated_at?->toIso8601String() ?? ''), [$own?->id, $opponent?->id]);
        $kickoff = $game->sportEvent?->starts_at;
        $safeQuotes = $quotes->filter(fn ($quote) => $kickoff && $quote->created_at->lt($kickoff) && $quote->captured_at->lt($kickoff));
        $spread = $safeQuotes->first(fn ($quote) => $quote->market_key === 'spreads' && $quote->side === 'home');
        $total = $safeQuotes->first(fn ($quote) => $quote->market_key === 'totals' && $quote->side === 'over');
        $spreadResult = is_numeric($spread?->line) ? $for - $against + ($home ? (float) $spread->line : -(float) $spread->line) : null;
        $totalResult = is_numeric($total?->line) ? $for + $against - (float) $total->line : null;
        $spreadAt = $spread ? max($observed, $spread->created_at->toIso8601String(), $spread->captured_at->toIso8601String()) : null;
        $totalAt = $total ? max($observed, $total->created_at->toIso8601String(), $total->captured_at->toIso8601String()) : null;
        $values['ats_cover_rate'] = $value($spreadResult === null || $spreadResult == 0 ? null : ($spreadResult > 0 ? 1 : 0), 1, $spreadAt, $spread?->id);
        $values['ats_push_rate'] = $value($spreadResult === null ? null : ($spreadResult == 0 ? 1 : 0), 1, $spreadAt, $spread?->id);
        $values['over_rate'] = $value($totalResult === null || $totalResult == 0 ? null : ($totalResult > 0 ? 1 : 0), 1, $totalAt, $total?->id);
        $values['under_rate'] = $value($totalResult === null || $totalResult == 0 ? null : ($totalResult < 0 ? 1 : 0), 1, $totalAt, $total?->id);
        $values['total_push_rate'] = $value($totalResult === null ? null : ($totalResult == 0 ? 1 : 0), 1, $totalAt, $total?->id);

        return ['game_id' => $game->id, 'season' => (int) $game->season, 'home' => $home,
            'neutral' => (bool) $game->neutral_site, 'conference' => (bool) $game->conference_game, 'values' => $values];
    }

    private function summarize(Collection $records): array
    {
        // Keep a stable metric schema even for a completely empty window.
        $metricNames = ['points_per_game', 'points_allowed_per_game', 'margin_per_game', 'total_points_per_game', 'win_rate',
            'passing_yards_per_game', 'rushing_yards_per_game', 'total_yards_per_game', 'sacks_allowed_per_game', 'penalty_yards_per_game', 'first_downs_per_game',
            'yards_allowed_per_game', 'passing_yards_allowed_per_game', 'rushing_yards_allowed_per_game', 'yards_per_play', 'opponent_yards_per_play',
            'passing_yards_per_attempt', 'rushing_yards_per_attempt', 'completion_rate', 'third_down_rate', 'fourth_down_rate', 'red_zone_score_rate',
            'turnovers_per_game', 'takeaways_per_game', 'turnover_margin_per_game', 'ats_cover_rate', 'ats_push_rate', 'over_rate', 'under_rate', 'total_push_rate'];
        $metrics = [];
        foreach ($metricNames as $metric) {
            $sample = $records->filter(fn ($record) => ($record['values'][$metric] ?? null) !== null);
            $numerator = $sample->sum(fn ($record) => $record['values'][$metric]['numerator']);
            $denominator = $sample->sum(fn ($record) => $record['values'][$metric]['denominator']);
            $metrics[$metric] = ['value' => $denominator > 0 ? round($numerator / $denominator, 6) : null,
                'sample_games' => $sample->count(), 'window_games' => $records->count(),
                'status' => $sample->isEmpty() ? 'missing' : ($sample->count() < $records->count() ? 'partial' : 'observed'),
                'numerator' => $sample->isEmpty() ? null : $numerator, 'denominator' => $sample->isEmpty() ? null : $denominator,
                'game_ids' => $sample->pluck('game_id')->values()->all(),
                'source_ids' => $sample->map(fn ($record) => $record['values'][$metric]['source_id'])->filter()->values()->all(),
                'observed_at' => $sample->map(fn ($record) => $record['values'][$metric]['observed_at'])->max()];
        }

        return ['games' => $records->count(), 'game_ids' => $records->pluck('game_id')->values()->all(), 'metrics' => $metrics];
    }
}
