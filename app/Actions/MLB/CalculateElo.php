<?php

namespace App\Actions\MLB;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected const SPORT_KEY = 'mlb';

    protected const ELO_RATING_MODEL = EloRating::class;

    /**
     * Execute Elo calculation for MLB (handles both team and pitcher Elo)
     */
    public function execute(Model $game, bool $skipIfExists = true): array
    {
        if ($game->status !== 'STATUS_FINAL') {
            return [
                'home_team_change' => 0,
                'away_team_change' => 0,
                'home_pitcher_change' => 0,
                'away_pitcher_change' => 0,
                'skipped' => false,
            ];
        }

        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        if (! $homeTeam || ! $awayTeam) {
            return [
                'home_team_change' => 0,
                'away_team_change' => 0,
                'home_pitcher_change' => 0,
                'away_pitcher_change' => 0,
                'skipped' => false,
            ];
        }

        // Check if Elo has already been calculated for this game
        if ($skipIfExists && $this->eloAlreadyCalculated($game, $homeTeam, $awayTeam)) {
            return [
                'home_team_change' => 0,
                'away_team_change' => 0,
                'home_pitcher_change' => 0,
                'away_pitcher_change' => 0,
                'skipped' => true,
            ];
        }

        // Identify starting pitchers
        $homeStartingPitcher = $this->getStartingPitcher($game, $homeTeam);
        $awayStartingPitcher = $this->getStartingPitcher($game, $awayTeam);

        // Calculate team Elo changes
        $teamEloResult = $this->calculateTeamElo($game, $homeTeam, $awayTeam);

        // Calculate pitcher Elo changes (if pitchers exist)
        $pitcherEloResult = $this->calculatePitcherElo(
            $game,
            $homeStartingPitcher,
            $awayStartingPitcher,
            $teamEloResult['home_won']
        );

        return [
            'home_team_change' => $teamEloResult['home_change'],
            'away_team_change' => $teamEloResult['away_change'],
            'home_team_new_elo' => $teamEloResult['home_new_elo'],
            'away_team_new_elo' => $teamEloResult['away_new_elo'],
            'home_pitcher_change' => $pitcherEloResult['home_change'],
            'away_pitcher_change' => $pitcherEloResult['away_change'],
            'home_pitcher_new_elo' => $pitcherEloResult['home_new_elo'] ?? null,
            'away_pitcher_new_elo' => $pitcherEloResult['away_new_elo'] ?? null,
            'skipped' => false,
        ];
    }

    protected function getStartingPitcher(Game $game, Team $team): ?Player
    {
        // Find the pitcher with the most innings pitched for this team in this game
        $pitcherStat = PlayerStat::query()
            ->where('game_id', $game->id)
            ->where('team_id', $team->id)
            ->where('stat_type', 'pitching')
            ->whereNotNull('innings_pitched')
            ->orderByDesc('innings_pitched')
            ->first();

        return $pitcherStat?->player;
    }

    protected function calculateTeamElo(Game $game, Team $homeTeam, Team $awayTeam): array
    {
        // Get current Elo ratings
        $defaultElo = config('mlb.elo.default_rating');
        $homeElo = $homeTeam->elo_rating ?? $defaultElo;
        $awayElo = $awayTeam->elo_rating ?? $defaultElo;

        // Adjust for home field advantage
        $adjustedHomeElo = $homeElo + config('mlb.elo.home_field_advantage');

        // Calculate expected win probabilities
        $homeExpected = $this->calculateExpectedScore($adjustedHomeElo, $awayElo);
        $awayExpected = 1 - $homeExpected;

        // Determine actual scores (1 for win, 0 for loss)
        $homeWon = $game->home_score > $game->away_score;
        $homeActual = $homeWon ? 1 : 0;
        $awayActual = 1 - $homeActual;

        // Calculate K-factor with margin of victory and playoff multiplier
        $kFactor = $this->calculateKFactor($game);

        // Calculate Elo changes
        $homeChange = round($kFactor * ($homeActual - $homeExpected), 1);
        $awayChange = round($kFactor * ($awayActual - $awayExpected), 1);

        // Update team Elo ratings
        $newHomeElo = round($homeElo + $homeChange);
        $newAwayElo = round($awayElo + $awayChange);

        $homeTeam->update(['elo_rating' => $newHomeElo]);
        $awayTeam->update(['elo_rating' => $newAwayElo]);

        // Save Elo history
        $this->saveEloHistory($homeTeam, $game, $newHomeElo, $homeChange);
        $this->saveEloHistory($awayTeam, $game, $newAwayElo, $awayChange);

        return [
            'home_change' => $homeChange,
            'away_change' => $awayChange,
            'home_new_elo' => $newHomeElo,
            'away_new_elo' => $newAwayElo,
            'home_won' => $homeWon,
        ];
    }

    protected function calculatePitcherElo(
        Game $game,
        ?Player $homeStartingPitcher,
        ?Player $awayStartingPitcher,
        bool $homeWon
    ): array {
        $result = [
            'home_change' => 0,
            'away_change' => 0,
        ];

        // If either pitcher is missing, we can't calculate pitcher Elo
        if (! $homeStartingPitcher || ! $awayStartingPitcher) {
            return $result;
        }

        // Get current pitcher Elo ratings
        $defaultElo = config('mlb.elo.default_rating');
        $homeElo = $homeStartingPitcher->elo_rating ?? $defaultElo;
        $awayElo = $awayStartingPitcher->elo_rating ?? $defaultElo;

        // Pitcher Elo uses its own HFA (default 0 — pitchers don't get home advantage)
        $pitcherHfa = config('mlb.elo.pitcher_home_field_advantage');
        $adjustedHomeElo = $homeElo + $pitcherHfa;

        // Calculate expected win probabilities
        $homeExpected = $this->calculateExpectedScore($adjustedHomeElo, $awayElo);
        $awayExpected = 1 - $homeExpected;

        // Use game outcome (pitchers win/lose with their team)
        $homeActual = $homeWon ? 1 : 0;
        $awayActual = 1 - $homeActual;

        // Use pitcher-specific K-factor
        $kFactor = $this->calculatePitcherKFactor($game);

        // Calculate Elo changes
        $homeChange = round($kFactor * ($homeActual - $homeExpected), 1);
        $awayChange = round($kFactor * ($awayActual - $awayExpected), 1);

        // Update pitcher Elo ratings
        $newHomeElo = round($homeElo + $homeChange);
        $newAwayElo = round($awayElo + $awayChange);

        $homeStartingPitcher->update(['elo_rating' => $newHomeElo]);
        $awayStartingPitcher->update(['elo_rating' => $newAwayElo]);

        // Save pitcher Elo history with team context
        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;
        $this->savePitcherEloHistory($homeStartingPitcher, $game, $newHomeElo, $homeChange, $homeTeam);
        $this->savePitcherEloHistory($awayStartingPitcher, $game, $newAwayElo, $awayChange, $awayTeam);

        $result['home_change'] = $homeChange;
        $result['away_change'] = $awayChange;
        $result['home_new_elo'] = $newHomeElo;
        $result['away_new_elo'] = $newAwayElo;

        return $result;
    }

    protected function calculatePitcherKFactor(Model $game): float
    {
        $kFactor = config('mlb.elo.pitcher_k_factor');
        $kFactor = $this->applyPlayoffMultiplier($game, (float) $kFactor);

        // Apply dampened margin of victory multiplier
        $marginMultiplier = $this->calculateMarginMultiplier($game);
        $dampening = config('mlb.elo.pitcher_margin_dampening');
        $dampenedMultiplier = 1.0 + (($marginMultiplier - 1.0) * $dampening);
        $kFactor *= $dampenedMultiplier;

        return $kFactor;
    }

    protected function calculateKFactor(Model $game): float
    {
        return $this->calculateStandardKFactor($game);
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $game->season_type === config('mlb.season.types.postseason');
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $multipliers = config('mlb.elo.margin_multipliers', []);

        return $this->resolveMarginMultiplier($margin, $multipliers);
    }

    protected function savePitcherEloHistory(Player $pitcher, Game $game, int $newElo, float $eloChange, ?Team $team = null): void
    {
        // Count games started this season only
        $gamesStarted = PitcherEloRating::query()
            ->where('player_id', $pitcher->id)
            ->where('season', $game->season)
            ->count() + 1;

        PitcherEloRating::create([
            'player_id' => $pitcher->id,
            'team_id' => $team?->id,
            'game_id' => $game->id,
            'season' => $game->season,
            'date' => $game->game_date,
            'elo_rating' => $newElo,
            'elo_change' => $eloChange,
            'games_started' => $gamesStarted,
        ]);
    }

    /**
     * Apply offseason regression to mean for a team's Elo rating
     */
    public function applyTeamRegression(Team $team, ?float $regressionFactor = null): int
    {
        $regressionFactor ??= config('mlb.elo.team_regression_factor');
        $defaultElo = config('mlb.elo.default_rating');
        $currentElo = $team->elo_rating ?? $defaultElo;
        $regressedElo = round($currentElo + ($regressionFactor * ($defaultElo - $currentElo)));
        $team->update(['elo_rating' => $regressedElo]);

        return $regressedElo;
    }

    /**
     * Apply offseason regression to mean for a pitcher's Elo rating
     * Uses a stronger regression factor since pitcher performance is more volatile
     */
    public function applyPitcherRegression(Player $pitcher, ?float $regressionFactor = null): int
    {
        $regressionFactor ??= config('mlb.elo.pitcher_regression_factor');
        $defaultElo = config('mlb.elo.default_rating');
        $currentElo = $pitcher->elo_rating ?? $defaultElo;
        $regressedElo = round($currentElo + ($regressionFactor * ($defaultElo - $currentElo)));
        $pitcher->update(['elo_rating' => $regressedElo]);

        return $regressedElo;
    }
}
