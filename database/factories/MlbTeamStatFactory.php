<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MLB\TeamStat>
 */
class MlbTeamStatFactory extends Factory
{
    protected $model = \App\Models\MLB\TeamStat::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Batting statistics
        $atBats = $this->faker->numberBetween(30, 40);
        $hits = $this->faker->numberBetween(5, $atBats - 5);
        $doubles = $this->faker->numberBetween(0, (int) ($hits / 3));
        $triples = $this->faker->numberBetween(0, 2);
        $homeRuns = $this->faker->numberBetween(0, 3);
        $walks = $this->faker->numberBetween(2, 6);
        $strikeouts = $this->faker->numberBetween(5, 12);
        $runs = $this->faker->numberBetween(1, 8);
        $rbis = $this->faker->numberBetween(0, $runs + 2);
        $stolenBases = $this->faker->numberBetween(0, 3);
        $leftOnBase = $this->faker->numberBetween(4, 10);
        $battingAverage = $atBats > 0 ? round($hits / $atBats, 3) : 0.000;

        // Pitching statistics
        $inningsPitched = $this->faker->numberBetween(7, 9);
        $hitsAllowed = $this->faker->numberBetween(4, 12);
        $runsAllowed = $this->faker->numberBetween(0, 6);
        $earnedRuns = $this->faker->numberBetween(0, $runsAllowed);
        $walksAllowed = $this->faker->numberBetween(1, 5);
        $strikeoutsPitched = $this->faker->numberBetween(4, 12);
        $homeRunsAllowed = $this->faker->numberBetween(0, 2);
        $pitchersUsed = $this->faker->numberBetween(3, 6);
        $totalPitches = $this->faker->numberBetween(120, 160);
        $era = $inningsPitched > 0 ? round(($earnedRuns * 9) / $inningsPitched, 2) : 0.00;

        // Fielding statistics
        $putouts = $this->faker->numberBetween(24, 27);
        $assists = $this->faker->numberBetween(8, 15);
        $errors = $this->faker->numberBetween(0, 2);
        $doublePlays = $this->faker->numberBetween(0, 3);
        $passedBalls = $this->faker->numberBetween(0, 1);

        return [
            'team_type' => $this->faker->randomElement(['home', 'away']),
            // Batting stats
            'runs' => $runs,
            'hits' => $hits,
            'errors' => $errors,
            'at_bats' => $atBats,
            'doubles' => $doubles,
            'triples' => $triples,
            'home_runs' => $homeRuns,
            'rbis' => $rbis,
            'walks' => $walks,
            'strikeouts' => $strikeouts,
            'stolen_bases' => $stolenBases,
            'left_on_base' => $leftOnBase,
            'batting_average' => $battingAverage,
            // Pitching stats
            'pitchers_used' => $pitchersUsed,
            'innings_pitched' => $inningsPitched,
            'hits_allowed' => $hitsAllowed,
            'runs_allowed' => $runsAllowed,
            'earned_runs' => $earnedRuns,
            'walks_allowed' => $walksAllowed,
            'strikeouts_pitched' => $strikeoutsPitched,
            'home_runs_allowed' => $homeRunsAllowed,
            'total_pitches' => $totalPitches,
            'era' => $era,
            // Fielding stats
            'putouts' => $putouts,
            'assists' => $assists,
            'double_plays' => $doublePlays,
            'passed_balls' => $passedBalls,
        ];
    }
}
