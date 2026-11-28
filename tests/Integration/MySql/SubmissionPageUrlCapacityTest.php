<?php

use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
    Notification::fake();
    config(['billing.entitlements.enabled' => false, 'billing.durable.enabled' => false]);
});

it('stores every admitted submission context length without truncation on native mysql', function () {
    $user = User::factory()->create();
    foreach ([255, 256, 2048] as $length) {
        $prefix = 'https://context.example.test/';
        $url = $prefix.str_repeat('x', $length - strlen($prefix));
        $this->actingAs($user)->post(route('submissions.store'), ['message' => 'Synthetic capacity proof', 'page_url' => $url])->assertRedirect();
        expect(Submission::query()->latest('id')->firstOrFail()->page_url)->toBe($url);
    }
    $unicode = str_repeat('é', 2048);
    $this->actingAs($user)->post(route('submissions.store'), ['message' => 'Synthetic multibyte context', 'page_url' => $unicode])->assertRedirect();
    expect(Submission::query()->latest('id')->firstOrFail()->page_url)->toBe($unicode);
    $this->actingAs($user)->postJson(route('submissions.store'), ['message' => 'Synthetic over-limit proof', 'page_url' => str_repeat('x', 2049)])->assertUnprocessable()->assertJsonValidationErrors('page_url');
    expect(Submission::query()->count())->toBe(4);
    $this->actingAs($user)->post(route('submissions.store'), ['message' => 'Synthetic unknown context', 'page_url' => null])->assertRedirect();
    expect(Submission::query()->latest('id')->firstOrFail()->page_url)->toBeNull();
});

it('preserves legacy context bytes and nullable attributes through the forward capacity expansion', function () {
    $connection = DB::connection();
    $connection->statement("ALTER TABLE submissions MODIFY page_url VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'synthetic context metadata'");
    $known = str_repeat('a', 255);
    $connection->table('submissions')->insert([['message' => 'Synthetic known context', 'page_url' => $known], ['message' => 'Synthetic unknown context', 'page_url' => null]]);
    $migration = require database_path('migrations/2026_11_26_180000_expand_submission_page_url_capacity.php');
    $migration->up();
    $migration->up();
    $column = $connection->selectOne("SELECT CHARACTER_MAXIMUM_LENGTH AS capacity, IS_NULLABLE AS nullable_state, COLUMN_DEFAULT AS default_value, COLLATION_NAME AS collation_name, COLUMN_COMMENT AS column_comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submissions' AND COLUMN_NAME='page_url'", [], false);
    expect((int) $column->capacity)->toBe(2048)
        ->and($column->nullable_state)->toBe('YES')
        ->and($column->default_value)->toBeNull()
        ->and($column->collation_name)->toBe('utf8mb4_unicode_ci')
        ->and($column->column_comment)->toBe('synthetic context metadata')
        ->and($connection->table('submissions')->orderBy('id')->pluck('page_url')->all())->toBe([$known, null]);
    expect(fn () => $migration->down())->toThrow(LogicException::class);
});
