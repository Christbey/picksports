<?php

namespace App\Services\Predictions\Review;

class ReviewDeferred extends \RuntimeException
{
    public function __construct(string $reason, public readonly int $retrySeconds = 300)
    {
        parent::__construct($reason);
    }
}
