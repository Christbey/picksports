<?php

namespace App\Services\WNBA;

use App\Actions\WNBA\CalculateElo;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use App\Support\ESPN\ObservedStatValue;
use RuntimeException;

/** Scoped reconstruction from the declared starting season, not a whole-career baseline. */
class HistoricalEloReplay
{
    public const MAXIMUM_GAMES = 20_000;

    public function replay(int $fromSeason, int $toSeason): int
    {
        $connection = (new Game)->getConnection();
        $name = $connection->getName();
        $games = $this->games($name, $fromSeason, $toSeason)->orderBy('game_date')->orderBy('game_time')->orderBy('id')
            ->limit(self::MAXIMUM_GAMES + 1)->get();
        if ($games->isEmpty() || $games->count() > self::MAXIMUM_GAMES) {
            throw new RuntimeException('Historical Elo requires a nonempty source within the game budget.');
        }
        $teams = $games->flatMap(fn (Game $game): array => [$game->home_team_id, $game->away_team_id])->unique()->values();
        $known = Team::on($name)->whereIn('id', $teams)->pluck('id')->all();
        $known = array_fill_keys($known, true);
        $ratings = [];
        $rows = [];
        $calculator = new CalculateElo;
        $default = (int) config('wnba.elo.default', 1500);
        foreach ($games as $game) {
            if (! isset($known[$game->home_team_id], $known[$game->away_team_id]) || $game->home_team_id === $game->away_team_id
                || $game->game_date === null || $game->home_score === null || $game->away_score === null) {
                throw new RuntimeException('Historical Elo requires observed final scores and distinct known teams.');
            }
            ObservedStatValue::integer($game->home_score);
            ObservedStatValue::integer($game->away_score);
            $changes = $calculator->previewChanges($game, $ratings[$game->home_team_id] ?? $default, $ratings[$game->away_team_id] ?? $default);
            foreach (['home', 'away'] as $side) {
                $teamId = $game->{$side.'_team_id'};
                $ratings[$teamId] = $changes[$side.'_new_elo'];
                $rows[] = ['team_id' => $teamId, 'game_id' => $game->id, 'season' => $game->season,
                    'week' => $game->week, 'date' => $game->game_date->toDateString(), 'elo_rating' => $changes[$side.'_new_elo'],
                    'elo_change' => $changes[$side.'_change'], 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
            }
        }
        $fingerprint = $this->fingerprint($games);
        $connection->transaction(function () use ($connection, $name, $fromSeason, $toSeason, $fingerprint, $rows): void {
            app(WnbaEloPublicationGate::class)->lock($connection);
            $current = $this->games($name, $fromSeason, $toSeason)->orderBy('id')->lockForUpdate()->limit(self::MAXIMUM_GAMES + 1)->get();
            if ($current->count() > self::MAXIMUM_GAMES || $this->fingerprint($current) !== $fingerprint) {
                throw new RuntimeException('Historical Elo source changed before publication.');
            }
            $prior = $connection->table('wnba_elo_ratings')->whereBetween('season', [$fromSeason, $toSeason])
                ->orderBy('id')->lockForUpdate()->limit(self::MAXIMUM_GAMES * 2 + 1)->get(['id']);
            if ($prior->count() > self::MAXIMUM_GAMES * 2) {
                throw new RuntimeException('Historical Elo prior history exceeds the row budget.');
            }
            $connection->table('wnba_elo_ratings')->whereBetween('season', [$fromSeason, $toSeason])->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                $connection->table('wnba_elo_ratings')->insert($chunk);
            }
        }, 3);

        return $games->count();
    }

    private function games(string $connection, int $fromSeason, int $toSeason)
    {
        return Game::on($connection)->whereBetween('season', [$fromSeason, $toSeason])->where('status', 'STATUS_FINAL');
    }

    private function fingerprint($games): string
    {
        return hash('sha256', json_encode($games->sortBy('id')->map(fn (Game $game): array => [
            'id' => $game->id, 'season' => $game->season, 'season_type' => $game->season_type, 'week' => $game->week,
            'game_date' => $game->game_date?->toIso8601String(), 'game_time' => $game->game_time, 'status' => $game->status,
            'home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
            'home_score' => $game->home_score, 'away_score' => $game->away_score, 'neutral_site' => $game->neutral_site,
        ])->values()->all(), JSON_THROW_ON_ERROR));
    }
}
