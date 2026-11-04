<?php

use App\Models\CalculationRelease;
use Carbon\CarbonImmutable;

it('preserves release defaults and approval forwarding across all seven sports', function (string $sport, string $version) {
    $class = 'App\\Services\\'.strtoupper($sport).'\\Predictions\\'.ucfirst($sport).'CalculationReleaseRegistrar';
    $release = CalculationRelease::factory()->make(['sport' => $sport, 'semantic_version' => $version, 'status' => 'approved']);
    $this->mock($class)->shouldReceive('register')->once()->withArgs(function ($semanticVersion, $approve, $actor, $reason, $effectiveAt) use ($version) {
        return $semanticVersion === $version && $approve === true && $actor === 'artisan' && filled($reason) && $effectiveAt === null;
    })->andReturn($release);
    $this->artisan($sport.':register-calculation-release')->assertSuccessful();
    expect(CalculationRelease::count())->toBe(0);
})->with([
    ['nfl', '1.2.0'], ['cfb', '1.10.1'], ['nba', '1.0.0'], ['cbb', '1.0.0'],
    ['wcbb', '1.0.0'], ['mlb', '1.0.0'], ['wnba', '1.0.0'],
]);

it('preserves draft actor reason version and activation forwarding for every sport', function (string $sport) {
    $class = 'App\\Services\\'.strtoupper($sport).'\\Predictions\\'.ucfirst($sport).'CalculationReleaseRegistrar';
    $release = CalculationRelease::factory()->make(['sport' => $sport, 'semantic_version' => '9.8.7', 'status' => 'draft', 'effective_at' => null]);
    $this->mock($class)->shouldReceive('register')->once()->withArgs(function ($semanticVersion, $approve, $actor, $reason, $effectiveAt) {
        return $semanticVersion === '9.8.7' && $approve === false && $actor === 'reviewer' && $reason === 'Frozen model review'
            && $effectiveAt instanceof CarbonImmutable && $effectiveAt->toIso8601String() === '2026-11-04T12:00:00-06:00';
    })->andReturn($release);
    $command = $this->artisan($sport.':register-calculation-release', [
        '--release-version' => '9.8.7', '--draft' => true, '--actor' => 'reviewer',
        '--reason' => 'Frozen model review', '--effective-at' => '2026-11-04T12:00:00-06:00',
    ]);
    if ($sport === 'wnba') {
        $command->expectsTable(['Field', 'Value'], [
            ['Public ID', $release->public_id], ['Sport / phase', "{$release->sport} / {$release->phase}"],
            ['Calculator', $release->calculator_name], ['Version', '9.8.7'], ['Status', 'draft'],
            ['Code revision', $release->code_revision], ['Configuration hash', $release->configuration_hash],
            ['Input schema', $release->input_schema_version], ['Effective at', 'not active'],
        ]);
    } else {
        $command->expectsOutput(strtoupper($sport).' calculation release 9.8.7 is draft.');
    }
    $command->assertSuccessful();
    expect(CalculationRelease::count())->toBe(0);
})->with(['nfl', 'cfb', 'nba', 'cbb', 'wcbb', 'mlb', 'wnba']);

it('preserves the basketball empty-version fallback', function (string $sport) {
    $prefix = 'App\\Services\\'.strtoupper($sport).'\\Predictions\\'.ucfirst($sport).'CalculationRelease';
    $version = constant($prefix.'Definition::SEMANTIC_VERSION');
    $release = CalculationRelease::factory()->make(['sport' => $sport, 'semantic_version' => $version]);
    $this->mock($prefix.'Registrar')->shouldReceive('register')->once()
        ->withArgs(fn ($semanticVersion) => $semanticVersion === $version)->andReturn($release);
    $this->artisan($sport.':register-calculation-release', ['--release-version' => '', '--draft' => true])->assertSuccessful();
})->with(['nba', 'cbb', 'wcbb', 'wnba']);
