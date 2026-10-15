<?php

namespace App\Console\Commands\CFB;

use App\Services\CFB\Predictions\CfbCandidateReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class ReviewModelCandidatesCommand extends Command
{
    protected $signature = 'cfb:review-model-candidates {--season=} {--from-date=} {--to-date=} {--output=}';

    protected $description = 'Read-only paired evaluation of frozen CFB totals, ratings, market blending, and probability calibration';

    public function handle(CfbCandidateReview $review): int
    {
        $season = $this->option('season') ?? now()->year;
        $validator = Validator::make(['season' => $season, 'from' => $this->option('from-date'), 'to' => $this->option('to-date')],
            ['season' => 'required|integer|min:2000|max:2100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $report = $review->report((int) $season, $this->option('from-date'), $this->option('to-date'));
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($this->option('output')) {
            File::put($this->option('output'), $json);
        }
        $this->line($json);

        return self::SUCCESS;
    }
}
