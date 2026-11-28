<?php

use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\PasskeyService;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    Http::preventStrayRequests();
    config(['passkeys.enabled' => true, 'passkeys.rp_id' => 'localhost', 'passkeys.origin' => 'http://localhost']);
    $column = DB::selectOne('SELECT COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['passkeys', 'credential_id']);
    $evidence = Env::get('PICKSPORTS_PASSKEY_SCHEMA_EVIDENCE');
    if (is_string($evidence) && $evidence !== '') {
        file_put_contents($evidence, json_encode(['column' => $column, 'indexes' => DB::select('SHOW INDEX FROM passkeys')], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    }
});

function passkeyNativePayload($test, User $user, string $credential): array
{
    $service = app(PasskeyService::class);
    $options = $test->actingAs($user)->postJson('/passkeys/registration/options')->assertOk();
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048]);
    expect($key)->not->toBeFalse();
    $details = openssl_pkey_get_details($key);
    expect($details['type'])->toBe(OPENSSL_KEYTYPE_EC)->and($details['bits'])->toBe(256);
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']), true);
    expect($der)->not->toBeFalse();

    return ['name' => 'Synthetic native storage boundary', 'credential_id' => $credential,
        'public_key' => $service->base64UrlEncode($der), 'algorithm' => -7,
        'client_data_json' => $service->base64UrlEncode(json_encode(['type' => 'webauthn.create',
            'challenge' => $options->json('publicKey.challenge'), 'origin' => 'http://localhost'], JSON_THROW_ON_ERROR)),
        'authenticator_data' => $service->base64UrlEncode(hash('sha256', 'localhost', true).chr(5).pack('N', 1)),
        'transports' => ['internal']];
}

it('stores case-distinct canonical base64url credential IDs as independent native credentials', function () {
    $user = User::factory()->create();
    $service = app(PasskeyService::class);
    $first = $service->base64UrlEncode("\x00");
    $second = $service->base64UrlEncode("\x68");
    expect([$first, $second])->toBe(['AA', 'aA'])->and($service->base64UrlDecode($first))->not->toBe($service->base64UrlDecode($second));
    $this->postJson('/passkeys/registration/verify', passkeyNativePayload($this, $user, $first))->assertOk();
    $stored = Passkey::query()->firstOrFail()->getRawOriginal();
    $response = $this->postJson('/passkeys/registration/verify', passkeyNativePayload($this, $user, $second));
    expect(Passkey::query()->whereKey($stored['id'])->firstOrFail()->getRawOriginal())->toBe($stored);
    $response->assertOk();
    expect(Passkey::query()->orderBy('id')->pluck('credential_id')->all())->toBe([$first, $second]);
    Http::assertNothingSent();
});

it('stores the full supported 512 character canonical native credential without truncation', function () {
    $user = User::factory()->create();
    $raw = str_repeat("\x01", 384);
    $credential = app(PasskeyService::class)->base64UrlEncode($raw);
    expect(strlen($credential))->toBe(512)->and(app(PasskeyService::class)->base64UrlDecode($credential))->toBe($raw);
    $this->postJson('/passkeys/registration/verify', passkeyNativePayload($this, $user, $credential))->assertOk();
    expect(Passkey::query()->sole()->getRawOriginal('credential_id'))->toBe($credential);
    Http::assertNothingSent();
});

function passkeyNativeSchema(): array
{
    $columns = array_map(fn ($row) => (array) $row, DB::select('SHOW FULL COLUMNS FROM passkeys'));
    $indexes = array_map(function ($row) {
        $values = (array) $row;
        unset($values['Cardinality']);

        return $values;
    }, DB::select('SHOW INDEX FROM passkeys'));

    return [$columns, $indexes];
}

it('expands legacy native credential storage while preserving rows column attributes and complete indexes', function () {
    $user = User::factory()->create();
    Passkey::create(['user_id' => $user->id, 'credential_id' => 'AA', 'name' => 'Preserved native value',
        'public_key' => 'synthetic-row-preservation-only', 'algorithm' => -7, 'sign_count' => 7, 'transports' => ['internal']]);
    DB::statement("ALTER TABLE passkeys MODIFY credential_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'preserved opaque ID comment'");
    $rows = DB::table('passkeys')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $before = passkeyNativeSchema();
    $expected = $before;
    foreach ($expected[0] as &$column) {
        if ($column['Field'] === 'credential_id') {
            $column['Type'] = 'varchar(512)';
            $column['Collation'] = 'utf8mb4_bin';
        }
    }
    unset($column);
    $migration = require database_path('migrations/2026_11_27_120000_expand_passkey_credential_identity.php');
    $migration->up();
    expect(passkeyNativeSchema())->toBe($expected)
        ->and(DB::table('passkeys')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($rows);
    $migration->up();
    expect(passkeyNativeSchema())->toBe($expected);
    expect(fn () => $migration->down())->toThrow(LogicException::class);
    expect(passkeyNativeSchema())->toBe($expected);
});

it('refuses unsupported native credential collation drift before changing schema', function () {
    $original = passkeyNativeSchema();
    DB::statement('ALTER TABLE passkeys MODIFY credential_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
    try {
        $before = passkeyNativeSchema();
        $migration = require database_path('migrations/2026_11_27_120000_expand_passkey_credential_identity.php');
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Unexpected passkey credential schema');
        expect(passkeyNativeSchema())->toBe($before);
    } finally {
        DB::statement('ALTER TABLE passkeys MODIFY credential_id VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }
    expect(passkeyNativeSchema())->toBe($original);
});
