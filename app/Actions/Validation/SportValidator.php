<?php

namespace App\Actions\Validation;

use App\Actions\Validation\Checks\CurrentDayGameDataFreshnessCheck;
use App\Actions\Validation\Checks\FinalizedDataCompletenessCheck;
use App\Actions\Validation\Checks\FuturesOddsFreshnessCheck;
use App\Actions\Validation\Checks\GameCoverageCheck;
use App\Actions\Validation\Checks\InjuryFreshnessCheck;
use App\Actions\Validation\Checks\OddsCompletenessCheck;
use App\Actions\Validation\Checks\PastScheduledGameStatusCheck;
use App\Actions\Validation\Checks\PipelineOrderCheck;
use App\Actions\Validation\Checks\PlayerPropFreshnessCheck;
use App\Actions\Validation\Checks\PredictionCompletenessCheck;
use App\Actions\Validation\Checks\ScheduleWindowIntegrityCheck;
use App\Actions\Validation\Checks\TeamStatCoverageCheck;
use App\Actions\Validation\Checks\UpcomingGameReadinessCheck;
use App\Actions\Validation\Checks\WeatherCompletenessCheck;
use App\Actions\Validation\Contracts\ValidationCheck;

class SportValidator
{
    /**
     * @var array<int, ValidationCheck>
     */
    private array $fullChecks;

    /**
     * @var array<int, ValidationCheck>
     */
    private array $dataChecks;

    public function __construct()
    {
        $gameCoverage = new GameCoverageCheck;
        $teamStatCoverage = new TeamStatCoverageCheck;
        $currentDayGameDataFreshness = new CurrentDayGameDataFreshnessCheck;
        $scheduleWindowIntegrity = new ScheduleWindowIntegrityCheck;
        $upcomingGameReadiness = new UpcomingGameReadinessCheck;
        $pastScheduledGameStatus = new PastScheduledGameStatusCheck;
        $predictionCompleteness = new PredictionCompletenessCheck;
        $oddsCompleteness = new OddsCompletenessCheck;
        $injuryFreshness = new InjuryFreshnessCheck;
        $playerPropFreshness = new PlayerPropFreshnessCheck;
        $futuresOddsFreshness = new FuturesOddsFreshnessCheck;
        $weatherCompleteness = new WeatherCompletenessCheck;
        $pipelineOrder = new PipelineOrderCheck;
        $finalizedDataCompleteness = new FinalizedDataCompletenessCheck;

        $this->fullChecks = [
            $gameCoverage,
            $teamStatCoverage,
            $currentDayGameDataFreshness,
            $scheduleWindowIntegrity,
            $upcomingGameReadiness,
            $pastScheduledGameStatus,
            $predictionCompleteness,
            $oddsCompleteness,
            $injuryFreshness,
            $playerPropFreshness,
            $futuresOddsFreshness,
            $weatherCompleteness,
            $pipelineOrder,
            $finalizedDataCompleteness,
        ];

        $this->dataChecks = [
            $gameCoverage,
            $teamStatCoverage,
            $currentDayGameDataFreshness,
            $scheduleWindowIntegrity,
            $upcomingGameReadiness,
            $pastScheduledGameStatus,
            $oddsCompleteness,
            $injuryFreshness,
            $playerPropFreshness,
            $futuresOddsFreshness,
            $weatherCompleteness,
            $finalizedDataCompleteness,
        ];
    }

    /**
     * @return array<int, ValidationCheck>
     */
    private function checksForScope(string $scope): array
    {
        return match ($scope) {
            'data' => $this->dataChecks,
            default => $this->fullChecks,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function supportedScopes(): array
    {
        return ['full', 'data'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function validate(string $sport, string $scope = 'full'): array
    {
        $profile = config("validation.sports.{$sport}");

        if (! is_array($profile)) {
            return [];
        }

        $results = [];

        foreach ($this->checksForScope($scope) as $check) {
            $result = $check->run($sport, $profile);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }
}
