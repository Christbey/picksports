<?php

use App\Actions\CFB\CalculateTeamMetrics;
use App\Actions\ESPN\CFB\SyncTeams;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Services\ESPN\CFB\EspnService;
use App\Support\CfbSeasonAffiliationResolver;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 19));
});

it('reads partial models without persisting or losing affiliation fields', function () {
    $team = Team::factory()->create(['conference' => 'East', 'division' => 'Sun Belt Conference']);
    $resolver = app(CfbSeasonAffiliationResolver::class);
    $partial = Team::query()->select('id')->findOrFail($team->id);
    expect($resolver->isFbs($partial, 2026))->toBeTrue()
        ->and($resolver->attributesForSeason($partial, 2026)['conference'])->toBe('Sun Belt Conference')
        ->and(TeamSeasonAffiliation::query()->count())->toBe(0);
});

it('does not project current membership or legacy snapshots into prior seasons', function () {
    $team = Team::factory()->create(['division' => 'FBS']);
    TeamSeasonAffiliation::query()->create(['team_id' => $team->id, 'season' => 2025, 'subdivision' => 'FBS', 'source' => 'team_snapshot']);
    expect(app(CfbSeasonAffiliationResolver::class)->attributesForSeason($team, 2025)['source'])->toBe('unknown')
        ->and($team->isFbsForSeason(2025))->toBeFalse();
});

it('preserves authoritative historical conference and subdivision', function () {
    $team = Team::factory()->create(['division' => 'FBS', 'conference' => 'Sun Belt Conference']);
    $resolver = app(CfbSeasonAffiliationResolver::class);
    $resolver->ensureForSeason($team, 2025, ['subdivision' => 'FBS', 'conference' => 'Conference USA', 'division' => null, 'source' => 'cfbd_fbs_membership']);
    expect($resolver->attributesForSeason($team, 2025)['conference'])->toBe('Conference USA')
        ->and($resolver->isFbs($team, 2025))->toBeTrue();
    expect(fn () => $resolver->ensureForSeason($team, 2025))->toThrow(InvalidArgumentException::class);
});

it('never purges metrics for unknown historical membership', function () {
    $team = Team::factory()->create(['division' => 'FBS']);
    TeamMetric::query()->create(['team_id' => $team->id, 'season' => 2025, 'games_played' => 12, 'calculation_date' => now()]);
    expect(app(CalculateTeamMetrics::class)->purgeNonFbsMetrics(2025))->toBe(0)
        ->and(TeamMetric::query()->count())->toBe(1);
});

it('synchronizes full provider membership atomically and separates conference division', function () {
    $team = Team::factory()->create(['cfbd_team_id' => 77, 'conference' => 'East', 'division' => 'Sun Belt Conference']);
    $other = Team::factory()->create(['cfbd_team_id' => 88, 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFbsTeams')->once()->with(2025)->andReturn([
        ['id' => 77, 'school' => $team->school, 'conference' => 'Sun Belt', 'division' => 'East', 'classification' => 'fbs'],
    ]);
    $this->artisan('cfb:sync-season-affiliations', ['--season' => 2025, '--minimum-teams' => 1])->assertSuccessful();
    expect($team->seasonAffiliation(2025))->conference->toBe('Sun Belt')->division->toBe('East')->subdivision->toBe('FBS')
        ->and($other->seasonAffiliation(2025))->subdivision->toBe('NON_FBS');
});

it('fails an unmatched or short provider roster without changing affiliations', function () {
    $team = Team::factory()->create(['cfbd_team_id' => 77]);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFbsTeams')->twice()->andReturn([
        ['id' => 77, 'school' => $team->school, 'conference' => 'Sun Belt'],
        ['id' => 999999, 'school' => 'Unmatched Team', 'conference' => 'Sun Belt'],
    ]);
    $this->artisan('cfb:sync-season-affiliations', ['--season' => 2025])->assertFailed();
    $this->artisan('cfb:sync-season-affiliations', ['--season' => 2025, '--minimum-teams' => 1])->assertFailed();
    expect(TeamSeasonAffiliation::query()->count())->toBe(0);
});

it('resolves nested ESPN conference groups through the subdivision', function () {
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getByRef')->with('https://example.test/east')->andReturn(['name' => 'East', 'parent' => ['$ref' => 'https://example.test/sunbelt']]);
    $service->shouldReceive('getByRef')->with('https://example.test/sunbelt')->andReturn(['name' => 'Sun Belt Conference', 'parent' => ['$ref' => 'https://example.test/fbs']]);
    $service->shouldReceive('getByRef')->with('https://example.test/fbs')->andReturn(['name' => 'FBS']);
    $action = new class($service) extends SyncTeams
    {
        public function resolve(array $team): array
        {
            return $this->resolveConferenceDivision($team);
        }
    };
    expect($action->resolve(['groups' => ['$ref' => 'https://example.test/east']]))->toBe(['Sun Belt Conference', 'FBS']);
});

it('prioritizes exact school and mascot over colliding abbreviations and duplicate school names', function () {
    $buffalo = Team::factory()->create(['school' => 'Buffalo', 'mascot' => 'Bulls', 'abbreviation' => 'BUFF']);
    Team::factory()->create(['school' => 'Buffalo State', 'mascot' => 'Bengals', 'abbreviation' => 'BUF']);
    $usf = Team::factory()->create(['school' => 'South Florida', 'mascot' => 'Bulls', 'abbreviation' => 'USF']);
    Team::factory()->create(['school' => 'SOUTH FLORIDA', 'mascot' => 'STARS', 'abbreviation' => 'SFX']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFbsTeams')->once()->with(2025)->andReturn([
        ['id' => 2084, 'school' => 'Buffalo', 'mascot' => 'Bulls', 'abbreviation' => 'BUF', 'conference' => 'Mid-American'],
        ['id' => 58, 'school' => 'South Florida', 'mascot' => 'Bulls', 'conference' => 'American Athletic'],
    ]);
    $this->artisan('cfb:sync-season-affiliations', ['--season' => 2025, '--minimum-teams' => 2])->assertSuccessful();
    expect($buffalo->fresh()->cfbd_team_id)->toBe(2084)->and($usf->fresh()->cfbd_team_id)->toBe(58)
        ->and(TeamSeasonAffiliation::query()->where('subdivision', 'FBS')->count())->toBe(2);
});

it('refuses a provider response that drops verified membership for the same season', function () {
    $team = Team::factory()->create(['cfbd_team_id' => 77]);
    $missing = Team::factory()->create(['cfbd_team_id' => 88]);
    app(CfbSeasonAffiliationResolver::class)->ensureForSeason($missing, 2025, [
        'subdivision' => 'FBS', 'conference' => 'Sun Belt', 'division' => null, 'source' => 'cfbd_fbs_membership',
    ]);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFbsTeams')->once()->andReturn([
        ['id' => 77, 'school' => $team->school, 'conference' => 'Sun Belt'],
    ]);
    $this->artisan('cfb:sync-season-affiliations', ['--season' => 2025, '--minimum-teams' => 1])->assertFailed();
    expect($missing->seasonAffiliation(2025)->subdivision)->toBe('FBS')
        ->and($team->seasonAffiliation(2025))->toBeNull();
});
