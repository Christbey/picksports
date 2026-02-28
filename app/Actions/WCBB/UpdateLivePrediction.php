<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractSimpleBasketballUpdateLivePrediction;

class UpdateLivePrediction extends AbstractSimpleBasketballUpdateLivePrediction
{
    protected const TOTAL_GAME_SECONDS = 2400;

    protected const REGULATION_PERIODS = 2;

    protected const SECONDS_PER_PERIOD = 1200;

    protected const DEFAULT_PRE_GAME_TOTAL = 140;

    protected const UPPER_BOUND = 220;
}
