<?php

namespace App\Services\CFB\Predictions;

use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbOfficialBoxscoreRepair;
use App\Services\CFB\Data\CfbOfficialPlayTailRepair;
use App\Services\CFB\Data\CfbScoringLedger;
use App\Services\CFB\Signals\CfbFootballSignalCatalog;
use App\Services\Predictions\Football\FootballCalculationReleaseDefinition;

class CfbCalculationReleaseDefinition extends FootballCalculationReleaseDefinition
{
    public const CALCULATOR_NAME = 'cfb-pregame-rules';

    public const INPUT_SCHEMA_VERSION = 'cfb-pregame-v1';

    public const SEMANTIC_VERSION = '1.9.0';

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $configuration = parent::configuration();
        $configuration['forecast_coordination'] = config('cfb_forecast_coordination', ['enabled' => false]);
        $configuration['candidate_suite'] = ['enabled' => true, 'version' => CfbForecastCandidates::VERSION, 'promotion' => 'shadow_only'];
        $configuration['scoring_challenger'] = ['artifact_id' => config('cfb.scoring.artifact_id'), 'mode' => 'shadow_until_promoted'];
        $configuration['inputs']['sample_aware_context'] = true;
        $configuration['inputs']['validated_historical_play_metrics'] = true;
        $configuration['inputs']['historical_play_normalizer_version'] = CfbDriveBuilder::VERSION;
        $configuration['inputs']['scoring_ledger_normalizer_version'] = CfbScoringLedger::VERSION;
        $configuration['inputs']['reconciled_play_validator_version'] = CfbGameDataValidator::RECONCILED_PLAYS_VERSION;
        $configuration['inputs']['official_boxscore_policy'] = CfbOfficialBoxscoreRepair::VERSION;
        $configuration['inputs']['official_play_tail_policy'] = CfbOfficialPlayTailRepair::VERSION;
        $configuration['inputs']['point_in_time_elo'] = true;
        $configuration['inputs']['auditable_feature_coverage'] = true;
        $configuration['inputs']['preserve_missing_metrics'] = true;
        $configuration['inputs']['personnel_evidence'] = true;
        $configuration['spread']['rating_baseline'] = 'fpi_points';

        $configuration['independent_result_rating'] = ['enabled' => true, 'version' => 'cfb-result-rating-v1'];
        $configuration['football_signals'] = ['enabled' => true, 'version' => 'cfb-football-signals-1',
            'feature_policy' => 'early_season_prior_v1', 'training_policy' => 'replay_frozen_baseline_v1', 'historical_training' => true, 'weighting' => 'joint_ridge_v1',
            'maximum_spread_adjustment' => 2.0, 'maximum_total_adjustment' => 3.0,
            'catalog' => CfbFootballSignalCatalog::all()];

        return $configuration;
    }

    public function sport(): string
    {
        return 'cfb';
    }

    public function calculatorName(): string
    {
        return self::CALCULATOR_NAME;
    }

    public function inputSchemaVersion(): string
    {
        return self::INPUT_SCHEMA_VERSION;
    }

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    protected function homeFieldAdvantage(): float
    {
        return 55.0;
    }

    protected function defaultTeamPoints(): float
    {
        return 28.0;
    }

    protected function averageTotal(): float
    {
        return 56.0;
    }
}
