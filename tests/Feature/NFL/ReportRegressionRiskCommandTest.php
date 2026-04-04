<?php

namespace Tests\Feature\NFL;

use App\Services\NFL\TeamRegressionRiskReportService;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportRegressionRiskCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_regression_risk_rankings(): void
    {
        $broncos = Team::factory()->create([
            'id' => 1,
            'location' => 'Denver',
            'name' => 'Broncos',
            'abbreviation' => 'DEN',
            'conference' => 'AFC',
            'division' => 'West',
        ]);
        $bills = Team::factory()->create([
            'id' => 2,
            'location' => 'Buffalo',
            'name' => 'Bills',
            'abbreviation' => 'BUF',
            'conference' => 'AFC',
            'division' => 'East',
        ]);

        TeamMetric::query()->create([
            'team_id' => $broncos->id,
            'season' => 2025,
            'wins' => 14,
            'losses' => 3,
            'predictive_rating' => 4.400,
            'recent_form_rating' => 5.500,
            'calculation_date' => '2026-01-10',
        ]);
        TeamMetric::query()->create([
            'team_id' => $bills->id,
            'season' => 2025,
            'wins' => 12,
            'losses' => 5,
            'predictive_rating' => 6.500,
            'recent_form_rating' => 9.000,
            'calculation_date' => '2026-01-10',
        ]);

        TeamMetricSnapshot::query()->create([
            'snapshot_key' => sha1('1|2026|2026-04-02T18:00:00Z'),
            'team_id' => $broncos->id,
            'season' => 2026,
            'wins' => 0,
            'losses' => 0,
            'predictive_rating' => 4.400,
            'future_strength_of_schedule' => 1500.000,
            'recent_form_rating' => 1.900,
            'injury_total_adjustment' => 0.000,
            'calculation_date' => '2026-04-02',
            'captured_at' => '2026-04-02 18:00:00',
        ]);
        TeamMetricSnapshot::query()->create([
            'snapshot_key' => sha1('2|2026|2026-04-02T18:00:00Z'),
            'team_id' => $bills->id,
            'season' => 2026,
            'wins' => 0,
            'losses' => 0,
            'predictive_rating' => 6.500,
            'future_strength_of_schedule' => 1500.000,
            'recent_form_rating' => 3.100,
            'injury_total_adjustment' => 0.000,
            'calculation_date' => '2026-04-02',
            'captured_at' => '2026-04-02 18:00:00',
        ]);

        $this->artisan('nfl:report-regression-risk', [
            '--season' => 2026,
            '--as-of-date' => '2026-04-02T18:00:00Z',
            '--require-historical-metrics' => true,
            '--limit' => 2,
        ])->assertSuccessful();

        $report = app(TeamRegressionRiskReportService::class)->generate(
            season: 2026,
            asOfDate: '2026-04-02T18:00:00Z',
            requireHistoricalMetrics: true,
            limit: 2,
        );

        $this->assertCount(2, $report['teams']);
        $this->assertSame('Denver Broncos', $report['teams'][0]['team_name']);
        $this->assertSame('very_high', $report['teams'][0]['risk_tier']);
        $this->assertSame('Buffalo Bills', $report['teams'][1]['team_name']);
        $this->assertSame('medium', $report['teams'][1]['risk_tier']);
    }

    public function test_it_reports_breakout_risk_rankings(): void
    {
        $giants = Team::factory()->create([
            'id' => 1,
            'location' => 'New York',
            'name' => 'Giants',
            'abbreviation' => 'NYG',
            'conference' => 'NFC',
            'division' => 'East',
        ]);
        $chiefs = Team::factory()->create([
            'id' => 2,
            'location' => 'Kansas City',
            'name' => 'Chiefs',
            'abbreviation' => 'KC',
            'conference' => 'AFC',
            'division' => 'West',
        ]);

        TeamMetric::query()->create([
            'team_id' => $giants->id,
            'season' => 2025,
            'wins' => 4,
            'losses' => 13,
            'predictive_rating' => -0.700,
            'recent_form_rating' => 5.600,
            'calculation_date' => '2026-01-10',
        ]);
        TeamMetric::query()->create([
            'team_id' => $chiefs->id,
            'season' => 2025,
            'wins' => 6,
            'losses' => 11,
            'predictive_rating' => -0.300,
            'recent_form_rating' => -7.300,
            'calculation_date' => '2026-01-10',
        ]);

        TeamMetricSnapshot::query()->create([
            'snapshot_key' => sha1('1|2026|2026-04-02T18:00:00Z'),
            'team_id' => $giants->id,
            'season' => 2026,
            'wins' => 0,
            'losses' => 0,
            'predictive_rating' => 0.000,
            'future_strength_of_schedule' => 1500.000,
            'recent_form_rating' => 9.000,
            'injury_total_adjustment' => 0.000,
            'calculation_date' => '2026-04-02',
            'captured_at' => '2026-04-02 18:00:00',
        ]);
        TeamMetricSnapshot::query()->create([
            'snapshot_key' => sha1('2|2026|2026-04-02T18:00:00Z'),
            'team_id' => $chiefs->id,
            'season' => 2026,
            'wins' => 0,
            'losses' => 0,
            'predictive_rating' => 0.000,
            'future_strength_of_schedule' => 1500.000,
            'recent_form_rating' => 5.000,
            'injury_total_adjustment' => 0.000,
            'calculation_date' => '2026-04-02',
            'captured_at' => '2026-04-02 18:00:00',
        ]);

        $this->artisan('nfl:report-regression-risk', [
            '--season' => 2026,
            '--mode' => 'breakout',
            '--as-of-date' => '2026-04-02T18:00:00Z',
            '--require-historical-metrics' => true,
            '--limit' => 2,
        ])->assertSuccessful();

        $report = app(TeamRegressionRiskReportService::class)->generate(
            season: 2026,
            asOfDate: '2026-04-02T18:00:00Z',
            requireHistoricalMetrics: true,
            limit: 2,
            mode: 'breakout',
        );

        $this->assertCount(2, $report['teams']);
        $this->assertSame('New York Giants', $report['teams'][0]['team_name']);
        $this->assertSame('very_high', $report['teams'][0]['risk_tier']);
        $this->assertSame('Kansas City Chiefs', $report['teams'][1]['team_name']);
        $this->assertSame('high', $report['teams'][1]['risk_tier']);
    }
}
