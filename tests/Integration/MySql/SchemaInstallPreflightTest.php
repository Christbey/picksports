<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('installs the complete schema on the guarded disposable MySQL target', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    expect(Schema::hasTable('personal_access_tokens'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBeGreaterThan(0);
})->group('mysql-schema-preflight');
