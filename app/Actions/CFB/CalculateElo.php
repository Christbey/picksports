<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CalculateElo extends AbstractEloCalculator
{
    protected const SPORT_KEY = 'cfb';

    protected const ELO_RATING_MODEL = EloRating::class;

    public function __construct(
        private readonly CfbSeasonAffiliationResolver $seasonAffiliationResolver = new CfbSeasonAffiliationResolver,
    ) {}

    protected function calculateKFactor(Model $game): float
    {
        $kFactor = config('cfb.elo.base_k_factor');

        $kFactor = $this->applyRecencyWeekMultiplier(
            $game,
            (float) $kFactor,
            config('cfb.season.types.regular')
        );

        $kFactor = $this->applyPlayoffMultiplier($game, (float) $kFactor);

        // Apply margin of victory multiplier
        $marginMultiplier = $this->calculateMarginMultiplier($game);
        $kFactor *= $marginMultiplier;

        return $kFactor;
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $this->gameMatchesSeasonType($game, config('cfb.season.types.postseason'));
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $coefficient = config('cfb.elo.mov_coefficient');
        $maxMultiplier = config('cfb.elo.max_mov_multiplier');

        return $this->resolveLogMarginMultiplier($margin, (float) $coefficient, (float) $maxMultiplier);
    }

    public const MODEL_VERSION = 'cfb-elo-2.0.0';

    public function fingerprint(Model $game): string
    {
        return hash('sha256', json_encode([
            self::MODEL_VERSION, $game->id, $game->season, $game->week, $game->season_type,
            $game->game_date?->format('Y-m-d H:i:s'), $game->game_time,
            $game->home_team_id, $game->away_team_id, $game->home_score, $game->away_score,
            (bool) $game->neutral_site, $game->status, config('cfb.elo'),
        ], JSON_THROW_ON_ERROR));
    }

    public function validateResult(Model $game): void
    {
        if ($game->status !== 'STATUS_FINAL'
            || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)
            || $game->home_score < 0 || $game->away_score < 0
            || (float) $game->home_score !== (float) (int) $game->home_score
            || (float) $game->away_score !== (float) (int) $game->away_score
            || (int) $game->home_score === (int) $game->away_score
            || ! $game->game_date || ! $game->home_team_id || ! $game->away_team_id
            || $game->home_team_id === $game->away_team_id) {
            throw new RuntimeException("Invalid final CFB result for game {$game->id}; ratings were not changed.");
        }
    }

    public function calculateFromRatings(Model $game, float $homeElo, float $awayElo): array
    {
        $this->validateResult($game);
        $advantage = $game->neutral_site ? 0 : (float) config('cfb.elo.home_field_advantage', 55);
        $expected = $this->calculateExpectedScore($homeElo + $advantage, $awayElo);
        $delta = round($this->calculateKFactor($game) * (($game->home_score > $game->away_score ? 1 : 0) - $expected), 1);
        // Preserve the established integer rating scale, but store the actual applied delta.
        $homeAfter = (int) round($homeElo + $delta);
        $awayAfter = (int) round($awayElo - $delta);

        return [
            'home_change' => $homeAfter - $homeElo,
            'away_change' => $awayAfter - $awayElo,
            'home_new_elo' => $homeAfter,
            'away_new_elo' => $awayAfter,
            'skipped' => false,
        ];
    }

    public function execute(Model $game, bool $skipIfExists = true): array
    {
        if ($game->status !== 'STATUS_FINAL') {
            return ['home_change' => 0, 'away_change' => 0, 'skipped' => true];
        }

        return Cache::lock('cfb-elo-integrity-write', 600)->block(15, fn () => DB::transaction(function () use ($game) {
            $game = Game::query()->lockForUpdate()->findOrFail($game->id);
            $teams = Team::query()->whereIn('id', [$game->home_team_id, $game->away_team_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $home = $teams->get($game->home_team_id);
            $away = $teams->get($game->away_team_id);
            if (! $home || ! $away) {
                throw new RuntimeException("Missing team for CFB game {$game->id}.");
            }
            $affiliations = [];
            foreach ([$home, $away] as $team) {
                $attributes = $this->seasonAffiliationResolver->attributesForSeason($team, (int) $game->season);
                if (empty($attributes['subdivision']) || $attributes['source'] === 'unknown') {
                    throw new RuntimeException("Unverified CFB affiliation for team {$team->id} season {$game->season}; synchronize before calculating.");
                }
                $affiliations[] = $attributes['subdivision'];
            }
            if ($affiliations !== ['FBS', 'FBS']) {
                return ['home_change' => 0, 'away_change' => 0, 'skipped' => true];
            }
            $this->validateResult($game);
            $fingerprint = $this->fingerprint($game);
            $existing = EloRating::query()->where('game_id', $game->id)->get();
            if ($existing->isNotEmpty()) {
                if ($existing->count() !== 2
                    || $existing->pluck('team_id')->sort()->values()->all() !== $teams->keys()->sort()->values()->all()
                    || $existing->contains(fn ($row) => $row->result_fingerprint !== $fingerprint
                        || $row->model_version !== self::MODEL_VERSION || $row->elo_before === null
                        || $row->season_initialization_id === null)) {
                    throw new RuntimeException("CFB Elo history is incomplete, legacy, or corrected for game {$game->id}; run cfb:rebuild-elo and validate its candidate before activation.");
                }

                return ['home_change' => 0, 'away_change' => 0, 'skipped' => true];
            }
            foreach ($teams as $team) {
                $latest = EloRating::query()->where('team_id', $team->id)
                    ->join('cfb_games', 'cfb_games.id', '=', 'cfb_elo_ratings.game_id')
                    ->orderByDesc('cfb_games.game_date')->orderByDesc('cfb_games.game_time')
                    ->orderByDesc('cfb_games.id')->select('cfb_elo_ratings.*')->first();
                if ($latest) {
                    $previousGame = $latest->game;
                    if ($this->orderKey($previousGame) >= $this->orderKey($game)) {
                        throw new RuntimeException("Out-of-order CFB game {$game->id}; run cfb:rebuild-elo for a chronological candidate.");
                    }
                    if ($latest->model_version !== self::MODEL_VERSION) {
                        throw new RuntimeException('Legacy CFB Elo baseline requires a validated cfb:rebuild-elo candidate.');
                    }
                    if ((float) $team->elo_rating !== (float) $latest->elo_rating) {
                        throw new RuntimeException('CFB team rating disagrees with its latest history; rebuild required.');
                    }
                }
            }
            $homeInit = $this->initializeSeason($home, (int) $game->season);
            $awayInit = $this->initializeSeason($away, (int) $game->season);
            $result = $this->calculateFromRatings($game, (float) $home->elo_rating, (float) $away->elo_rating);
            foreach ([['home', $home, $homeInit], ['away', $away, $awayInit]] as [$side, $team, $initialization]) {
                EloRating::create([
                    'team_id' => $team->id, 'game_id' => $game->id,
                    'season' => $game->season, 'week' => $game->week,
                    'season_type' => $game->season_type, 'date' => $game->game_date,
                    'elo_before' => $team->elo_rating, 'elo_rating' => $result[$side.'_new_elo'],
                    'elo_change' => $result[$side.'_change'], 'model_version' => self::MODEL_VERSION,
                    'result_fingerprint' => $fingerprint, 'season_initialization_id' => $initialization,
                ]);
                $team->update(['elo_rating' => $result[$side.'_new_elo']]);
            }

            return $result;
        }, 3));
    }

    public function orderKey(Model $game): string
    {
        return $game->game_date->format('Y-m-d').' '.($game->game_time ?? '00:00:00').' '.str_pad((string) $game->id, 20, '0', STR_PAD_LEFT);
    }

    private function initializeSeason(Team $team, int $season): int
    {
        $query = DB::table('cfb_elo_season_initializations')->where('team_id', $team->id)
            ->where('season', $season)->where('active_slot', 1);
        if ($existing = $query->first()) {
            return $existing->id;
        }
        if (EloRating::query()->where('team_id', $team->id)->where('season', $season)->exists()) {
            throw new RuntimeException('CFB season has history without initialization; rebuild required.');
        }
        $prior = EloRating::query()->where('team_id', $team->id)->where('cfb_elo_ratings.season', $season - 1)
            ->join('cfb_games', 'cfb_games.id', '=', 'cfb_elo_ratings.game_id')
            ->orderByDesc('cfb_games.game_date')->orderByDesc('cfb_games.game_time')->orderByDesc('cfb_games.id')
            ->select('cfb_elo_ratings.*')->first();
        $default = (float) config('cfb.elo.default_rating', 1500);
        $factor = (float) config('cfb.elo.offseason_regression_factor', .3);
        $baseline = (float) ($prior?->elo_rating ?? $default);
        $initial = round($baseline * (1 - $factor) + $default * $factor);
        $id = DB::table('cfb_elo_season_initializations')->insertGetId([
            'team_id' => $team->id, 'season' => $season, 'model_version' => self::MODEL_VERSION,
            'prior_rating' => $baseline, 'initial_rating' => $initial,
            'regression_factor' => $factor, 'source_rating_id' => $prior?->id,
            'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $team->update(['elo_rating' => $initial]);

        return $id;
    }
}
