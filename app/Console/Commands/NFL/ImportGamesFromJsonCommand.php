<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportGamesFromJsonCommand extends Command
{
    protected $signature = 'nfl:import-games-from-json
                            {file? : Path to the JSON file (defaults to nfl_team_schedules.json in project root)}';

    protected $description = 'Import NFL games from JSON file into nfl_games table';

    public function handle(): int
    {
        $filePath = $this->argument('file') ?? base_path('nfl_team_schedules.json');

        if (! File::exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return Command::FAILURE;
        }

        $this->info("Reading games from: {$filePath}");

        $jsonContent = File::get($filePath);
        $games = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Failed to parse JSON: '.json_last_error_msg());

            return Command::FAILURE;
        }

        if (! is_array($games) || empty($games)) {
            $this->error('No games found in JSON file');

            return Command::FAILURE;
        }

        $this->info("Found {$this->count($games)} games in JSON file");
        $this->info('Importing games...');

        $bar = $this->output->createProgressBar(count($games));
        $bar->start();

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($games as $gameData) {
            try {
                $result = $this->importGame($gameData);

                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("Failed to import game {$gameData['espn_event_id']}: {$e->getMessage()}");
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Import complete!');
        $this->info("Imported: {$imported} games");
        $this->info("Updated: {$updated} games");
        $this->info("Skipped: {$skipped} games");

        return Command::SUCCESS;
    }

    protected function importGame(array $data): string
    {
        if (empty($data['espn_event_id'])) {
            return 'skipped';
        }

        $existingGame = Game::query()->where('espn_event_id', $data['espn_event_id'])->first();
        $wasExisting = (bool) $existingGame;

        $gameAttributes = [
            'espn_uid' => $data['uid'] ?? null,
            'season' => $data['season'] ?? null,
            'week' => $data['game_week'] ?? null,
            'season_type' => $this->mapSeasonType($data['season_type'] ?? null),
            'game_date' => $data['game_date'] ?? null,
            'game_time' => $data['game_time'] ?? null,
            'name' => $data['name'] ?? null,
            'short_name' => $data['short_name'] ?? null,
            'home_team_id' => $data['home_team_id'] ?? null,
            'away_team_id' => $data['away_team_id'] ?? null,
            'home_score' => $data['home_score'] ?? 0,
            'away_score' => $data['away_score'] ?? 0,
            'status' => $this->mapStatus($data['game_status'] ?? 'Scheduled'),
            'period' => $data['quarter'] ?? null,
            'game_clock' => $data['clock'] ?? null,
            'neutral_site' => $data['neutral_site'] ?? 0,
            'venue_name' => $data['venue_name'] ?? null,
            'venue_city' => $data['venue_city'] ?? null,
            'venue_state' => $data['venue_state'] ?? null,
            'broadcast_networks' => $this->parseBroadcastNetworks($data['referees'] ?? null),
        ];

        Game::updateOrCreate(
            ['espn_event_id' => (string) $data['espn_event_id']],
            $gameAttributes
        );

        return $wasExisting ? 'updated' : 'imported';
    }

    protected function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'scheduled', 'pre' => 'STATUS_SCHEDULED',
            'final', 'post' => 'STATUS_FINAL',
            'in progress', 'in' => 'STATUS_IN_PROGRESS',
            default => 'STATUS_SCHEDULED',
        };
    }

    protected function mapSeasonType(?string $seasonType): ?string
    {
        if ($seasonType === null) {
            return null;
        }

        return match ($seasonType) {
            '1' => 'Preseason',
            '2' => 'Regular Season',
            '3' => 'Postseason',
            default => $seasonType,
        };
    }

    protected function parseBroadcastNetworks(?string $networks): ?array
    {
        if (empty($networks)) {
            return null;
        }

        $decoded = json_decode($networks, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function count(array $games): int
    {
        return count($games);
    }
}
