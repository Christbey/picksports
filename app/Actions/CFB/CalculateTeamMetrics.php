<?php

namespace App\Actions\CFB;

use App\Actions\Sports\Concerns\CalculatesGridironTeamMetrics;
use App\Actions\Sports\Concerns\CalculatesTeamTrueEpaFromPlays;
use App\Concerns\FiltersTeamGames;
use App\Models\CFB\EloRating;
use App\Models\CFB\FpiRating;
use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Database\Eloquent\Collection;

class CalculateTeamMetrics
{
    use CalculatesGridironTeamMetrics, FiltersTeamGames;
    use CalculatesTeamTrueEpaFromPlays;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $seasonWepaIndex = null;

    /**
     * @var array<string, int>|null
     */
    protected ?array $cfbdMappingIndex = null;

    public function __construct(
        private readonly CollegeFootballDataService $collegeFootballDataService,
        private readonly CfbSeasonAffiliationResolver $seasonAffiliationResolver,
    ) {}

    public function execute(Team $team, int $season): ?TeamMetric
    {
        if (! $this->seasonAffiliationResolver->isFbs($team, $season)) {
            return null;
        }

        $fpi = $this->latestFpiForTeam($team, $season);
        $wepa = $this->wepaForTeam($team, $season);
        $games = $this->getCompletedGamesForTeam($team, $season, 'CFB');

        if ($games->isEmpty()) {
            return $this->buildPreseasonMetric($team, $season, $fpi, $wepa);
        }

        extract($this->gatherTeamStatsFromGames($games, $team));

        // Gather CFB-specific points data
        $pointsScored = [];
        $pointsAllowed = [];

        foreach ($games as $game) {
            $isHome = $game->home_team_id === $team->id;

            if ($isHome) {
                $pointsScored[] = $game->home_score ?? 0;
                $pointsAllowed[] = $game->away_score ?? 0;
            } else {
                $pointsScored[] = $game->away_score ?? 0;
                $pointsAllowed[] = $game->home_score ?? 0;
            }
        }

        if (empty($teamStats)) {
            return null;
        }

        $record = $this->calculateWinLossRecord($games, $team);

        // Calculate metrics
        $pointsPerGame = $this->calculateAverage($pointsScored);
        $pointsAllowedPerGame = $this->calculateAverage($pointsAllowed);
        $offensiveRating = $pointsPerGame;
        $defensiveRating = $pointsAllowedPerGame;
        $netRating = $offensiveRating - $defensiveRating;

        $yardsPerGame = $this->calculateAverageYards($teamStats);
        $yardsAllowedPerGame = $this->calculateAverageYards($opponentStats);
        $passingYardsPerGame = $this->calculateAveragePassingYards($teamStats);
        $rushingYardsPerGame = $this->calculateAverageRushingYards($teamStats);
        $turnoverDifferential = $this->calculateTurnoverDifferential($teamStats, $opponentStats);
        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);
        $recentFormRating = $this->calculateRecentFormRating($games, $team);
        $injuryAdjustedTeamRating = $this->calculateInjuryAdjustedTeamRating($team, 'cfb', (float) ($team->elo_rating ?? 1500));
        $restTravelFatigue = $this->calculateRestTravelFatigue($games, $team);
        $pregameEloIndex = $this->pregameEloIndex($games);
        $powerRating = $this->calculatePowerRating(
            netRating: $netRating,
            strengthOfSchedule: $strengthOfSchedule,
            recentFormRating: $recentFormRating,
            fpi: $fpi,
            wepaNet: $wepa['net']
        );
        $resumeRating = $this->calculateResumeRating(
            team: $team,
            season: $season,
            games: $games,
            record: $record,
            pregameEloIndex: $pregameEloIndex,
            strengthOfSchedule: $strengthOfSchedule,
        );
        $cfpRating = $this->calculateCfpRating($powerRating, $resumeRating);
        $trueEpaMetrics = $this->calculateTeamTrueEpaMetrics(Play::class, (int) $team->id, $games, true);

