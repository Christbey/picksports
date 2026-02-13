<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportLineScoresCommand extends Command
{
    protected $signature = 'nfl:import-line-scores
                            {file? : Path to the JSON file (defaults to nfl_box_scores.json in project root)}';

    protected $description = 'Import NFL line scores from box scores JSON file';

    public function handle(): int
    {
        $filePath = $this->argument('file') ?? base_path('nfl_box_scores.json');

        if (! File::exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return Command::FAILURE;
        }

        $this->info("Reading box scores from: {$filePath}");

        $jsonContent = File::get($filePath);
        $boxScores = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Failed to parse JSON: '.json_last_error_msg());

            return Command::FAILURE;
        }

        if (! is_array($boxScores) || empty($boxScores)) {
            $this->error('No box scores found in JSON file');

            return Command::FAILURE;
        }

        $this->info('Found '.count($boxScores).' box scores in JSON file');
        $this->info('Importing line scores...');

        $bar = $this->output->createProgressBar(count($boxScores));
        $bar->start();

        $updated = 0;
        $notFound = 0;
        $skipped = 0;

        foreach ($boxScores as $boxScore) {
            try {
                $result = $this->importLineScore($boxScore);

                if ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'not_found') {
                    $notFound++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("Failed to import line score for game {$boxScore['game_id']}: {$e->getMessage()}");
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Line score import complete!');
        $this->info("Updated: {$updated} games");
        $this->info("Not found: {$notFound} games");
        $this->info("Skipped: {$skipped} games");

        return Command::SUCCESS;
    }

    protected function importLineScore(array $data): string
    {
        if (empty($data['home_team_id']) || empty($data['away_team_id']) || empty($data['game_date'])) {
            return 'skipped';
        }

        $gameDate = Carbon::parse($data['game_date'])->format('Y-m-d');

        $game = Game::query()
            ->where('home_team_id', $data['home_team_id'])
            ->where('away_team_id', $data['away_team_id'])
            ->whereDate('game_date', $gameDate)
            ->first();

        if (! $game) {
            return 'not_found';
        }

        $homeLineScores = $this->parseLineScore($data['home_line_score'] ?? null);
        $awayLineScores = $this->parseLineScore($data['away_line_score'] ?? null);

        $game->update([
            'home_linescores' => $homeLineScores,
            'away_linescores' => $awayLineScores,
        ]);

        return 'updated';
    }

    protected function parseLineScore(?string $lineScoreJson): ?array
    {
        if (empty($lineScoreJson)) {
            return null;
        }

        $lineScore = json_decode($lineScoreJson, true);

        if (! is_array($lineScore)) {
            return null;
        }

        $quarters = [];

        foreach (['Q1', 'Q2', 'Q3', 'Q4', 'OT'] as $quarter) {
            if (isset($lineScore[$quarter])) {
                $quarters[$quarter] = (int) $lineScore[$quarter];
            }
        }

        return empty($quarters) ? null : $quarters;
    }
}
