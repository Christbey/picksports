<?php

namespace App\Jobs\Predictions;

use App\Models\AnalysisWorkItem;
use App\Services\Predictions\Review\PredictionReviewRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReviewPredictionWorkItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(public int $workItemId) {}

    public function uniqueId(): string
    {
        return 'prediction-review-work:'.$this->workItemId;
    }

    public function handle(PredictionReviewRunner $runner): void
    {
        $item = AnalysisWorkItem::find($this->workItemId);
        if ($item !== null) {
            $runner->run($item);
        }
    }
}
