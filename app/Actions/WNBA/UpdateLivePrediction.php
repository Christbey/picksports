<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractSimpleBasketballUpdateLivePrediction;

class UpdateLivePrediction extends AbstractSimpleBasketballUpdateLivePrediction
{
    protected const TOTAL_GAME_SECONDS = 2400;

    protected const REGULATION_PERIODS = 4;

    protected const SECONDS_PER_PERIOD = 600;

    protected const DEFAULT_PRE_GAME_TOTAL = 220;

    protected const UPPER_BOUND = 250;
}
