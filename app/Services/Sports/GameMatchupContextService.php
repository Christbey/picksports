<?php

namespace App\Services\Sports;

use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Game as NflGame;
use App\Services\NFL\QuarterbackAvailability;
use App\Support\MLB\MlbGameScoreResolver;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class GameMatchupContextService
{
    /**
     * @return array{rows: array<int, array<string, mixed>>}
     */
    public function forGame(Model $game): array
    {
        $config = $this->configForGame($game);
        $homeTeam = $game->relationLoaded('homeTeam') ? $game->homeTeam : $game->homeTeam()->first();
        $awayTeam = $game->relationLoaded('awayTeam') ? $game->awayTeam : $game->awayTeam()->first();

        if (! $homeTeam || ! $awayTeam) {
            return ['rows' => []];
        }

        $homeTeamId = (int) $homeTeam->getKey();
        $awayTeamId = (int) $awayTeam->getKey();
        $matchupGames = $this->priorSeasonGamesForTeams($game, [$homeTeamId, $awayTeamId]);
        $homeGames = $matchupGames->filter(
            fn (Model $item): bool => (int) $item->home_team_id === $homeTeamId
                || (int) $item->away_team_id === $homeTeamId,
        );
        $awayGames = $matchupGames->filter(
            fn (Model $item): bool => (int) $item->home_team_id === $awayTeamId
                || (int) $item->away_team_id === $awayTeamId,
        );
        $headToHeadGames = $matchupGames->filter(function (Model $item) use ($homeTeamId, $awayTeamId): bool {
            $teams = [(int) $item->home_team_id, (int) $item->away_team_id];

            return in_array($homeTeamId, $teams, true) && in_array($awayTeamId, $teams, true);
        });
        if ($game instanceof NflGame) {
            // Season records and historical meetings deliberately have different scopes.
            $headToHeadGames = $this->priorHeadToHeadGames($game);
        }

        $rows = [
            $this->makeRow(
                key: 'head_to_head',
                label: 'Head-to-head',
                subtitle: $this->seasonScopeSubtitle($game, headToHead: true),
                awayRecord: $this->recordForHeadToHead($headToHeadGames, (int) $awayTeam->getKey()),
                homeRecord: $this->recordForHeadToHead($headToHeadGames, (int) $homeTeam->getKey()),
            ),
            $this->makeRow(
                key: 'overall',
                label: 'Record entering game',
                subtitle: $this->seasonScopeSubtitle($game),
                awayRecord: $this->recordFromGames($awayGames, (int) $awayTeam->getKey()),
                homeRecord: $this->recordFromGames($homeGames, (int) $homeTeam->getKey()),
            ),
            $this->makeRow(
                key: 'role_record',
                label: 'Current role record',
                subtitle: 'Away/home split',
                awayRecord: $this->recordFromGames(
                    $awayGames->filter(fn (Model $item): bool => (int) $item->away_team_id === (int) $awayTeam->getKey()),
                    (int) $awayTeam->getKey(),
                ),
                homeRecord: $this->recordFromGames(
                    $homeGames->filter(fn (Model $item): bool => (int) $item->home_team_id === (int) $homeTeam->getKey()),
                    (int) $homeTeam->getKey(),
                ),
            ),
        ];
        if ($game instanceof NflGame) {
            $rows[0]['scope'] = 'available_prior_seasons';
            $rows[0]['latest_meeting'] = $this->latestMeeting($headToHeadGames, $awayTeam, $homeTeam);
            $rows[0]['subtitle'] = $this->historicalScopeSubtitle($game);
        }

        $conferenceRow = $this->buildAlignmentRow(
            key: 'conference_record',
            label: 'Conference record',
            subtitle: $this->seasonScopeSubtitle($game),
            gamesBySide: ['away' => $awayGames, 'home' => $homeGames],
            teamsBySide: ['away' => $awayTeam, 'home' => $homeTeam],
            axis: 'conference',
        );

        if ($conferenceRow !== null) {
            $rows[] = $conferenceRow;
        }

        $divisionRow = $this->buildAlignmentRow(
            key: 'division_record',
            label: 'Division record',
            subtitle: $this->seasonScopeSubtitle($game),
            gamesBySide: ['away' => $awayGames, 'home' => $homeGames],
            teamsBySide: ['away' => $awayTeam, 'home' => $homeTeam],
            axis: 'division',
        );

        if ($divisionRow !== null) {
            $rows[] = $divisionRow;
        }

        $timeBucket = $this->gameTimeBucket($game);
        if ($timeBucket !== null) {
            $rows[] = $this->makeRow(
                key: 'time_bucket_record',
                label: sprintf('%s record', $timeBucket['label']),
                subtitle: $this->seasonScopeSubtitle($game).($game instanceof NflGame
                    ? ' · '.config('trends.timezones.nfl.display', 'America/New_York').' kickoff'
                    : ''),
                awayRecord: $this->recordFromGames(
                    $awayGames->filter(fn (Model $item): bool => $this->matchesTimeBucket($item, $timeBucket['bucket'])),
                    (int) $awayTeam->getKey(),
                ),
                homeRecord: $this->recordFromGames(
                    $homeGames->filter(fn (Model $item): bool => $this->matchesTimeBucket($item, $timeBucket['bucket'])),
                    (int) $homeTeam->getKey(),
                ),
            );
        }

        $starterRow = $this->buildStarterRow($game, $config, $awayTeam, $homeTeam, $awayGames, $homeGames);
        if ($starterRow !== null) {
            $rows[] = $starterRow;
        }

        return ['rows' => array_values(array_filter($rows))];
    }

    /**
     * @return array{game_model: class-string<Model>, player_stat_model?: class-string<Model>, player_model?: class-string<Model>, starter_type?: string}|array{game_model: class-string<Model>}
     */
    protected function configForGame(Model $game): array
    {
        return match ($game::class) {
            Game::class => [
                'game_model' => Game::class,
                'player_stat_model' => PlayerStat::class,
                'player_model' => Player::class,
                'starter_type' => 'pitcher',
            ],
            NflGame::class => [
                'game_model' => NflGame::class,
                'player_stat_model' => \App\Models\NFL\PlayerStat::class,
                'player_model' => \App\Models\NFL\Player::class,
                'starter_type' => 'qb',
            ],
            \App\Models\CFB\Game::class => [
                'game_model' => \App\Models\CFB\Game::class,
                'player_stat_model' => \App\Models\CFB\PlayerStat::class,
                'player_model' => \App\Models\CFB\Player::class,
                'starter_type' => 'qb',
            ],
            \App\Models\NBA\Game::class => ['game_model' => \App\Models\NBA\Game::class],
            \App\Models\WNBA\Game::class => ['game_model' => \App\Models\WNBA\Game::class],
            \App\Models\CBB\Game::class => ['game_model' => \App\Models\CBB\Game::class],
            \App\Models\WCBB\Game::class => ['game_model' => \App\Models\WCBB\Game::class],
            default => throw new \RuntimeException('Unsupported game model: '.$game::class),
        };
    }

    protected function priorSeasonGamesForTeam(Model $game, int $teamId): EloquentCollection
    {
        $query = $this->baseGameQuery($game)
            ->where('season', (int) $game->season)
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            });

        return $query->get();
    }

    /**
     * @param  list<int>  $teamIds
     */
    protected function priorSeasonGamesForTeams(Model $game, array $teamIds): EloquentCollection
    {
        return $this->baseGameQuery($game)
            ->when($game instanceof NflGame, fn ($query) => $query
                ->select(['id', 'season', 'game_date', 'game_time', 'home_team_id', 'away_team_id', 'home_score', 'away_score'])
                ->with(['homeTeam:id,abbreviation,conference,division', 'awayTeam:id,abbreviation,conference,division']))
            ->where('season', (int) $game->season)
            ->where(function (Builder $query) use ($teamIds): void {
                $query->whereIn('home_team_id', $teamIds)
                    ->orWhereIn('away_team_id', $teamIds);
            })
            ->get();
    }

    protected function priorHeadToHeadGames(Model $game): EloquentCollection
    {
        $homeTeamId = (int) $game->home_team_id;
        $awayTeamId = (int) $game->away_team_id;

        return $this->baseGameQuery($game)
            ->without(['homeTeam', 'awayTeam'])
            ->select(['id', 'season', 'game_date', 'game_time', 'home_team_id', 'away_team_id', 'home_score', 'away_score'])
            ->where(function ($query) use ($homeTeamId, $awayTeamId): void {
                $query->where(function ($inner) use ($homeTeamId, $awayTeamId): void {
                    $inner->where('home_team_id', $homeTeamId)
                        ->where('away_team_id', $awayTeamId);
                })->orWhere(function ($inner) use ($homeTeamId, $awayTeamId): void {
                    $inner->where('home_team_id', $awayTeamId)
                        ->where('away_team_id', $homeTeamId);
                });
            })
            ->get();
    }

    protected function baseGameQuery(Model $game): Builder
    {
        $relations = ['homeTeam', 'awayTeam'];
        if ($game instanceof Game) {
            $relations[] = 'teamStats';
        }

        $query = $game::query()
            ->with($relations)
            ->where('status', 'STATUS_FINAL');

        if (! $game instanceof Game) {
            $query->whereNotNull('home_score')->whereNotNull('away_score');
        }

        $this->applyMatchupContextSeasonTypeFilter($query, $game);

        return $this->applyBeforeGameFilter($query, $game);
    }

    protected function applyBeforeGameFilter(Builder $query, Model $game): Builder
    {
        $gameDate = $game->game_date?->toDateString() ?? (string) $game->game_date;
        $gameTime = $this->normalizeTimeValue($game->game_time);

        return $query->where(function ($inner) use ($gameDate, $gameTime): void {
            $inner->whereDate('game_date', '<', $gameDate)
                ->when($gameTime !== null, fn ($query) => $query->orWhere(function ($sameDate) use ($gameDate, $gameTime): void {
                    $sameDate->whereDate('game_date', '=', $gameDate);

                    if ($gameTime !== null) {
                        $sameDate->whereTime('game_time', '<', $gameTime);
                    }
                }));
        });
    }

    protected function historicalScopeSubtitle(Model $game): string
    {
        return $this->isPostseasonSeasonType($game->season_type)
            ? 'Available prior seasons · regular + postseason · before kickoff'
            : 'Available prior seasons · same season type · before kickoff';
    }

    protected function latestMeeting(Collection $games, Model $awayTeam, Model $homeTeam): ?array
    {
        $latest = $games->sortByDesc(fn (Model $item): string => sprintf('%s %s',
            $item->game_date?->toDateString() ?? (string) $item->game_date,
            $this->normalizeTimeValue($item->game_time) ?? '00:00:00',
        ))->first();
        if (! $latest) {
            return null;
        }
        $teams = collect([$awayTeam, $homeTeam])->keyBy(fn (Model $team) => $team->getKey());
        $date = GameDateTimePresenter::forSport('nfl', $latest->game_date, $latest->game_time);

        return [
            'game_id' => (int) $latest->getKey(),
            'season' => (int) $latest->season,
            'game_date' => $date['game_date'],
            'away_team_id' => (int) $latest->away_team_id,
            'home_team_id' => (int) $latest->home_team_id,
            'away_abbreviation' => $teams->get((int) $latest->away_team_id)?->abbreviation,
            'home_abbreviation' => $teams->get((int) $latest->home_team_id)?->abbreviation,
            'away_score' => (int) $latest->away_score,
            'home_score' => (int) $latest->home_score,
        ];
    }

    /**
     * @return array{wins:int, losses:int, ties:int, games:int, display:string}
     */
    protected function recordFromGames(Collection $games, int $teamId): array
    {
        $wins = 0;
        $losses = 0;
        $ties = 0;

        foreach ($games as $item) {
            $result = $this->teamResult($item, $teamId);
            if ($result === 'win') {
                $wins++;
            } elseif ($result === 'loss') {
                $losses++;
            } elseif ($result === 'tie') {
                $ties++;
            }
        }

        return $this->formatRecord($wins, $losses, $ties);
    }

    /**
     * @return array{wins:int, losses:int, ties:int, games:int, display:string}
     */
    protected function recordForHeadToHead(Collection $games, int $teamId): array
    {
        return $this->recordFromGames($games, $teamId);
    }

    protected function teamResult(Model $game, int $teamId): ?string
    {
        if ($game instanceof Game) {
            $resolved = app(MlbGameScoreResolver::class)->forTeam($game, $teamId);
            $rawTeamScore = $resolved['team'];
            $rawOpponentScore = $resolved['opponent'];
        } else {
            $isHome = (int) $game->home_team_id === $teamId;
            $rawTeamScore = $isHome ? $game->home_score : $game->away_score;
            $rawOpponentScore = $isHome ? $game->away_score : $game->home_score;
        }

        if (! is_numeric($rawTeamScore) || ! is_numeric($rawOpponentScore)) {
            return null;
        }

        $teamScore = (int) $rawTeamScore;
        $opponentScore = (int) $rawOpponentScore;

        if ($teamScore === $opponentScore) {
            return 'tie';
        }

        return $teamScore > $opponentScore ? 'win' : 'loss';
    }

    /**
     * @return array{wins:int, losses:int, ties:int, games:int, display:string}
     */
    protected function formatRecord(int $wins, int $losses, int $ties = 0): array
    {
        $display = $ties > 0
            ? sprintf('%d-%d-%d', $wins, $losses, $ties)
            : sprintf('%d-%d', $wins, $losses);

        return [
            'wins' => $wins,
            'losses' => $losses,
            'ties' => $ties,
            'games' => $wins + $losses + $ties,
            'display' => $display,
        ];
    }

    /**
     * @param  array{away: Collection<int, Model>, home: Collection<int, Model>}  $gamesBySide
     * @param  array{away: Model, home: Model}  $teamsBySide
     * @return array<string, mixed>|null
     */
    protected function buildAlignmentRow(
        string $key,
        string $label,
        string $subtitle,
        array $gamesBySide,
        array $teamsBySide,
        string $axis,
    ): ?array {
        $awayValue = $this->normalizedAlignmentValue($teamsBySide['away'], $axis);
        $homeValue = $this->normalizedAlignmentValue($teamsBySide['home'], $axis);

        if ($awayValue === null && $homeValue === null) {
            return null;
        }

        return $this->makeRow(
            key: $key,
            label: $label,
            subtitle: $subtitle,
            awayRecord: $this->recordFromGames(
                $gamesBySide['away']->filter(
                    fn (Model $item): bool => $this->opponentMatchesAlignment($item, (int) $teamsBySide['away']->getKey(), $awayValue, $axis),
                ),
                (int) $teamsBySide['away']->getKey(),
            ),
            homeRecord: $this->recordFromGames(
                $gamesBySide['home']->filter(
                    fn (Model $item): bool => $this->opponentMatchesAlignment($item, (int) $teamsBySide['home']->getKey(), $homeValue, $axis),
                ),
                (int) $teamsBySide['home']->getKey(),
            ),
        );
    }

    protected function normalizedAlignmentValue(Model $team, string $axis): ?string
    {
        $value = trim((string) ($team->getAttribute($axis) ?? ''));

        return $value === '' ? null : strtolower($value);
    }

    protected function opponentMatchesAlignment(Model $game, int $teamId, ?string $alignmentValue, string $axis): bool
    {
        if ($alignmentValue === null) {
            return false;
        }

        $opponent = (int) $game->home_team_id === $teamId ? $game->awayTeam : $game->homeTeam;
        if (! $opponent) {
            return false;
        }

        return $this->normalizedAlignmentValue($opponent, $axis) === $alignmentValue;
    }

    /**
     * @return array{bucket:string, label:string}|null
     */
    protected function gameTimeBucket(Model $game): ?array
    {
        $time = $this->normalizeTimeValue($game->game_time);
        if ($game instanceof NflGame || $game instanceof \App\Models\CFB\Game) {
            $sport = $game instanceof NflGame ? 'nfl' : 'cfb';
            $time = GameDateTimePresenter::forSport($sport, $game->game_date, $time)['game_time'];
        }
        if ($time === null) {
            return null;
        }

        [$hour] = array_pad(explode(':', $time), 2, '00');
        $bucket = (int) $hour < 17 ? 'day' : 'night';

        return [
            'bucket' => $bucket,
            'label' => ucfirst($bucket),
        ];
    }

    protected function matchesTimeBucket(Model $game, string $bucket): bool
    {
        return ($this->gameTimeBucket($game)['bucket'] ?? null) === $bucket;
    }

    protected function normalizeTimeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 8);
    }

    protected function applySeasonTypeFilter(Builder $query, Model $game, string $column = 'season_type'): void
    {
        $seasonTypes = $this->seasonTypeVariants($game->getAttribute('season_type'));

        if ($seasonTypes === []) {
            return;
        }

        $query->whereIn($column, $seasonTypes);
    }

    protected function applyMatchupContextSeasonTypeFilter(Builder $query, Model $game, string $column = 'season_type'): void
    {
        $seasonTypes = $this->matchupContextSeasonTypeVariants($game);

        if ($seasonTypes === []) {
            return;
        }

        $query->whereIn($column, $seasonTypes);
    }

    /**
     * Matchup-context records are meant to describe what each team has shown
     * entering this game. For postseason games, regular-season meetings and
     * records are still meaningful context, while preseason should stay out.
     *
     * @return array<int, string>
     */
    protected function matchupContextSeasonTypeVariants(Model $game): array
    {
        $seasonType = $game->getAttribute('season_type');

        if ($this->isPostseasonSeasonType($seasonType)) {
            return array_values(array_unique([
                ...$this->seasonTypeVariants('2'),
                ...$this->seasonTypeVariants('3'),
            ]));
        }

        return $this->seasonTypeVariants($seasonType);
    }

    protected function isPostseasonSeasonType(mixed $seasonType): bool
    {
        if ($seasonType === null) {
            return false;
        }

        $value = strtolower(trim((string) $seasonType));

        return in_array($value, ['3', 'postseason', 'post season', 'playoffs'], true);
    }

    protected function seasonScopeSubtitle(Model $game, bool $headToHead = false): string
    {
        if ($this->isPostseasonSeasonType($game->getAttribute('season_type'))) {
            return $headToHead
                ? 'Current season series before game'
                : 'Regular + postseason before game';
        }

        return $headToHead
            ? 'Prior same-type matchups'
            : 'Season to date';
    }

    /**
     * @return array<int, string>
     */
    protected function seasonTypeVariants(mixed $seasonType): array
    {
        if ($seasonType === null) {
            return [];
        }

        $value = trim((string) $seasonType);

        if ($value === '') {
            return [];
        }

        $variants = [$value];
        $normalized = strtolower($value);

        if (ctype_digit($value)) {
            $variants = [...$variants, ...match ((int) $value) {
                1 => ['Preseason', 'Pre Season'],
                2 => ['Regular Season', 'Regular'],
                3 => ['Postseason', 'Post Season', 'Playoffs'],
                default => [],
            }];
        } else {
            $variants = [...$variants, ...match ($normalized) {
                'preseason', 'pre season' => ['1'],
                'regular season', 'regular' => ['2'],
                'postseason', 'post season', 'playoffs' => ['3'],
                default => [],
            }];
        }

        return array_values(array_unique($variants));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildStarterRow(
        Model $game,
        array $config,
        Model $awayTeam,
        Model $homeTeam,
        Collection $awayGames,
        Collection $homeGames,
    ): ?array {
        $starterType = $config['starter_type'] ?? null;

        if ($starterType === 'pitcher') {
            return $this->buildPitcherRow($game, $awayTeam, $homeTeam);
        }

        if ($starterType === 'qb') {
            return $this->buildQuarterbackRow($game, $config, $awayTeam, $homeTeam, $awayGames, $homeGames);
        }

        return null;
    }

    protected function buildPitcherRow(Model $game, Model $awayTeam, Model $homeTeam): ?array
    {
        $homePitcherId = trim((string) ($game->probable_home_pitcher_espn_id ?? ''));
        $awayPitcherId = trim((string) ($game->probable_away_pitcher_espn_id ?? ''));

        if ($homePitcherId === '' && $awayPitcherId === '') {
            return null;
        }

        $homePitcher = $homePitcherId !== ''
            ? Player::query()->where('espn_id', $homePitcherId)->first()
            : null;
        $awayPitcher = $awayPitcherId !== ''
            ? Player::query()->where('espn_id', $awayPitcherId)->first()
            : null;

        return $this->makeRow(
            key: 'starter_matchup',
            label: 'Record vs probable starter',
            subtitle: trim(collect([
                $homePitcher?->full_name ? 'vs '.$homePitcher->full_name : null,
                $awayPitcher?->full_name ? 'vs '.$awayPitcher->full_name : null,
            ])->filter()->implode(' / ')),
            awayRecord: $homePitcherId !== ''
                ? $this->recordVsProbablePitcher($game, (int) $awayTeam->getKey(), $homePitcherId)
                : $this->formatRecord(0, 0),
            homeRecord: $awayPitcherId !== ''
                ? $this->recordVsProbablePitcher($game, (int) $homeTeam->getKey(), $awayPitcherId)
                : $this->formatRecord(0, 0),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildQuarterbackRow(
        Model $game,
        array $config,
        Model $awayTeam,
        Model $homeTeam,
        Collection $awayGames,
        Collection $homeGames,
    ): ?array {
        $playerModel = $config['player_model'] ?? null;

        if (! is_string($playerModel)) {
            return null;
        }

        $homeQuarterbackId = $this->resolveLikelyQuarterbackId($game, $config, (int) $homeTeam->getKey(), $homeGames);
        $awayQuarterbackId = $this->resolveLikelyQuarterbackId($game, $config, (int) $awayTeam->getKey(), $awayGames);

        if ($homeQuarterbackId === null && $awayQuarterbackId === null) {
            return null;
        }

        $quarterbacks = $playerModel::query()->whereIn('id', array_filter([$homeQuarterbackId, $awayQuarterbackId]))->get()->keyBy('id');
        $homeQuarterback = $quarterbacks->get($homeQuarterbackId);
        $awayQuarterback = $quarterbacks->get($awayQuarterbackId);

        return $this->makeRow(
            key: 'starter_matchup',
            label: 'Record vs likely QB',
            subtitle: $this->historicalScopeSubtitle($game).' · '.trim(collect([
                $homeQuarterback?->full_name ? 'vs '.$homeQuarterback->full_name : null,
                $awayQuarterback?->full_name ? 'vs '.$awayQuarterback->full_name : null,
            ])->filter()->implode(' / ')),
            awayRecord: $homeQuarterbackId !== null
                ? $this->recordVsPlayer($game, $config, (int) $awayTeam->getKey(), $homeQuarterbackId, 'passing_attempts')
                : $this->formatRecord(0, 0),
            homeRecord: $awayQuarterbackId !== null
                ? $this->recordVsPlayer($game, $config, (int) $homeTeam->getKey(), $awayQuarterbackId, 'passing_attempts')
                : $this->formatRecord(0, 0),
        );
    }

    protected function recordVsProbablePitcher(Model $game, int $teamId, string $pitcherEspnId): array
    {
        $games = $this->baseGameQuery($game)
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->where(function ($query) use ($teamId, $pitcherEspnId): void {
                $query->where(function ($inner) use ($teamId, $pitcherEspnId): void {
                    $inner->where('home_team_id', $teamId)
                        ->where('probable_away_pitcher_espn_id', $pitcherEspnId);
                })->orWhere(function ($inner) use ($teamId, $pitcherEspnId): void {
                    $inner->where('away_team_id', $teamId)
                        ->where('probable_home_pitcher_espn_id', $pitcherEspnId);
                });
            })
            ->get();

        return $this->recordFromGames($games, $teamId);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveLikelyQuarterbackId(Model $game, array $config, int $teamId, Collection $priorTeamGames): ?int
    {
        $unavailable = [];
        if ($game instanceof NflGame) {
            $unavailable = app(QuarterbackAvailability::class)->forGame($game, $teamId);
            $depthChart = $this->availableDepthChartQuarterback($game, $teamId, $unavailable);
            if ($depthChart['has_candidates']) {
                return $depthChart['player_id'];
            }
        }
        if ($priorTeamGames->isEmpty()) {
            return null;
        }

        $playerStatModel = $config['player_stat_model'] ?? null;
        $playerModel = $config['player_model'] ?? null;
        if (! is_string($playerStatModel) || ! is_string($playerModel)) {
            return null;
        }

        $latestGameId = $priorTeamGames->sortByDesc(function (Model $item): string {
            return sprintf('%s %s', $item->game_date?->toDateString() ?? (string) $item->game_date, $this->normalizeTimeValue($item->game_time) ?? '00:00:00');
        })->first()?->getKey();

        if (! $latestGameId) {
            return null;
        }

        $playerTable = (new $playerModel)->getTable();
        $statTable = (new $playerStatModel)->getTable();

        $stat = $playerStatModel::query()
            ->select(["{$statTable}.player_id", "{$playerTable}.full_name"])
            ->join($playerTable, "{$playerTable}.id", '=', "{$statTable}.player_id")
            ->where("{$statTable}.game_id", $latestGameId)
            ->where("{$statTable}.team_id", $teamId)
            ->where($playerTable.'.position', 'QB')
            ->where("{$statTable}.passing_attempts", '>', 0)
            ->orderByDesc("{$statTable}.passing_attempts")
            ->first();

        if ($stat && $game instanceof NflGame && app(QuarterbackAvailability::class)->excludes($unavailable, $stat->player_id, $stat->full_name)) {
            return null;
        }

        return $stat ? (int) $stat->player_id : null;
    }

    /** @return array{has_candidates:bool, player_id:?int} */
    protected function availableDepthChartQuarterback(NflGame $game, int $teamId, array $unavailable): array
    {
        $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        $cutoff = $kickoff && $kickoff->lt(now()) ? $kickoff : now();
        $snapshot = DepthChartSnapshot::query()->with(['entries' => fn ($q) => $q->with('player')
            ->where('observed_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', $cutoff))])
            ->where('team_id', $teamId)->where('season', $game->season)
            ->where('observed_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', $cutoff))
            ->latest('observed_at')->latest('id')->first();
        $candidates = $snapshot ? $snapshot->entries->filter(fn ($entry) => strtoupper((string) ($entry->position_code ?: $entry->position_slot_key)) === 'QB')
            : DepthChartEntry::query()->with('player')->where('team_id', $teamId)->where('season', $game->season)
                ->where('position_code', 'QB')->whereNotNull('source_updated_at')
                ->where('source_updated_at', '<=', $cutoff)->where('updated_at', '<=', $cutoff)->get();
        $available = $candidates->filter(fn ($entry) => $entry->player_id && ! app(QuarterbackAvailability::class)->excludes($unavailable, $entry->player_id, $entry->player?->full_name))
            ->sortBy([['depth_rank', 'asc'], ['slot_order', 'asc']]);
        $first = $available->first();
        $ambiguous = $first && (! is_numeric($first->depth_rank)
            || $available->where('depth_rank', $first->depth_rank)->pluck('player_id')->unique()->count() > 1);

        return ['has_candidates' => $candidates->isNotEmpty(), 'player_id' => $first && ! $ambiguous ? (int) $first->player_id : null];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function recordVsPlayer(
        Model $game,
        array $config,
        int $teamId,
        int $playerId,
        string $attemptField,
    ): array {
        $playerStatModel = $config['player_stat_model'] ?? null;
        if (! is_string($playerStatModel)) {
            return $this->formatRecord(0, 0);
        }

        $statTable = (new $playerStatModel)->getTable();
        $gameTable = $game->getTable();

        $games = $game::query()
            ->where("{$gameTable}.status", 'STATUS_FINAL')
            ->whereNotNull("{$gameTable}.home_score")->whereNotNull("{$gameTable}.away_score")
            ->where(fn ($query) => $query->where("{$gameTable}.home_team_id", $teamId)->orWhere("{$gameTable}.away_team_id", $teamId))
            ->whereExists(function ($query) use ($statTable, $gameTable, $playerId, $attemptField, $teamId): void {
                $query->selectRaw('1')->from($statTable)
                    ->whereColumn("{$statTable}.game_id", "{$gameTable}.id")
                    ->where("{$statTable}.player_id", $playerId)
                    ->where("{$statTable}.{$attemptField}", '>', 0)
                    ->where(function ($query) use ($teamId, $gameTable, $statTable): void {
                        $query->where(function ($inner) use ($teamId, $gameTable, $statTable): void {
                            $inner->where("{$gameTable}.home_team_id", $teamId)
                                ->whereColumn("{$statTable}.team_id", "{$gameTable}.away_team_id");
                        })->orWhere(function ($inner) use ($teamId, $gameTable, $statTable): void {
                            $inner->where("{$gameTable}.away_team_id", $teamId)
                                ->whereColumn("{$statTable}.team_id", "{$gameTable}.home_team_id");
                        });
                    });
            });

        $this->applyMatchupContextSeasonTypeFilter($games, $game, "{$gameTable}.season_type");

        $games = $this->applyBeforeGameFilter($games, $game)
            ->select(["{$gameTable}.id", "{$gameTable}.home_team_id", "{$gameTable}.away_team_id", "{$gameTable}.home_score", "{$gameTable}.away_score"])
            ->get();

        return $this->recordFromGames($games, $teamId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeRow(
        string $key,
        string $label,
        string $subtitle,
        array $awayRecord,
        array $homeRecord,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'subtitle' => $subtitle,
            'away' => $awayRecord,
            'home' => $homeRecord,
        ];
    }
}
