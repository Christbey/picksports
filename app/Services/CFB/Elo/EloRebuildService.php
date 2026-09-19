<?php

namespace App\Services\CFB\Elo;

use App\Actions\CFB\CalculateElo;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Replays source results without consulting mutable team Elo or changing live history. */
class EloRebuildService
{
    public function __construct(private CalculateElo $calculator, private CfbSeasonAffiliationResolver $affiliations) {}

    /** Detect upstream corrections even when the scheduler is scoped to the current season. */
    public function assertActiveHistoryCurrent(): void
    {
        $history = EloRating::query()->get()->groupBy('game_id');
        if ($history->isEmpty()) {
            return;
        }
        $allGames = Game::query()->get()->keyBy('id');
        $teams = Team::query()->with('seasonAffiliations')->get()->keyBy('id');
        $latest = [];
        foreach ($history as $gameId => $pair) {
            $game = $allGames->get($gameId);
            if (! $game || $pair->count() !== 2 || $pair->pluck('team_id')->unique()->count() !== 2
                || $pair->contains(fn ($row) => $row->result_fingerprint !== $this->calculator->fingerprint($game))) {
                throw new RuntimeException("CFB Elo source history changed or is incomplete at game {$gameId}; rebuild a candidate before calculating.");
            }
            foreach ($pair as $row) {
                $team = $teams->get($row->team_id);
                if (! $team || ! $this->affiliations->isFbs($team, (int) $game->season)) {
                    throw new RuntimeException('Active CFB Elo affiliation changed; rebuild required.');
                }
                $latest[$row->team_id] = max($latest[$row->team_id] ?? '', $this->calculator->orderKey($game));
            }
        }
        foreach ($allGames as $game) {
            if ($game->status !== 'STATUS_FINAL' || $history->has($game->id)) {
                continue;
            }
            $home = $teams->get($game->home_team_id);
            $away = $teams->get($game->away_team_id);
            if ($home && $away && $this->affiliations->isFbs($home, (int) $game->season)
                && $this->affiliations->isFbs($away, (int) $game->season)
                && ($this->calculator->orderKey($game) < ($latest[$home->id] ?? '')
                    || $this->calculator->orderKey($game) < ($latest[$away->id] ?? ''))) {
                throw new RuntimeException("Late-arriving CFB game {$game->id} requires a chronological candidate rebuild.");
            }
        }
    }

