<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CalibrateSpreadCommand extends Command
{
    protected $signature = 'nfl:calibrate-spread
                            {--season=2025 : Season to calibrate against}
                            {--hfa= : Home field advantage to use (defaults to config)}
                            {--min= : Minimum points per ELO to test (defaults to config)}
                            {--max= : Maximum points per ELO to test (defaults to config)}
                            {--step= : Step size between values (defaults to config)}';

    protected $description = 'Calibrate the ELO-to-points conversion factor for spread predictions';

    protected ?Collection $eloByTeam = null;

    public function handle(): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $hfa = $this->option('hfa') ?? config('nfl.elo.home_field_advantage');
        $min = $this->option('min') ?? config('nfl.calibration.spread.min');
        $max = $this->option('max') ?? config('nfl.calibration.spread.max');
        $step = $this->option('step') ?? config('nfl.calibration.spread.step');

        if ($season === false || $season < 2000 || $season > 2100
            || collect([$hfa, $min, $max, $step])->contains(fn ($value) => ! is_numeric($value) || ! is_finite((float) $value))
            || $min <= 0 || $max < $min || $step <= 0 || ($max - $min) / $step > 1000) {
            $this->error('Use a valid season and finite parameters: 0 < min <= max, step > 0, at most 1001 candidates.');

            return Command::INVALID;
        }

        $this->info("Calibrating spread conversion factor for {$season} season...");
        $this->info("Using HFA: {$hfa}");
        $this->newLine();

        $games = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->where('season', $season)
            ->whereIn('season_type', ['2', 'regular', 'Regular Season'])
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->get();

        if ($games->isEmpty()) {
            $this->error('No games found for calibration');

            return Command::FAILURE;
        }

        // Load once, not once per game per candidate. Date-only Elo rows are
        // postgame ratings: never admit the evaluated game's calendar date.
        $this->eloByTeam = EloRating::query()
            ->whereIn('team_id', $games->pluck('home_team_id')->merge($games->pluck('away_team_id'))->unique())
            ->whereDate('date', '<', $games->max('game_date')->toDateString())
            ->orderBy('date')->orderBy('id')->get(['team_id', 'date', 'elo_rating'])
            ->map(fn ($rating) => ['team_id' => $rating->team_id, 'date' => substr($rating->getRawOriginal('date'), 0, 10), 'elo' => (float) $rating->elo_rating])
            ->groupBy('team_id');
        $samples = [];
        foreach ($games as $game) {
            if (! $game->homeTeam || ! $game->awayTeam) {
                continue;
            }
            $home = $this->getEloAtDate($game->home_team_id, $game->game_date);
            $away = $this->getEloAtDate($game->away_team_id, $game->game_date);
            if ($home === null || $away === null) {
                continue; // An absent rating is not evidence of equal team strength.
            }
            $samples[] = ['elo_diff' => $home - $away + ($game->neutral_site ? 0 : (float) $hfa),
                'actual' => $game->home_score - $game->away_score];
        }
        $this->info('Eligible regular-season games: '.count($samples).'; excluded missing prior ratings/teams: '.($games->count() - count($samples)));
        $this->warn('Retrospective in-sample diagnostic, not held-out ATS validation. Stored ratings may be reconstructed. No settings are changed.');
        if ($samples === []) {
            $this->error('No games with both prior-date Elo ratings.');

            return Command::FAILURE;
        }
        $this->newLine();

        $results = [];

        for ($index = 0; $index <= (int) floor(((float) $max - (float) $min) / (float) $step + 1e-9); $index++) {
            $pointsPerElo = (float) $min + $index * (float) $step;
            $errors = [];

            foreach ($samples as $sample) {
                $predictedSpread = $this->publishedMargin($sample['elo_diff'], $pointsPerElo);
                $errors[] = abs($sample['actual'] - $predictedSpread);
            }

            if (empty($errors)) {
                continue;
            }

            $mae = array_sum($errors) / count($errors);
            $median = $this->median($errors);

            $results[] = [
                'points_per_elo' => round($pointsPerElo, 4),
                'mae' => $mae,
                'median' => $median,
            ];
        }

        // Sort by MAE (lower is better)
        usort($results, fn ($a, $b) => $a['mae'] <=> $b['mae']);

        // Display top 10 results
        $this->info('🏆 Top 10 Conversion Factors:');
        $this->newLine();

        $rows = [];
        foreach (array_slice($results, 0, 10) as $index => $result) {
            $rows[] = [
                $index + 1,
                $result['points_per_elo'],
                round($result['mae'], 2).' pts',
                round($result['median'], 2).' pts',
            ];
        }

        $this->table(
            ['Rank', 'Points per ELO', 'Mean Abs Error', 'Median Error'],
            $rows
        );

        $this->newLine();
        $best = $results[0];
        $this->info("Best tested in-sample conversion factor: {$best['points_per_elo']}");
        $this->line("  Mean Absolute Error: {$best['mae']} points");
        $this->line("  Median Error: {$best['median']} points");
        $this->newLine();
        $this->line('Example spreads with this factor:');
        foreach ([50, 100, 150, 200] as $difference) {
            $this->line("  {$difference} adjusted ELO difference = ".$this->publishedMargin($difference, $best['points_per_elo']).' points');
        }

        return Command::SUCCESS;
    }

    protected function publishedMargin(float $difference, float $pointsPerElo): float
    {
        return round(max((float) config('nfl.predictions.min_spread'), min((float) config('nfl.predictions.max_spread'), $difference * $pointsPerElo)), 1);
    }

    protected function getEloAtDate(int $teamId, $gameDate): ?float
    {
        $date = Carbon::parse($gameDate)->toDateString();
        if ($this->eloByTeam !== null) {
            return $this->eloByTeam->get($teamId, collect())->last(fn ($rating) => $rating['date'] < $date)['elo'] ?? null;
        }
        $eloRecord = EloRating::query()
            ->where('team_id', $teamId)
            ->whereDate('date', '<', $date)
            ->orderBy('date', 'desc')
            ->orderByDesc('id')
            ->first();

        return $eloRecord ? (float) $eloRecord->elo_rating : null;
    }

    protected function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }
}
