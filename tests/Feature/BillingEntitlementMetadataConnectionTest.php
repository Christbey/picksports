<?php

use App\Models\User;
use App\Services\Billing\BillingRoleEntitlements;
use Illuminate\Support\Facades\DB;

it('finds durable billing classification on the write database when the read replica lacks the table', function () {
    $original = DB::getDefaultConnection();
    $name = 'billing_metadata_split';
    $write = new PDO('sqlite::memory:');
    $write->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
    $write->exec('INSERT INTO users (id) VALUES (42)');
    $write->exec('CREATE TABLE billing_role_entitlements (user_id INTEGER, role_id INTEGER, origin TEXT)');
    $write->exec("INSERT INTO billing_role_entitlements VALUES (42, 9, 'manual')");
    config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'billing.entitlements.enabled' => false, 'billing.durable.enabled' => false]);
    try {
        $connection = DB::connection($name);
        $connection->setPdo($write)->setReadPdo(new PDO('sqlite::memory:'));
        DB::setDefaultConnection($name);
        $user = (new User)->setConnection($name)->setRawAttributes(['id' => 42], true);
        $user->exists = true;
        expect((clone $connection)->getSchemaBuilder()->hasTable('billing_role_entitlements'))->toBeFalse();
        expect($connection->getSchemaGrammar())->toBeNull();
        expect(app(BillingRoleEntitlements::class)->hasRecorded($user))->toBeTrue();
    } finally {
        DB::setDefaultConnection($original);
        DB::purge($name);
    }
});
