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
use App\Actions\Validation\Checks\TeamStatCoverageCheck;
use App\Actions\Validation\Checks\UpcomingGameReadinessCheck;
use App\Actions\Validation\Checks\WeatherCompletenessCheck;
use App\Actions\Validation\Contracts\ValidationCheck;

class SportValidator
{
    /**
     * @var array<int, ValidationCheck>
     */
    private array $checks;

    public function __construct()
    {
        $this->checks = [
            new GameCoverageCheck,
            new TeamStatCoverageCheck,
            new CurrentDayGameDataFreshnessCheck,
            new UpcomingGameReadinessCheck,
            new PastScheduledGameStatusCheck,
            new PredictionCompletenessCheck,
            new OddsCompletenessCheck,
            new InjuryFreshnessCheck,
            new PlayerPropFreshnessCheck,
            new FuturesOddsFreshnessCheck,
            new WeatherCompletenessCheck,
            new PipelineOrderCheck,
            new FinalizedDataCompletenessCheck,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function validate(string $sport): array
    {
        $profile = config("validation.sports.{$sport}");

        if (! is_array($profile)) {
            return [];
        }

        $results = [];

        foreach ($this->checks as $check) {
            $result = $check->run($sport, $profile);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }
}