    public function build(): array
    {
        [$games, $teams, $eligibility, $digest] = $this->inputs();
        $ratings = [];
        $seasons = [];
        $initializations = [];
        $rows = [];
        $excluded = [];
        $default = (float) config('cfb.elo.default_rating', 1500);
        $factor = (float) config('cfb.elo.offseason_regression_factor', .3);
        foreach ($games as $game) {
            if (! ($eligibility[$game->season][$game->home_team_id] ?? false)
                || ! ($eligibility[$game->season][$game->away_team_id] ?? false)) {
                $excluded[] = $game->id;

                continue;
            }
            $this->calculator->validateResult($game);
            foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
                if (($seasons[$teamId] ?? null) !== $game->season) {
                    $prior = ($seasons[$teamId] ?? null) === $game->season - 1 ? $ratings[$teamId] : $default;
                    $ratings[$teamId] = round($prior * (1 - $factor) + $default * $factor);
                    $seasons[$teamId] = $game->season;
                    $initializations[$game->season.':'.$teamId] = [
                        'team_id' => $teamId, 'season' => $game->season,
                        'model_version' => CalculateElo::MODEL_VERSION, 'prior_rating' => $prior,
                        'initial_rating' => $ratings[$teamId], 'regression_factor' => $factor,
                    ];
                }
            }
            $result = $this->calculator->calculateFromRatings($game, $ratings[$game->home_team_id], $ratings[$game->away_team_id]);
            foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $side => $teamId) {
                $rows[] = [
                    'team_id' => $teamId, 'game_id' => $game->id, 'season' => $game->season,
                    'week' => $game->week, 'season_type' => $game->season_type,
                    'date' => $game->game_date->format('Y-m-d'),
                    'elo_before' => $ratings[$teamId], 'elo_rating' => $result[$side.'_new_elo'],
                    'elo_change' => $result[$side.'_change'], 'model_version' => CalculateElo::MODEL_VERSION,
                    'result_fingerprint' => $this->calculator->fingerprint($game),
                ];
                $ratings[$teamId] = $result[$side.'_new_elo'];
            }
        }
        $differences = [];
        foreach ($ratings as $id => $rating) {
            $differences[] = ['team_id' => $id, 'team' => $teams[$id]->school,
                'stored' => (float) $teams[$id]->elo_rating, 'candidate' => $rating,
                'difference' => round($rating - (float) $teams[$id]->elo_rating, 2)];
        }
        usort($differences, fn ($a, $b) => abs($b['difference']) <=> abs($a['difference']));
        $payload = ['ratings' => $ratings, 'rows' => $rows, 'initializations' => array_values($initializations),
            'excluded_games' => $excluded, 'source_seasons' => $games->pluck('season')->unique()->values()->all(),
            'initialization_policy' => 'Configured default at first available season; configured regression from immediately preceding season thereafter',
            'retrospective' => true, 'elo_config' => config('cfb.elo')];
        $this->validatePayload($payload);
        $comparison = ['games' => count($rows) / 2, 'teams' => count($ratings), 'excluded_games' => count($excluded),
            'integrity_validated' => true, 'accuracy_validated' => false, 'differences' => $differences];
        $id = DB::table('cfb_elo_rebuilds')->insertGetId([
            'model_version' => CalculateElo::MODEL_VERSION, 'status' => 'candidate', 'input_digest' => $digest,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'comparison' => json_encode($comparison, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'input_digest' => $digest, ...$comparison];
    }

    public function activate(int $id): array
    {
        return Cache::lock('cfb-elo-integrity-write', 600)->block(15, fn () => DB::transaction(function () use ($id) {
            $candidate = DB::table('cfb_elo_rebuilds')->where('id', $id)->lockForUpdate()->first();
            if (! $candidate || $candidate->status !== 'candidate' || $candidate->model_version !== CalculateElo::MODEL_VERSION) {
                throw new RuntimeException('Rebuild is missing, already activated, or uses another model version.');
            }
            // Prevent score/status and affiliation updates while the source digest is checked and installed.
            Game::query()->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('cfb_team_season_affiliations')->orderBy('id')->lockForUpdate()->get();
            Team::query()->orderBy('id')->lockForUpdate()->get(['id']);
            [, , , $digest] = $this->inputs();
            if (! hash_equals($candidate->input_digest, $digest)) {
                throw new RuntimeException('Source results, affiliations, or Elo configuration changed; build a fresh candidate.');
            }
            $payload = json_decode($candidate->payload, true, flags: JSON_THROW_ON_ERROR);
            $this->validatePayload($payload);
            if (empty($payload['rows'])) {
                throw new RuntimeException('An empty Elo candidate cannot be activated.');
            }
            // Lock every team because resetting omitted teams is part of this full-history activation.
            Team::query()->orderBy('id')->lockForUpdate()->get();
            EloRating::query()->toBase()->update(['active_slot' => null]);
            DB::table('cfb_elo_season_initializations')->where('active_slot', 1)->update(['active_slot' => null]);
            $initializationIds = [];
            foreach ($payload['initializations'] as $initialization) {
                $initializationIds[$initialization['season'].':'.$initialization['team_id']] = DB::table('cfb_elo_season_initializations')->insertGetId([
                    ...$initialization, 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($payload['rows'] as $row) {
                EloRating::create([...$row, 'active_slot' => 1, 'rebuilt_at' => now(),
                    'season_initialization_id' => $initializationIds[$row['season'].':'.$row['team_id']]]);
            }
            Team::query()->update(['elo_rating' => (float) config('cfb.elo.default_rating', 1500)]);
            foreach ($payload['ratings'] as $teamId => $rating) {
                Team::query()->whereKey($teamId)->update(['elo_rating' => $rating]);
            }
            DB::table('cfb_elo_rebuilds')->where('status', 'active')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('cfb_elo_rebuilds')->where('id', $id)->update(['status' => 'active', 'updated_at' => now()]);

            return ['id' => $id, 'status' => 'active', 'games' => count($payload['rows']) / 2,
                'teams' => count($payload['ratings']), 'historical_rows_preserved' => true];
        }, 3));
    }

    private function validatePayload(array $payload): void
    {
        $state = [];
        $initializations = [];
        foreach ($payload['initializations'] as $initialization) {
            $expected = round($initialization['prior_rating'] * (1 - $initialization['regression_factor'])
                + (float) config('cfb.elo.default_rating', 1500) * $initialization['regression_factor']);
            if ((float) $initialization['initial_rating'] !== (float) $expected) {
                throw new RuntimeException('Candidate season initialization failed integrity validation.');
            }
            $initializations[$initialization['season'].':'.$initialization['team_id']] = $initialization;
        }
        $pairs = [];
        $endpoints = [];
        $latestSeason = [];
        foreach ($payload['rows'] as $row) {
            $key = $row['season'].':'.$row['team_id'];
            if (! isset($state[$key])) {
                $expectedPrior = ($latestSeason[$row['team_id']] ?? null) === $row['season'] - 1
                    ? $endpoints[$row['team_id']] : (float) config('cfb.elo.default_rating', 1500);
                if ((float) ($initializations[$key]['prior_rating'] ?? -1) !== (float) $expectedPrior) {
                    throw new RuntimeException('Candidate offseason boundary disagrees with prior season.');
                }
            }
            $expectedBefore = $state[$key] ?? ($initializations[$key]['initial_rating'] ?? null);
            if ($expectedBefore === null || (float) $expectedBefore !== (float) $row['elo_before']
                || (float) $row['elo_rating'] - (float) $row['elo_before'] !== (float) $row['elo_change']
                || ! is_finite((float) $row['elo_rating'])) {
                throw new RuntimeException('Candidate rating continuity failed integrity validation.');
            }
            $state[$key] = $row['elo_rating'];
            $endpoints[$row['team_id']] = $row['elo_rating'];
            $latestSeason[$row['team_id']] = $row['season'];
            $pairs[$row['game_id']][] = $row['team_id'];
        }
        if ($endpoints != $payload['ratings']) {
            throw new RuntimeException('Candidate endpoints disagree with history.');
        }
        foreach ($pairs as $pair) {
            if (count($pair) !== 2 || count(array_unique($pair)) !== 2) {
                throw new RuntimeException('Candidate contains incomplete or duplicate game pairs.');
            }
        }
    }

    private function inputs(): array
    {
        $games = Game::query()->where('status', 'STATUS_FINAL')->orderBy('season')
            ->orderBy('game_date')->orderBy('game_time')->orderBy('id')->get();
        $teams = Team::query()->with('seasonAffiliations')->get()->keyBy('id');
        $eligibility = [];
        $affiliationEvidence = [];
        foreach ($games->groupBy('season') as $season => $seasonGames) {
            $participants = $seasonGames->pluck('home_team_id')->merge($seasonGames->pluck('away_team_id'))->unique()->sort();
            foreach ($participants as $teamId) {
                $team = $teams->get($teamId);
                if (! $team) {
                    throw new RuntimeException("Missing CFB team {$teamId} in season {$season}.");
                }
                $attributes = $this->affiliations->attributesForSeason($team, (int) $season);
                if (empty($attributes['subdivision']) || in_array($attributes['source'], ['unknown', 'team_snapshot'], true)) {
                    throw new RuntimeException("Unverified CFB affiliation for team {$teamId} season {$season}; synchronize season affiliations before replay.");
                }
                $eligibility[$season][$teamId] = $attributes['subdivision'] === 'FBS';
                $affiliationEvidence[$season][$teamId] = $attributes;
            }
        }
        $fingerprints = $games->map(fn ($game) => $this->calculator->fingerprint($game))->all();
        $digest = hash('sha256', json_encode([$fingerprints, $affiliationEvidence, config('cfb.elo')], JSON_THROW_ON_ERROR));

        return [$games, $teams, $eligibility, $digest];
    }
}
