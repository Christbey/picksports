<?php

namespace App\Services\NFL;

use Carbon\Carbon;

class TeamPlayoffForecastService
{
    public const MODEL_VERSION = 'schedule_v6';

    public function __construct(
        protected NflPlayoffTeamInputs $teamInputs,
        protected NflPlayoffScoringHistory $scoringHistory,
        protected NflPostseasonGames $postseasonGames,
        protected NflPlayoffBracket $playoffBracket,
        protected NflPlayoffRegularSeason $regularSeason
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forecast(
        int $season,
        ?string $asOfDate = null,
        bool $requireHistoricalMetrics = false,
        ?int $simulations = null,
        ?int $seed = null
    ): array {
        $asOf = $asOfDate !== null ? Carbon::parse($asOfDate) : null;
        $cutoff = $asOf ?? now();
        $teams = $this->teamInputs->load($season, $asOf, $requireHistoricalMetrics);
        ['games' => $games, 'warnings' => $warnings] = $this->regularSeason->load($season, $teams, $asOf);
        if ($warnings !== []) {
            return NflPlayoffForecastReport::unavailable($season, $asOfDate, $warnings);
        }
        $postseason = $this->postseasonGames->atCutoff($season, $cutoff, array_column($teams, 'team_id'));
        $scoring = $this->scoringHistory->load($season, $cutoff, $games);
        $warnings = [
            'Uncalibrated model estimates. Future regular-season games use a league-wide tie frequency from stored results and historical score templates.',
            'NFL standings tiebreakers use available records and scoring data. Scoring steps with missing inputs are skipped; unresolved ties use a simulated coin toss.',
        ];
        if ($scoring['samples'] === [] && in_array(false, array_column($games, 'completed'), true)) {
            $warnings[] = 'No eligible historical results were available to estimate ties. Unplayed games use win or loss outcomes.';
        }
        $teamsById = array_column($teams, null, 'team_id');
        $games = array_map(function (array $game) use ($teamsById, $scoring): array {
            $home = $teamsById[$game['home']];
            $away = $teamsById[$game['away']];
            $game['touchdowns'] = $scoring['touchdowns'][$game['id']] ?? null;
            $game['home_probability'] = $this->gameProbability($home, $away, ! $game['neutral']);

            return $game;
        }, $games);

        $simulationCount = max(100, $simulations ?? (int) config('nfl.team_playoff_forecast.simulations', 5000));
        $randomSeed = $seed ?? (int) config('nfl.team_playoff_forecast.random_seed', 20260402);
        $random = new NflPlayoffRandom($randomSeed);

        $eliminated = (new NflPlayoffElimination)->eliminatedTeams($teams, $games);
        $report = new NflPlayoffForecastReport($teams, self::MODEL_VERSION, $eliminated);
        $simulateGame = function (array $first, array $second, bool $useHomeField, bool $firstIsHome) use ($random): array {
            $probability = $this->gameProbability($first, $second, $useHomeField, $firstIsHome);

            return $random->wins($probability) ? $first : $second;
        };
        for ($i = 0; $i < $simulationCount; $i++) {
            $sampled = $this->regularSeason->sample($teams, $games, $scoring['samples'], $random);
            $standings = (new NflPlayoffTiebreaker($sampled, $random))->seedPlayoffs();
            $bracket = $this->playoffBracket->resolve($standings['fields'], $postseason, $simulateGame);
            $report->record($sampled, $standings['teams'], $bracket);
        }

        return $report->build(
            $season, $asOfDate, $requireHistoricalMetrics, $simulationCount, $postseason, $scoring['tie_model'], $warnings
        );
    }

    protected function gameProbability(array $teamA, array $teamB, bool $useHomeField, bool $teamAHome = true): float
    {
        $homeField = $useHomeField ? (float) config('nfl.team_playoff_forecast.playoff_home_field_advantage', 0.35) : 0.0;
        $ratingA = (float) $teamA['strength_rating'] + ($teamAHome ? $homeField : 0.0);
        $ratingB = (float) $teamB['strength_rating'] + (! $teamAHome ? $homeField : 0.0);
        $scale = max(0.1, (float) config('nfl.team_playoff_forecast.win_probability_scale', 1.6));

        return 1.0 / (1.0 + exp(-(($ratingA - $ratingB) / $scale)));
    }
}
