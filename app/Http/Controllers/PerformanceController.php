<?php

namespace App\Http\Controllers;

use App\Services\PerformanceStatistics;
use Inertia\Inertia;
use Inertia\Response;

class PerformanceController extends Controller
{
    public function __construct(
        public PerformanceStatistics $performanceStats
    ) {}

    public function __invoke(): Response
    {
        // Get overall stats
        $overall = $this->performanceStats->getOverallStats();

        // Get stats by sport
        $bySport = $this->performanceStats->getStatsBySport();

        // Get recent performance (last 30 days)
        $recent = $this->performanceStats->getRecentPerformance();

        // Get season-to-date stats
        $seasonToDate = $this->performanceStats->getSeasonToDate();

        // Calculate ROI
        $roi = $this->performanceStats->calculateROI();

        return Inertia::render('Performance', [
            'overall' => $overall,
            'by_sport' => $bySport,
            'recent' => $recent,
            'season_to_date' => $seasonToDate,
            'roi' => $roi,
        ]);
    }
}
