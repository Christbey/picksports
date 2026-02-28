<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\AbstractGenerateSeasonScheduledPredictionsCommand;

class GeneratePredictionsCommand extends AbstractGenerateSeasonScheduledPredictionsCommand
{
    protected const COMMAND_NAME = 'mlb:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate MLB game predictions for scheduled games';

    protected const SEASON_OPTION_DESCRIPTION = 'Generate predictions for a specific season (required)';

    protected const GENERATE_ACTION_CLASS = \App\Actions\MLB\GeneratePrediction::class;
}
