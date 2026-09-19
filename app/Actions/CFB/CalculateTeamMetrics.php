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
use App\Models\CFB\TeamStat;
use App\Models\CfbdTeamMapping;
use App\Services\CFB\PlayerAvailabilityImpactService;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class CalculateTeamMetrics
{
    use CalculatesGridironTeamMetrics, FiltersTeamGames;
    use CalculatesTeamTrueEpaFromPlays;

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    protected array $seasonWepaIndex = [];

    /**
     * @var array<int, array<int|string, array<string, mixed>>>
     */
    protected array $seasonAdvancedStatsIndex = [];

    /**
     * @var array<string, int>|null
     */
    protected ?array $cfbdMappingIndex = null;

    public function __construct(
        private readonly CollegeFootballDataService $collegeFootballDataService,
        private readonly CfbSeasonAffiliationResolver $seasonAffiliationResolver,
        private readonly PlayerAvailabilityImpactService $playerAvailabilityImpactService,
    ) {}

    public function execute(Team $team, int $season): ?TeamMetric
    {
        if (! $this->seasonAffiliationResolver->isFbs($team, $season)) {
            return null;
        }

        $fpi = $this->latestFpiForTeam($team, $season);
        $wepa = $this->wepaForTeam($team, $season);
        $advancedStats = $this->advancedStatsForTeam($team, $season);
        $games = $this->getCompletedGamesForTeam($team, $season, 'CFB');

        if ($games->isEmpty()) {
            return $this->buildPreseasonMetric($team, $season, $fpi, $wepa, $advancedStats);
        }

        extract($this->gatherTeamStatsFromGames($games, $team));
        if ($season < (int) now()->format('Y')) {
            // Missing historical opponent ratings are unknown, not today's mutable team Elo.
            $recordedElos = $this->pregameEloIndex($games);
            $opponentElos = [];
            foreach ($games as $game) {
                $opponentId = (int) $game->home_team_id === (int) $team->id ? $game->away_team_id : $game->home_team_id;
                if (isset($recordedElos[$game->id.'-'.$opponentId])) {
                    $opponentElos[] = $recordedElos[$game->id.'-'.$opponentId];
                }
            }
        }

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

        if (count($teamStats) !== $games->count() || count($opponentStats) !== $games->count()) {
            Log::warning('Skipping CFB team metrics because completed game stats are incomplete', [
                'team_id' => $team->id,
                'team_name' => $team->display_name ?? $team->school ?? $team->id,
                'season' => $season,
                'completed_games' => $games->count(),
                'team_stat_games' => count($teamStats),
                'opponent_stat_games' => count($opponentStats),
            ]);

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
        $injuryAdjustedTeamRating = $this->playerAvailabilityAdjustedTeamRating($team, $season);
        $injuryAdjustedTotalAdjustment = $this->playerAvailabilityTotalAdjustment($team, $season);
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
        $ratingConsensus = $this->calculateRatingConsensus($team, $season, [
            'fpi' => $fpi,
            'power_rating' => $powerRating,
            'resume_rating' => $resumeRating,
            'cfp_rating' => $cfpRating,
            'wepa_net' => $wepa['net'],
            'net_rating' => $netRating,
        ]);
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
            'strength_of_schedule' => $this->roundOrNull($strengthOfSchedule, 3),
            'recent_form_rating' => $recentFormRating,
            'injury_adjusted_team_rating' => $injuryAdjustedTeamRating,
            'injury_total_adjustment' => $injuryAdjustedTotalAdjustment,
            'rest_travel_fatigue' => $restTravelFatigue,
            'cfbd_wepa_offense' => $wepa['offense'],
            'cfbd_wepa_defense' => $wepa['defense'],
            'cfbd_wepa_net' => $wepa['net'],
            'cfbd_wepa_payload' => $wepa['payload'],
            'cfp_rating' => $cfpRating,
            'power_rating' => $powerRating,
            'resume_rating' => $resumeRating,
            'rating_consensus' => $ratingConsensus['rating'],
            'rating_consensus_sources' => $ratingConsensus['sources'],
            'offensive_success_rate' => $advancedStats['offensive_success_rate'],
            'defensive_success_rate' => $advancedStats['defensive_success_rate'],
            'net_success_rate' => $advancedStats['net_success_rate'],
            'offensive_explosiveness' => $advancedStats['offensive_explosiveness'],
            'defensive_explosiveness' => $advancedStats['defensive_explosiveness'],
            'net_explosiveness' => $advancedStats['net_explosiveness'],
            'offensive_havoc_rate' => $advancedStats['offensive_havoc_rate'],
            'defensive_havoc_rate' => $advancedStats['defensive_havoc_rate'],
            'net_havoc_rate' => $advancedStats['net_havoc_rate'],
            'offensive_line_yards' => $advancedStats['offensive_line_yards'],
            'offensive_stuff_rate' => $advancedStats['offensive_stuff_rate'],
            'offensive_sack_rate' => $advancedStats['offensive_sack_rate'],
            'offensive_line_rating' => $advancedStats['offensive_line_rating'],
            'qb_environment_rating' => $advancedStats['qb_environment_rating'],
            'defensive_front_rating' => $advancedStats['defensive_front_rating'],
            'cfbd_advanced_payload' => $advancedStats['payload'],
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
            ->filter(function (Team $team) use ($season): bool {
                $attributes = $this->seasonAffiliationResolver->attributesForSeason($team, $season);

                return in_array($attributes['subdivision'], ['FCS', 'NON_FBS'], true);
            })
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
    protected function buildPreseasonMetric(Team $team, int $season, ?float $fpi, array $wepa, array $advancedStats): ?TeamMetric
    {
        if ($fpi === null
            && $wepa['net'] === null
            && $wepa['offense'] === null
            && $wepa['defense'] === null
            && $advancedStats['payload'] === null
        ) {
            return null;
        }

        $powerRating = $this->calculatePowerRating(
            netRating: 0.0,
            strengthOfSchedule: null,
            recentFormRating: null,
            fpi: $fpi,
            wepaNet: $wepa['net']
        );
        $ratingConsensus = $this->calculateRatingConsensus($team, $season, [
            'fpi' => $fpi,
            'power_rating' => $powerRating,
            'wepa_net' => $wepa['net'],
        ]);

        return $this->persistMetric($team, $season, [
            'wins' => 0,
            'losses' => 0,
            'fpi' => $fpi,
            'injury_adjusted_team_rating' => $this->playerAvailabilityAdjustedTeamRating($team, $season),
            'injury_total_adjustment' => $this->playerAvailabilityTotalAdjustment($team, $season),
            'cfbd_wepa_offense' => $wepa['offense'],
            'cfbd_wepa_defense' => $wepa['defense'],
            'cfbd_wepa_net' => $wepa['net'],
            'cfbd_wepa_payload' => $wepa['payload'],
            'power_rating' => $powerRating,
            'rating_consensus' => $ratingConsensus['rating'],
            'rating_consensus_sources' => $ratingConsensus['sources'],
            'offensive_success_rate' => $advancedStats['offensive_success_rate'],
            'defensive_success_rate' => $advancedStats['defensive_success_rate'],
            'net_success_rate' => $advancedStats['net_success_rate'],
            'offensive_explosiveness' => $advancedStats['offensive_explosiveness'],
            'defensive_explosiveness' => $advancedStats['defensive_explosiveness'],
            'net_explosiveness' => $advancedStats['net_explosiveness'],
            'offensive_havoc_rate' => $advancedStats['offensive_havoc_rate'],
            'defensive_havoc_rate' => $advancedStats['defensive_havoc_rate'],
            'net_havoc_rate' => $advancedStats['net_havoc_rate'],
            'offensive_line_yards' => $advancedStats['offensive_line_yards'],
            'offensive_stuff_rate' => $advancedStats['offensive_stuff_rate'],
            'offensive_sack_rate' => $advancedStats['offensive_sack_rate'],
            'offensive_line_rating' => $advancedStats['offensive_line_rating'],
            'qb_environment_rating' => $advancedStats['qb_environment_rating'],
            'defensive_front_rating' => $advancedStats['defensive_front_rating'],
            'cfbd_advanced_payload' => $advancedStats['payload'],
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

    protected function playerAvailabilityAdjustedTeamRating(Team $team, int $season): ?float
    {
        if ($season < (int) now()->format('Y')) {
            return null; // Current injury feeds cannot establish historical availability.
        }

        if (! (bool) config('cfb.predictions.player_availability.enabled', true)) {
            return $this->calculateInjuryAdjustedTeamRating($team, 'cfb', (float) ($team->elo_rating ?? 1500));
        }

        $baseRating = (float) ($team->elo_rating ?? 1500);
        $adjusted = $this->playerAvailabilityImpactService->adjustedTeamRating((int) $team->id, $baseRating, $season);

        return $adjusted ?? $this->calculateInjuryAdjustedTeamRating($team, 'cfb', $baseRating);
    }

    protected function playerAvailabilityTotalAdjustment(Team $team, int $season): ?float
    {
        if ($season < (int) now()->format('Y')) {
            return null;
        }

        if (! (bool) config('cfb.predictions.player_availability.enabled', true)) {
            return $this->calculateInjuryAdjustedTotalAdjustment($team, 'cfb');
        }

        return $this->playerAvailabilityImpactService->totalAdjustment((int) $team->id, $season)
            ?? $this->calculateInjuryAdjustedTotalAdjustment($team, 'cfb');
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
            'epa.total',
            'offense',
            'offensive',
            'wepaOff',
            'offensiveWepa',
            'offensive_wepa',
            'wepa_offense',
        ]);
        $defense = $this->extractMetricValue($row, [
            'epaAllowed.total',
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

    /**
     * @return array{
     *     offensive_success_rate:?float,
     *     defensive_success_rate:?float,
     *     net_success_rate:?float,
     *     offensive_explosiveness:?float,
     *     defensive_explosiveness:?float,
     *     net_explosiveness:?float,
     *     offensive_havoc_rate:?float,
     *     defensive_havoc_rate:?float,
     *     net_havoc_rate:?float,
     *     offensive_line_yards:?float,
     *     offensive_stuff_rate:?float,
     *     offensive_sack_rate:?float,
     *     offensive_line_rating:?float,
     *     qb_environment_rating:?float,
     *     defensive_front_rating:?float,
     *     payload:?array<string,mixed>
     * }
     */
    protected function advancedStatsForTeam(Team $team, int $season): array
    {
        $empty = [
            'offensive_success_rate' => null,
            'defensive_success_rate' => null,
            'net_success_rate' => null,
            'offensive_explosiveness' => null,
            'defensive_explosiveness' => null,
            'net_explosiveness' => null,
            'offensive_havoc_rate' => null,
            'defensive_havoc_rate' => null,
            'net_havoc_rate' => null,
            'offensive_line_yards' => null,
            'offensive_stuff_rate' => null,
            'offensive_sack_rate' => null,
            'offensive_line_rating' => null,
            'qb_environment_rating' => null,
            'defensive_front_rating' => null,
            'payload' => null,
        ];

        $row = $this->advancedStatsRowForTeam($team, $season);

        if (! is_array($row)) {
            return $empty;
        }

        $offensiveSuccessRate = $this->rateMetricValue($row, [
            'offense.successRate',
            'offense.success_rate',
            'offensive.successRate',
            'offensive.success_rate',
            'offensiveSuccessRate',
            'offensive_success_rate',
        ]);
        $defensiveSuccessRate = $this->rateMetricValue($row, [
            'defense.successRate',
            'defense.success_rate',
            'defensive.successRate',
            'defensive.success_rate',
            'defensiveSuccessRate',
            'defensive_success_rate',
        ]);
        $offensiveExplosiveness = $this->extractMetricValue($row, [
            'offense.explosiveness',
            'offensive.explosiveness',
            'offensiveExplosiveness',
            'offensive_explosiveness',
        ]);
        $defensiveExplosiveness = $this->extractMetricValue($row, [
            'defense.explosiveness',
            'defensive.explosiveness',
            'defensiveExplosiveness',
            'defensive_explosiveness',
        ]);
        $offensiveHavocRate = $this->rateMetricValue($row, [
            'offense.havoc.total',
            'offense.havocRate',
            'offense.havoc_rate',
            'offensive.havoc.total',
            'offensiveHavocRate',
            'offensive_havoc_rate',
        ]);
        $defensiveHavocRate = $this->rateMetricValue($row, [
            'defense.havoc.total',
            'defense.havocRate',
            'defense.havoc_rate',
            'defensive.havoc.total',
            'defensiveHavocRate',
            'defensive_havoc_rate',
        ]);
        $offensiveLineYards = $this->extractMetricValue($row, [
            'offense.lineYards',
            'offense.line_yards',
            'offensive.lineYards',
            'offensive_line_yards',
        ]);
        $offensiveStuffRate = $this->rateMetricValue($row, [
            'offense.stuffRate',
            'offense.stuff_rate',
            'offensive.stuffRate',
            'offensive_stuff_rate',
        ]);
        $offensiveSackRate = $this->rateMetricValue($row, [
            'offense.sackRate',
            'offense.sack_rate',
            'offensive.sackRate',
            'offensive_sack_rate',
        ]);

        if ($offensiveSackRate === null) {
            $derived = $this->observedSackRate($team, $season);
            $offensiveSackRate = $derived['value'];
            $row['_local_derivations']['offensive_sack_rate'] = $derived;
        }

        return array_merge($empty, [
            'offensive_success_rate' => $this->roundOrNull($offensiveSuccessRate, 4),
            'defensive_success_rate' => $this->roundOrNull($defensiveSuccessRate, 4),
            'net_success_rate' => $this->roundOrNull($this->nullableSubtract($offensiveSuccessRate, $defensiveSuccessRate), 4),
            'offensive_explosiveness' => $this->roundOrNull($offensiveExplosiveness, 4),
            'defensive_explosiveness' => $this->roundOrNull($defensiveExplosiveness, 4),
            'net_explosiveness' => $this->roundOrNull($this->nullableSubtract($offensiveExplosiveness, $defensiveExplosiveness), 4),
            'offensive_havoc_rate' => $this->roundOrNull($offensiveHavocRate, 4),
            'defensive_havoc_rate' => $this->roundOrNull($defensiveHavocRate, 4),
            'net_havoc_rate' => $this->roundOrNull($this->nullableSubtract($defensiveHavocRate, $offensiveHavocRate), 4),
            'offensive_line_yards' => $this->roundOrNull($offensiveLineYards, 4),
            'offensive_stuff_rate' => $this->roundOrNull($offensiveStuffRate, 4),
            'offensive_sack_rate' => $this->roundOrNull($offensiveSackRate, 4),
            'offensive_line_rating' => $this->roundOrNull($this->offensiveLineRating($offensiveLineYards, $offensiveStuffRate, $offensiveSackRate), 4),
            'qb_environment_rating' => $this->roundOrNull($this->quarterbackEnvironmentRating($offensiveSuccessRate, $offensiveExplosiveness, $offensiveSackRate), 4),
            'defensive_front_rating' => $this->roundOrNull($this->defensiveFrontRating($defensiveHavocRate, $defensiveSuccessRate), 4),
            'payload' => $row,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function advancedStatsRowForTeam(Team $team, int $season): ?array
    {
        $index = $this->seasonAdvancedStatsIndex($season);
        $cfbdTeamId = $this->cfbdTeamIdForTeam($team);

        if ($cfbdTeamId !== null && is_array($index[$cfbdTeamId] ?? null)) {
            return $index[$cfbdTeamId];
        }

        foreach (array_filter([$team->school, $team->display_name, $team->name, $team->short_display_name]) as $name) {
            $row = $index[mb_strtolower(trim((string) $name))] ?? null;

            if (is_array($row)) {
                return $row;
            }
        }

        return null;
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
        if (array_key_exists($season, $this->seasonWepaIndex)) {
            return $this->seasonWepaIndex[$season];
        }
        $rows = $this->providerRows('wepa', $season);
        $index = [];
        foreach ($rows as $row) {
            if (! is_array($row) || (isset($row['year']) && (int) $row['year'] !== $season)) {
                continue;
            }
            $id = $row['teamId'] ?? $row['id'] ?? $row['team_id'] ?? null;
            if (is_numeric($id)) {
                $index[(int) $id] = $row;
            }
        }

        return $this->seasonWepaIndex[$season] = $index;
    }

    /** @return array<int|string, array<string, mixed>> */
    protected function seasonAdvancedStatsIndex(int $season): array
    {
        if (array_key_exists($season, $this->seasonAdvancedStatsIndex)) {
            return $this->seasonAdvancedStatsIndex[$season];
        }
        $rows = $this->providerRows('advanced', $season);
        $mapping = $this->cfbdMappingIndex();
        $index = [];
        foreach ($rows as $row) {
            if (! is_array($row) || (isset($row['season']) && (int) $row['season'] !== $season)) {
                continue;
            }
            $id = $row['teamId'] ?? $row['id'] ?? $row['team_id'] ?? null;
            if (is_numeric($id)) {
                // Do not flatMap numeric keys: collection flattening renumbers team IDs.
                $index[(int) $id] = $row;
            }
            foreach (['team', 'school', 'name'] as $key) {
                if (is_string($row[$key] ?? null) && trim($row[$key]) !== '') {
                    $name = mb_strtolower(trim($row[$key]));
                    $index[$name] = $row;
                    if (isset($mapping[$name])) {
                        $index[$mapping[$name]] = $row;
                    }
                }
            }
        }

        return $this->seasonAdvancedStatsIndex[$season] = $index;
    }

    private function providerRows(string $source, int $season): array
    {
        try {
            $rows = $source === 'advanced'
                ? $this->collegeFootballDataService->getAdvancedTeamSeasonStats($season, excludeGarbageTime: true)
                : $this->collegeFootballDataService->getWepaTeamSeason($season);
        } catch (\Throwable $exception) {
            Log::warning('CFB external metric request failed', ['source' => $source, 'season' => $season,
                'exception' => get_class($exception)]);
            // Optional unconfigured local environments can still calculate local box-score metrics.
            // A configured provider failure must not silently overwrite real metrics with nulls.
            if (config('services.collegefootballdata.api_key') || $exception instanceof RequestException) {
                throw new \RuntimeException("CFBD {$source} request failed for {$season}; metric update withheld.", previous: $exception);
            }

            return [];
        }
        if ($rows === []) {
            Log::warning('CFB external metric endpoint returned no season data', ['source' => $source, 'season' => $season]);
        }

        return $rows;
    }

    private function observedSackRate(Team $team, int $season): array
    {
        $stats = TeamStat::query()->where('team_id', $team->id)
            ->whereHas('game', fn ($query) => $query->where('season', $season)->where('status', 'STATUS_FINAL'))
            ->get();
        $games = Game::query()->where('season', $season)->where('status', 'STATUS_FINAL')
            ->where(fn ($query) => $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))->count();
        $complete = $games > 0 && $stats->count() === $games && $stats->every(fn ($stat) => is_numeric($stat->sacks_allowed)
            && is_numeric($stat->passing_attempts) && $stat->sacks_allowed >= 0 && $stat->passing_attempts >= 0);
        $sacks = $complete ? (float) $stats->sum('sacks_allowed') : null;
        $attempts = $complete ? (float) $stats->sum('passing_attempts') : null;

        return ['value' => $complete && $sacks + $attempts > 0 ? round($sacks / ($sacks + $attempts), 4) : null,
            'source' => 'stored_final_game_team_stats', 'formula' => 'sacks_allowed / (passing_attempts + sacks_allowed)',
            'definition' => 'Sacks per pass attempts plus sacks; excludes scrambles; not passing-down-only sack rate',
            'complete' => $complete, 'sample_games' => $stats->count(), 'stat_ids' => $stats->pluck('id')->all(),
            'game_ids' => $stats->pluck('game_id')->all(), 'observed_at' => $stats->max('updated_at')?->toIso8601String()];
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
            ->get(['espn_team_name', 'cfbd_team_name', 'alternate_names', 'cfbd_team_id'])
            ->mapWithKeys(function (CfbdTeamMapping $mapping): array {
                $names = array_filter([$mapping->espn_team_name, $mapping->cfbd_team_name, ...(array) $mapping->alternate_names], 'is_string');

                return collect($names)->mapWithKeys(fn ($name) => [mb_strtolower(trim($name)) => (int) $mapping->cfbd_team_id])->all();
            })
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

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    protected function rateMetricValue(array $row, array $keys): ?float
    {
        $value = $this->extractMetricValue($row, $keys);

        if ($value === null) {
            return null;
        }

        if (abs($value) > 1.5) {
            $value /= 100;
        }

        return max(-1.0, min(1.0, $value));
    }

    protected function nullableSubtract(?float $left, ?float $right): ?float
    {
        if ($left === null || $right === null) {
            return null;
        }

        return $left - $right;
    }

    protected function offensiveLineRating(?float $lineYards, ?float $stuffRate, ?float $sackRate): ?float
    {
        if ($lineYards === null && $stuffRate === null && $sackRate === null) {
            return null;
        }

        $score = 0.0;
        $weight = 0.0;

        if ($lineYards !== null) {
            $score += max(-1.0, min(1.0, ($lineYards - 2.75) / 1.25)) * 0.40;
            $weight += 0.40;
        }

        if ($stuffRate !== null) {
            $score += max(-1.0, min(1.0, (0.19 - $stuffRate) / 0.12)) * 0.35;
            $weight += 0.35;
        }

        if ($sackRate !== null) {
            $score += max(-1.0, min(1.0, (0.065 - $sackRate) / 0.07)) * 0.25;
            $weight += 0.25;
        }

        return $weight > 0 ? max(-1.0, min(1.0, $score / $weight)) : null;
    }

    protected function quarterbackEnvironmentRating(?float $successRate, ?float $explosiveness, ?float $sackRate): ?float
    {
        if ($successRate === null && $explosiveness === null && $sackRate === null) {
            return null;
        }

        $score = 0.0;
        $weight = 0.0;

        if ($successRate !== null) {
            $score += max(-1.0, min(1.0, ($successRate - 0.42) / 0.16)) * 0.45;
            $weight += 0.45;
        }

        if ($explosiveness !== null) {
            $score += max(-1.0, min(1.0, ($explosiveness - 1.30) / 0.55)) * 0.35;
            $weight += 0.35;
        }

        if ($sackRate !== null) {
            $score += max(-1.0, min(1.0, (0.065 - $sackRate) / 0.07)) * 0.20;
            $weight += 0.20;
        }

        return $weight > 0 ? max(-1.0, min(1.0, $score / $weight)) : null;
    }

    protected function defensiveFrontRating(?float $defensiveHavocRate, ?float $defensiveSuccessRate): ?float
    {
        if ($defensiveHavocRate === null && $defensiveSuccessRate === null) {
            return null;
        }

        $score = 0.0;
        $weight = 0.0;

        if ($defensiveHavocRate !== null) {
            $score += max(-1.0, min(1.0, ($defensiveHavocRate - 0.17) / 0.10)) * 0.60;
            $weight += 0.60;
        }

        if ($defensiveSuccessRate !== null) {
            $score += max(-1.0, min(1.0, (0.40 - $defensiveSuccessRate) / 0.16)) * 0.40;
            $weight += 0.40;
        }

        return $weight > 0 ? max(-1.0, min(1.0, $score / $weight)) : null;
    }

    /**
     * @param  array<string, mixed>  $ratings
     * @return array{rating:?float,sources:array<string,array{value:float,weight:float}>}
     */
    protected function calculateRatingConsensus(Team $team, int $season, array $ratings): array
    {
        $weights = (array) config('cfb.metrics.consensus_ratings.weights', []);
        $pointsPerElo = (float) config('cfb.predictions.points_per_elo', 0.08);
        $defaultElo = (float) config('cfb.elo.default_rating', 1500);

        $elo = $season < (int) now()->format('Y')
            ? EloRating::query()->where('team_id', $team->id)->where('cfb_elo_ratings.season', $season)
                ->where('model_version', CalculateElo::MODEL_VERSION)
                ->join('cfb_games', 'cfb_games.id', '=', 'cfb_elo_ratings.game_id')
                ->orderByDesc('cfb_games.game_date')->orderByDesc('cfb_games.game_time')->orderByDesc('cfb_games.id')
                ->value('cfb_elo_ratings.elo_rating')
            : ($team->elo_rating ?? $defaultElo);

        $sourceValues = [
            'fpi' => $ratings['fpi'] ?? null,
            'power_rating' => $ratings['power_rating'] ?? null,
            'wepa_net' => is_numeric($ratings['wepa_net'] ?? null) ? ((float) $ratings['wepa_net'] * 4.0) : null,
            'net_rating' => is_numeric($ratings['net_rating'] ?? null) ? ((float) $ratings['net_rating'] * 0.40) : null,
            'elo' => is_numeric($elo) ? ((float) $elo - $defaultElo) * $pointsPerElo : null,
            'cfp_rating' => is_numeric($ratings['cfp_rating'] ?? null) ? (((float) $ratings['cfp_rating'] - 50.0) / 2.9) : null,
            'resume_rating' => is_numeric($ratings['resume_rating'] ?? null) ? ((float) $ratings['resume_rating'] * 0.39 / 2.9) : null,
        ];

        $weightedSum = 0.0;
        $totalWeight = 0.0;
        $sources = [];

        foreach ($sourceValues as $source => $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $weight = (float) ($weights[$source] ?? 0.0);
            if ($weight <= 0) {
                continue;
            }

            $weightedSum += (float) $value * $weight;
            $totalWeight += $weight;
            $sources[$source] = [
                'value' => round((float) $value, 3),
                'weight' => round($weight, 3),
            ];
        }

        if ($totalWeight <= 0) {
            return ['rating' => null, 'sources' => []];
        }

        return [
            'rating' => round($weightedSum / $totalWeight, 3),
            'sources' => $sources,
        ];
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
        $opponentPregameElo = $pregameEloIndex[$game->id.'-'.$opponent->id]
            ?? ($season < (int) now()->format('Y') ? (float) config('cfb.elo.default_rating', 1500) : (float) ($opponent->elo_rating ?? 1500));
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
                $preGameElo = $record->elo_before !== null ? (float) $record->elo_before : (float) $record->elo_rating - (float) $record->elo_change;

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
        $normalized = strtolower(trim((string) $conference));

        return match ($normalized) {
            'sec' => 'southeastern conference',
            'acc' => 'atlantic coast conference',
            'big ten' => 'big ten conference',
            'big 12' => 'big 12 conference',
            'american athletic', 'american' => 'american athletic conference',
            'mid-american' => 'mid-american conference',
            'mountain west' => 'mountain west conference',
            'sun belt' => 'sun belt conference',
            'pac-12' => 'pac-12 conference',
            default => $normalized,
        };
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
        return $this->seasonAffiliationResolver->attributesForSeason($team, $season)['conference'];
    }
}
