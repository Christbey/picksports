<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Confirmed final-game starter labels, never projected depth-chart ranks or passing leaders. */
class NflGameQuarterbackIdentitySync
{
    public const SOURCE_URL = 'https://raw.githubusercontent.com/nflverse/nfldata/master/data/games.csv';

    public function sync(string $csv, int $season, bool $apply = false): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $required = ['game_id', 'espn', 'season', 'game_type', 'week', 'gameday', 'home_team', 'away_team', 'home_score', 'away_score', 'home_qb_id', 'away_qb_id', 'home_qb_name', 'away_qb_name'];
        if (! is_array($header) || array_diff($required, $header) !== []) {
            fclose($stream);
            throw new RuntimeException('Missing required nflverse schedule columns.');
        }
        $byEvent = [];
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }
            $row = array_combine($header, $values);
            if ((int) $row['season'] === $season && trim($row['espn']) !== '') {
                $byEvent[$row['espn']][] = $row;
            }
        }
        fclose($stream);
        $hash = hash('sha256', $csv);
        $report = ['season' => $season, 'apply' => $apply, 'source_url' => self::SOURCE_URL,
            'source_sha256' => $hash, 'observed_at' => now()->toIso8601String(),
            'games' => [], 'changed_games' => 0, 'unresolved_games' => 0];
        $games = Game::query()->with(['homeTeam', 'awayTeam'])->where('season', $season)
            ->whereIn('season_type', ['2', 'regular', '3', 'postseason'])->where('status', 'STATUS_FINAL')->orderBy('id')->get();
        foreach ($games as $game) {
            $rows = $byEvent[(string) $game->espn_event_id] ?? [];
            if (count($rows) !== 1 || ! $this->matches($game, $rows[0])) {
                $report['games'][] = ['game_id' => $game->id, 'status' => 'missing_ambiguous_or_mismatched_source'];
                $report['unresolved_games']++;

                continue;
            }
            $row = $rows[0];
            $result = DB::transaction(function () use ($game, $row, $hash, $report, $apply) {
                $locked = Game::query()->when($apply, fn ($query) => $query->lockForUpdate())->findOrFail($game->id);
                if (! $this->matches($locked, $row)) {
                    return ['game_id' => $game->id, 'status' => 'game_changed_during_repair'];
                }
                $updates = [];
                $evidence = $locked->quarterback_identity_evidence ?? [];
                foreach (['home', 'away'] as $side) {
                    $name = trim($row[$side.'_qb_name']);
                    $id = trim($row[$side.'_qb_id']);
                    if ($name === '' || ! preg_match('/^00-\d{7}$/', $id) || in_array(strtoupper($name), ['NA', 'NULL'], true)) {
                        return ['game_id' => $game->id, 'status' => 'missing_source_starter'];
                    }
                    foreach (['name' => $name, 'id' => $id] as $field => $value) {
                        $key = $side.'_qb_'.$field;
                        if (filled($locked->$key) && $this->identity((string) $locked->$key) !== $this->identity($value)) {
                            return ['game_id' => $game->id, 'status' => 'conflicting_existing_starter', 'side' => $side];
                        }
                        if (blank($locked->$key)) {
                            $updates[$key] = $value;
                        }
                    }
                    if (isset($updates[$side.'_qb_name']) || isset($updates[$side.'_qb_id'])) {
                        $evidence[$side] = ['status' => 'confirmed_final_game_starter', 'provider' => 'nflverse_schedules',
                            'source_url' => self::SOURCE_URL, 'source_sha256' => $hash, 'observed_at' => $report['observed_at'],
                            'pregame_observed' => false, 'source_game_id' => $row['game_id'], 'espn_event_id' => $row['espn'],
                            'qb_id' => $id, 'qb_name' => $name];
                    }
                }
                if ($updates !== [] && $apply) {
                    // Intentionally no prediction, revision, market, score, or depth-chart writes.
                    $locked->fill([...$updates, 'quarterback_identity_evidence' => $evidence])->save();
                }

                return ['game_id' => $game->id, 'status' => $updates === [] ? 'unchanged' : ($apply ? 'updated' : 'would_update'),
                    'fields' => $updates, 'source_game_id' => $row['game_id']];
            });
            $report['games'][] = $result;
            $report['changed_games'] += (int) in_array($result['status'], ['updated', 'would_update'], true);
            $report['unresolved_games'] += (int) ! in_array($result['status'], ['updated', 'would_update', 'unchanged'], true);
        }

        return $report;
    }

    private function matches(Game $game, array $row): bool
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $row['gameday']);
        } catch (\Throwable) {
            return false;
        }
        $regular = in_array((string) $game->season_type, ['2', 'regular'], true);

        return $date && in_array($game->game_date?->toDateString(), [$date->toDateString(), $date->addDay()->toDateString()], true)
            && (int) $row['season'] === (int) $game->season
            // ESPN postseason week numbers restart; nflverse continues the season.
            && (! $regular || (int) $row['week'] === (int) $game->week)
            && ($regular ? $row['game_type'] === 'REG' : in_array($row['game_type'], ['WC', 'DIV', 'CON', 'SB'], true))
            && $this->team($row['home_team']) === $this->team((string) $game->homeTeam?->abbreviation)
            && $this->team($row['away_team']) === $this->team((string) $game->awayTeam?->abbreviation)
            && is_numeric($row['home_score']) && is_numeric($row['away_score'])
            && $game->home_score !== null && $game->away_score !== null
            && (int) $row['home_score'] === (int) $game->home_score && (int) $row['away_score'] === (int) $game->away_score;
    }

    private function team(string $team): string
    {
        return match (strtoupper(trim($team))) {
            'LA', 'STL' => 'LAR', 'SD' => 'LAC', 'OAK' => 'LV', 'WSH' => 'WAS', default => strtoupper(trim($team))
        };
    }

    private function identity(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }
}