        // Update or create team metric
        return $this->persistMetric($team, $season, [
            'wins' => $record['wins'],
            'losses' => $record['losses'],
            'fpi' => $fpi,
            'offensive_rating' => round($offensiveRating, 1),
            'defensive_rating' => round($defensiveRating, 1),
            'net_rating' => round($netRating, 1),
            'points_per_game' => round($pointsPerGame, 1),
            'points_allowed_per_game' => round($pointsAllowedPerGame, 1),
            'yards_per_game' => round($yardsPerGame, 1),
            'yards_allowed_per_game' => round($yardsAllowedPerGame, 1),
            'passing_yards_per_game' => round($passingYardsPerGame, 1),
            'rushing_yards_per_game' => round($rushingYardsPerGame, 1),
            'turnover_differential' => round($turnoverDifferential, 1),
            'strength_of_schedule' => round($strengthOfSchedule, 3),
            'recent_form_rating' => $recentFormRating,
            'injury_adjusted_team_rating' => $injuryAdjustedTeamRating,
            'rest_travel_fatigue' => $restTravelFatigue,
            'cfbd_wepa_offense' => $wepa['offense'],
            'cfbd_wepa_defense' => $wepa['defense'],
            'cfbd_wepa_net' => $wepa['net'],
            'cfbd_wepa_payload' => $wepa['payload'],
            'cfp_rating' => $cfpRating,
            'power_rating' => $powerRating,
            'resume_rating' => $resumeRating,
            'offensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['offensive_true_epa_per_play'], 3),
            'defensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['defensive_true_epa_per_play'], 3),
            'net_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['net_true_epa_per_play'], 3),
        ]);
    }

    public function executeForAllTeams(int $season): int
    {
        $this->purgeNonFbsMetrics($season);

        $teams = Team::query()->get();
        $calculated = 0;

        foreach ($teams as $team) {
            $metric = $this->execute($team, $season);
            if ($metric) {
                $calculated++;
            }
        }

        return $calculated;
    }

    public function purgeNonFbsMetrics(int $season): int
    {
        $nonFbsIds = Team::query()
            ->get()
            ->reject(fn (Team $team) => $this->seasonAffiliationResolver->isFbs($team, $season))
            ->pluck('id');

        if ($nonFbsIds->isEmpty()) {
            return 0;
        }

        return TeamMetric::query()
            ->where('season', $season)
            ->whereIn('team_id', $nonFbsIds)
            ->delete();
    }

    protected function latestFpiForTeam(Team $team, int $season): ?float
    {
        $rating = FpiRating::query()
            ->where('team_id', $team->id)
            ->where('season', $season)
            ->orderByDesc('week')
            ->first();

        $value = data_get($rating, 'fpi');

        if ($value === null) {
            $value = data_get($rating, 'fpi_rating');
        }

        return $value === null ? null : round((float) $value, 2);
    }

    /**
     * @param  array{offense: ?float, defense: ?float, net: ?float, payload: ?array<string, mixed>}  $wepa
     */
    protected function buildPreseasonMetric(Team $team, int $season, ?float $fpi, array $wepa): ?TeamMetric
    {
        if ($fpi === null && $wepa['net'] === null && $wepa['offense'] === null && $wepa['defense'] === null) {
            return null;
        }

        $powerRating = $this->calculatePowerRating(
            netRating: 0.0,
            strengthOfSchedule: null,
            recentFormRating: null,
            fpi: $fpi,
            wepaNet: $wepa['net']
        );

        return $this->persistMetric($team, $season, [
            'wins' => 0,
            'losses' => 0,
            'fpi' => $fpi,
            'cfbd_wepa_offense' => $wepa['offense'],
            'cfbd_wepa_defense' => $wepa['defense'],
            'cfbd_wepa_net' => $wepa['net'],
            'cfbd_wepa_payload' => $wepa['payload'],
            'power_rating' => $powerRating,
            'resume_rating' => null,
            'cfp_rating' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function persistMetric(Team $team, int $season, array $attributes): TeamMetric
    {
        return TeamMetric::updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
            ],
            array_merge($attributes, [
                'calculation_date' => now()->toDateString(),
            ])
        );
    }

    /**
     * @return array{offense: ?float, defense: ?float, net: ?float, payload: ?array<string, mixed>}
     */
    protected function wepaForTeam(Team $team, int $season): array
    {
        $cfbdTeamId = $this->cfbdTeamIdForTeam($team);

        if ($cfbdTeamId === null) {
            return ['offense' => null, 'defense' => null, 'net' => null, 'payload' => null];
        }

        $row = $this->seasonWepaIndex($season)[$cfbdTeamId] ?? null;

        if (! is_array($row)) {
            return ['offense' => null, 'defense' => null, 'net' => null, 'payload' => null];
        }

        $offense = $this->extractMetricValue($row, [
            'offense',
            'offensive',
            'wepaOff',
            'offensiveWepa',
            'offensive_wepa',
            'wepa_offense',
        ]);
        $defense = $this->extractMetricValue($row, [
            'defense',
            'defensive',
            'wepaDef',
            'defensiveWepa',
            'defensive_wepa',
            'wepa_defense',
        ]);
        $net = $this->extractMetricValue($row, [
            'net',
            'wepa',
            'wepaNet',
            'netWepa',
            'net_wepa',
            'wepa_net',
        ]);

        if ($net === null && $offense !== null && $defense !== null) {
            $net = round($offense - $defense, 4);
        }

        return [
            'offense' => $offense,
            'defense' => $defense,
            'net' => $net,
            'payload' => $row,
        ];
    }

    protected function cfbdTeamIdForTeam(Team $team): ?int
    {
        if ($team->cfbd_team_id) {
            return (int) $team->cfbd_team_id;
        }

        $mappingIndex = $this->cfbdMappingIndex();
        $candidateNames = array_filter([
            $team->school,
            $team->display_name,
            $team->name,
            $team->short_display_name,
        ]);

        foreach ($candidateNames as $name) {
            $cfbdTeamId = $mappingIndex[mb_strtolower(trim((string) $name))] ?? null;

            if ($cfbdTeamId === null) {
                continue;
            }

            $team->forceFill(['cfbd_team_id' => $cfbdTeamId])->saveQuietly();

            return $cfbdTeamId;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function seasonWepaIndex(int $season): array
    {
        if ($this->seasonWepaIndex !== null) {
            return $this->seasonWepaIndex;
        }

        try {
            $rows = $this->collegeFootballDataService->getWepaTeamSeason($season);
        } catch (\Throwable) {
            $this->seasonWepaIndex = [];

            return $this->seasonWepaIndex;
        }

        $this->seasonWepaIndex = collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->mapWithKeys(function (array $row): array {
                $teamId = data_get($row, 'id');

                if ($teamId === null) {
                    $teamId = data_get($row, 'teamId');
                }

                $teamId = is_numeric($teamId) ? (int) $teamId : null;

                return $teamId ? [$teamId => $row] : [];
            })
            ->all();

        return $this->seasonWepaIndex;
    }

    /**
     * @return array<string, int>
     */
    protected function cfbdMappingIndex(): array
    {
        if ($this->cfbdMappingIndex !== null) {
            return $this->cfbdMappingIndex;
        }

        $this->cfbdMappingIndex = CfbdTeamMapping::query()
            ->whereNotNull('espn_team_name')
            ->get(['espn_team_name', 'cfbd_team_id'])
            ->mapWithKeys(fn (CfbdTeamMapping $mapping): array => [
                mb_strtolower(trim((string) $mapping->espn_team_name)) => (int) $mapping->cfbd_team_id,
            ])
            ->all();

        return $this->cfbdMappingIndex;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    protected function extractMetricValue(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($row, $key);

            if ($value !== null && is_numeric($value)) {
                return round((float) $value, 4);
            }
        }

        return null;
    }

    protected function calculatePowerRating(
        float $netRating,
        ?float $strengthOfSchedule,
        ?float $recentFormRating,
        ?float $fpi,
        ?float $wepaNet
    ): float {
        $sosComponent = (($strengthOfSchedule ?? 1500.0) - 1500.0) * 0.020;
        $recentComponent = (float) ($recentFormRating ?? 0.0) * 0.180;
        $fpiComponent = (float) ($fpi ?? 0.0) * 0.150;
        $wepaComponent = (float) ($wepaNet ?? 0.0) * 4.000;
        $cappedNetRating = min(18.0, $netRating);

        return round(
            ($cappedNetRating * 0.400)
            + $sosComponent
            + $recentComponent
            + $fpiComponent
            + $wepaComponent,
            3
        );
    }

    /**
     * @param  array<string, float>  $pregameEloIndex
     */
    protected function calculateResumeRating(
        Team $team,
        int $season,
        Collection $games,
        array $record,
        array $pregameEloIndex,
        ?float $strengthOfSchedule,
    ): float {
        $fbsRecord = $this->calculateFbsRecord($games, $team, $season);
        $gamesPlayed = max(1, (int) (($fbsRecord['wins'] ?? 0) + ($fbsRecord['losses'] ?? 0)));
        $winPct = ($fbsRecord['wins'] ?? 0) / $gamesPlayed;
        $nonFbsWins = max(0, (int) ($record['wins'] ?? 0) - (int) ($fbsRecord['wins'] ?? 0));
        $nonFbsLosses = max(0, (int) ($record['losses'] ?? 0) - (int) ($fbsRecord['losses'] ?? 0));

        $score = ($winPct * 30.0)
            + ((($strengthOfSchedule ?? 1500.0) - 1500.0) * 0.070)
            + $this->conferenceResumeAdjustment($team, $season);

        foreach ($games as $game) {
            $score += $this->resumeGameScore($team, $season, $game, $pregameEloIndex);
        }

        $score -= ($nonFbsWins * 1.5);
        $score -= ($nonFbsLosses * 6.0);
        $score += $this->eliteResumeBonus($strengthOfSchedule, $fbsRecord);

        return round($score, 3);
    }

    /**
     * @param  array<string, float>  $pregameEloIndex
     */
    protected function resumeGameScore(Team $team, int $season, Game $game, array $pregameEloIndex): float
    {
        $isHome = (int) $game->home_team_id === (int) $team->id;
        $opponent = $isHome ? $game->awayTeam : $game->homeTeam;

        if (! $opponent) {
            return 0.0;
        }

        $teamScore = (int) ($isHome ? ($game->home_score ?? 0) : ($game->away_score ?? 0));
        $opponentScore = (int) ($isHome ? ($game->away_score ?? 0) : ($game->home_score ?? 0));
        $margin = $teamScore - $opponentScore;
        $won = $margin > 0;
        $opponentIsFbs = $this->seasonAffiliationResolver->isFbs($opponent, $season);
        $opponentPregameElo = $pregameEloIndex[$game->id.'-'.$opponent->id] ?? (float) ($opponent->elo_rating ?? 1500);
        $neutralSite = (bool) ($game->neutral_site ?? false);
        $locationBonus = $neutralSite ? 0.25 : ($isHome ? 0.0 : 0.55);
        $championshipBonus = $this->isChampionshipValueGame($game) ? 0.75 : 0.0;
        $marginBonus = min(1.25, max(-1.25, $margin / 14.0));

        if (! $opponentIsFbs) {
            return $won ? -0.75 : -8.0;
        }

        $qualityWinBonus = match (true) {
            $opponentPregameElo >= 1650 => 13.0,
            $opponentPregameElo >= 1600 => 10.5,
            $opponentPregameElo >= 1550 => 8.0,
            $opponentPregameElo >= 1500 => 5.6,
            $opponentPregameElo >= 1450 => 2.8,
            default => 0.4,
        };

        $badLossPenalty = match (true) {
            $opponentPregameElo < 1350 => -16.0,
            $opponentPregameElo < 1400 => -12.5,
            $opponentPregameElo < 1450 => -9.0,
            $opponentPregameElo < 1500 => -5.5,
            $opponentPregameElo < 1550 => -3.0,
            default => -1.2,
        };

        $conferenceModifier = $this->conferenceGameModifier($team, $opponent, $season);

        if ($won) {
            return 0.8
                + $qualityWinBonus
                + $locationBonus
                + max(0.0, $marginBonus * 0.20)
                + $championshipBonus
                + $conferenceModifier;
        }

        $homeLossPenalty = $isHome && ! $neutralSite ? -1.0 : 0.0;

        return $badLossPenalty
            + $homeLossPenalty
            + min(0.0, $marginBonus * 0.15)
            + $conferenceModifier;
    }

    protected function calculateCfpRating(float $powerRating, float $resumeRating): float
    {
        $normalizedPower = $this->normalizePowerRating($powerRating);
        $normalizedResume = $this->normalizeResumeRating($resumeRating);

        return round(($normalizedResume * 0.68) + ($normalizedPower * 0.32), 3);
    }

    /**
     * @return array<string, float>
     */
    protected function pregameEloIndex(Collection $games): array
    {
        if ($games->isEmpty()) {
            return [];
        }

        return EloRating::query()
            ->whereIn('game_id', $games->pluck('id'))
            ->get()
            ->mapWithKeys(function ($record): array {
                $preGameElo = (float) $record->elo_rating - (float) $record->elo_change;

                return [$record->game_id.'-'.$record->team_id => $preGameElo];
            })
            ->all();
    }

    protected function isChampionshipValueGame(Game $game): bool
    {
        return (int) ($game->season_type ?? 0) === (int) config('cfb.season.types.postseason')
            && (int) ($game->week ?? 0) <= 2;
    }

    protected function conferenceResumeAdjustment(Team $team, int $season): float
    {
        $normalizedConference = $this->normalizeConference($this->conferenceForSeason($team, $season));

        if (in_array($normalizedConference, $this->powerConferences(), true)) {
            return 5.0;
        }

        if (in_array($normalizedConference, $this->groupOfFiveConferences(), true)) {
            return -7.0;
        }

        if ($normalizedConference === 'independent') {
            return 0.5;
        }

        return 0.0;
    }

    protected function conferenceGameModifier(Team $team, Team $opponent, int $season): float
    {
        $teamConference = $this->normalizeConference($this->conferenceForSeason($team, $season));
        $opponentConference = $this->normalizeConference($this->conferenceForSeason($opponent, $season));

        if ($opponentConference === '') {
            return 0.0;
        }

        if (in_array($opponentConference, $this->powerConferences(), true)) {
            return 2.0;
        }

        if (in_array($opponentConference, $this->groupOfFiveConferences(), true)) {
            return in_array($teamConference, $this->powerConferences(), true) ? -1.8 : -0.9;
        }

        return 0.0;
    }

    /**
     * @return array<int, string>
     */
    protected function powerConferences(): array
    {
        return array_map(
            fn (string $conference): string => $this->normalizeConference($conference),
            (array) config('cfb.teams.power_conferences', [])
        );
    }

    /**
     * @return array<int, string>
     */
    protected function groupOfFiveConferences(): array
    {
        return array_map(
            fn (string $conference): string => $this->normalizeConference($conference),
            (array) config('cfb.teams.group_of_five_conferences', [])
        );
    }

    protected function normalizeConference(?string $conference): string
    {
        return strtolower(trim((string) $conference));
    }

    /**
     * @return array{wins:int,losses:int}
     */
    protected function calculateFbsRecord(Collection $games, Team $team, int $season): array
    {
        $wins = 0;
        $losses = 0;

        foreach ($games as $game) {
            $isHome = (int) $game->home_team_id === (int) $team->id;
            $opponent = $isHome ? $game->awayTeam : $game->homeTeam;

            if (! $opponent || ! $this->seasonAffiliationResolver->isFbs($opponent, $season)) {
                continue;
            }

            $teamScore = (int) ($isHome ? ($game->home_score ?? 0) : ($game->away_score ?? 0));
            $opponentScore = (int) ($isHome ? ($game->away_score ?? 0) : ($game->home_score ?? 0));

            if ($teamScore > $opponentScore) {
                $wins++;
            } elseif ($teamScore < $opponentScore) {
                $losses++;
            }
        }

        return ['wins' => $wins, 'losses' => $losses];
    }

    protected function normalizePowerRating(float $powerRating): float
    {
        return round(50.0 + ($powerRating * 2.9), 3);
    }

    protected function normalizeResumeRating(float $resumeRating): float
    {
        return round(50.0 + ($resumeRating * 0.39), 3);
    }

    /**
     * @param  array{wins:int,losses:int}  $fbsRecord
     */
    protected function eliteResumeBonus(?float $strengthOfSchedule, array $fbsRecord): float
    {
        $wins = (int) ($fbsRecord['wins'] ?? 0);
        $losses = (int) ($fbsRecord['losses'] ?? 0);
        $sos = (float) ($strengthOfSchedule ?? 1500.0);

        return match (true) {
            $sos >= 1525.0 && $wins >= 10 && $losses <= 3 => 5.0,
            $sos >= 1515.0 && $wins >= 10 && $losses <= 3 => 3.0,
            $sos >= 1508.0 && $wins >= 9 && $losses <= 3 => 1.5,
            default => 0.0,
        };
    }

    protected function conferenceForSeason(Team $team, int $season): ?string
    {
        return $team->seasonAffiliation($season)?->conference ?? $team->conference;
    }
}
