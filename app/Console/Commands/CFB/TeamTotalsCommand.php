<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\CFB\TeamTotals\HistoricalTeamTotalProjector;
use Illuminate\Console\Command;

class TeamTotalsCommand extends Command
{
    protected $signature = 'cfb:team-totals {--date= : Central slate date} {--backtest-season= : Walk-forward evaluation season}';

    protected $description = 'Read-only historical team score projections or chronological validation';

    public function handle(HistoricalTeamTotalProjector $model): int
    {
        $season = $this->option('backtest-season');
        if ($season !== null && (! ctype_digit($season) || (int) $season < 2024 || (int) $season > (int) date('Y'))) {
            $this->error('Provide a historical season from 2024 through the current year.');

            return self::FAILURE;
        }
        $date = $this->option('date') ?? now('America/Chicago')->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $this->error('Provide a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }
        $query = $season ? Game::where('season', $season)->where('status', 'STATUS_FINAL')->whereDate('game_date', '<', now('America/Chicago')->toDateString()) : CfbPropEligibility::onDate(Game::query(), $date);
        $games = $query->with(['homeTeam', 'awayTeam'])->orderBy('game_date')->orderBy('game_time')->get();
        $errors = [];
        $baselineErrors = [];
        $bias = [];
        $skipped = [];
        foreach ($games as $game) {
            $projection = $model->project($game);
            if (! $season) {
                $this->line(json_encode(['game_id' => $game->id, 'home' => $game->homeTeam?->school, 'away' => $game->awayTeam?->school, ...$projection], JSON_THROW_ON_ERROR));

                continue;
            }
            if ($projection['status'] !== 'experimental' || $game->home_score === null || $game->away_score === null) {
                $skipped[$projection['status']] = ($skipped[$projection['status']] ?? 0) + 1;

                continue;
            }
            foreach (['home', 'away'] as $side) {
                $actual = $game->{$side.'_score'};
                $estimate = $projection[$side.'_points'];
                $errors[] = abs($estimate - $actual);
                $bias[] = $estimate - $actual;
                $baselineErrors[] = abs($projection['league_points'] - $actual);
            }
        }
        if ($season) {
            $this->line(json_encode(['season' => (int) $season, 'games' => count($errors) / 2, 'skipped' => $skipped,
                'team_points_mae' => count($errors) ? round(array_sum($errors) / count($errors), 3) : null,
                'league_mean_mae' => count($errors) ? round(array_sum($baselineErrors) / count($errors), 3) : null,
                'team_points_bias' => count($errors) ? round(array_sum($bias) / count($errors), 3) : null,
                'note' => 'Chronological score validation; no historical team-total odds or calibrated betting win rate.'], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
