<?php

namespace App\Services\NFL\Research;

use App\Models\NFL\Game;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Collection;

class ResearchAnalysisContext
{
    public const VERSION = 2;

    public function forGame(Game $game, array $candidate, array $quarterbacks = []): array
    {
        $dates = app(SportsDateWindowService::class);
        $kickoff = $dates->gameDateTimeUtc($game->game_date, $game->game_time);
        $cutoff = $kickoff?->min(now()->utc());
        $games = $cutoff ? Game::with('teamStats')
            ->where('season', $game->season)->where('season_type', 2)
            ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->where('game_date', '<=', $cutoff->toDateString())
            ->where(fn ($q) => $q->whereIn('home_team_id', [$game->home_team_id, $game->away_team_id])
                ->orWhereIn('away_team_id', [$game->home_team_id, $game->away_team_id]))
            ->orderByDesc('game_date')->orderByDesc('game_time')->orderByDesc('id')
            ->get()->filter(fn ($g) => $dates->gameDateTimeUtc($g->game_date, $g->game_time)?->lt($cutoff)) : collect();
        $teams = [];
        foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $side => $id) {
            $rows = $games->filter(fn ($g) => in_array($id, [$g->home_team_id, $g->away_team_id]));
            $stats = $rows->flatMap(fn ($g) => $g->teamStats->where('team_id', $id))->unique('game_id');
            $teams[$side] = [
                'team_id' => $id,
                'team' => $game->{$side.'Team'}?->abbreviation,
                ...$this->scoringSample($rows, $id),
                'last_five' => $this->scoringSample($rows->take(5), $id),
                'same_venue_role' => $game->neutral_site ? null : $this->scoringSample(
                    $rows->filter(fn ($prior) => ! $prior->neutral_site && $prior->{$side.'_team_id'} === $id),
                    $id,
                ),
                'stat_coverage_games' => $stats->unique('game_id')->count(),
            ];
            foreach (['total_yards', 'passing_yards', 'rushing_yards', 'sacks_allowed', 'interceptions', 'fumbles_lost'] as $field) {
                $values = $stats->pluck($field)->filter(fn ($v) => is_numeric($v));
                $teams[$side][$field] = ['per_game' => $values->isEmpty() ? null : round($values->avg(), 2), 'sample_size' => $values->count()];
            }
        }

        return [
            'version' => self::VERSION,
            'forecast_semantics' => 'Final predicted_spread is expected HOME scoring margin: positive means home wins. Bookmaker home handicap has the opposite sign. Intermediate blends are not competing forecasts.',
            'final_forecast' => collect($candidate)->only(['predicted_spread', 'predicted_total', 'win_probability'])->all(),
            'market_blended' => (bool) data_get($candidate, 'model_metadata.market_blend.applied', false),
            'season_evidence' => ['season' => $game->season, 'season_type' => 2, 'cutoff_utc' => $cutoff?->toIso8601String(), 'teams' => $teams,
                'limitations' => ['Descriptive completed-game results; not opponent adjusted or calibrated cover probabilities.', 'Missing statistics remain null; coverage and game IDs are provided.', 'Earlier results may involve different quarterbacks or personnel.']],
            'quarterbacks' => $quarterbacks,
            'source_precedence' => 'Use effective event time before retrieval time. Return-to-practice/designated-for-return is not activation. Fresh reserve status controls availability until a later explicit activation. Preserve genuinely contradictory effective events.',
        ];
    }

    private function scoringSample(Collection $rows, int $teamId): array
    {
        $scores = $rows->map(fn ($game) => [
            'for' => $game->home_team_id === $teamId ? $game->home_score : $game->away_score,
            'against' => $game->home_team_id === $teamId ? $game->away_score : $game->home_score,
        ]);

        return [
            'game_ids' => $rows->pluck('id')->values()->all(),
            'games' => $rows->count(),
            'first_game_date' => $rows->last()?->game_date?->toDateString(),
            'last_game_date' => $rows->first()?->game_date?->toDateString(),
            'wins' => $scores->filter(fn ($score) => $score['for'] > $score['against'])->count(),
            'losses' => $scores->filter(fn ($score) => $score['for'] < $score['against'])->count(),
            'ties' => $scores->filter(fn ($score) => $score['for'] === $score['against'])->count(),
            'points_per_game' => $scores->isEmpty() ? null : round($scores->avg('for'), 2),
            'points_allowed_per_game' => $scores->isEmpty() ? null : round($scores->avg('against'), 2),
            'scoring_margin' => $scores->isEmpty() ? null : round($scores->avg('for') - $scores->avg('against'), 2),
        ];
    }
}
