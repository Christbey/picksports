<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => [],
        'subscriptions.tiers.basic.stripe_price_id.monthly' => 'price_boundary_basic',
        'subscriptions.tiers.pro.stripe_price_id.monthly' => 'price_boundary_pro']);
});

it('refuses an unconfigured explicit subscription tier on registered test routes', function (string $tier, string $channel) {
    $user = User::factory()->create();
    DB::table('subscriptions')->insert(['user_id' => $user->id, 'type' => 'default',
        'stripe_id' => 'sub_boundary_'.Str::random(16), 'stripe_status' => 'active',
        'stripe_price' => 'price_boundary_pro', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $path = '/__tests__/tier-boundary/'.$channel.'/'.bin2hex($tier);
    Route::middleware([$channel, $channel === 'api' ? 'auth:sanctum' : 'auth', 'subscribed:'.$tier])
        ->get($path, fn () => response()->json(['protected_handler_ran' => true]));
    if ($channel === 'api') {
        Sanctum::actingAs($user);
    } else {
        $this->actingAs($user);
    }
    $this->getJson($path)->assertForbidden()->assertJsonMissing(['protected_handler_ran' => true]);
})->with(['unconfigured', 'PRO', '0', ''])->with(['web', 'api']);

it('preserves configured subscription tier ordering on registered test routes', function (string $current, string $required, bool $allowed) {
    $user = User::factory()->create();
    DB::table('subscriptions')->insert(['user_id' => $user->id, 'type' => 'default',
        'stripe_id' => 'sub_boundary_'.Str::random(16), 'stripe_status' => 'active',
        'stripe_price' => 'price_boundary_'.$current, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $path = '/__tests__/tier-boundary/known/'.$current.'/'.$required;
    Route::middleware(['web', 'auth', 'subscribed:'.$required])
        ->get($path, fn () => response()->json(['protected_handler_ran' => true]));
    $response = $this->actingAs($user)->getJson($path);
    if ($allowed) {
        $response->assertOk()->assertJsonPath('protected_handler_ran', true);
    } else {
        $response->assertForbidden()->assertJsonMissing(['protected_handler_ran' => true]);
    }
})->with([['basic', 'basic', true], ['basic', 'pro', false], ['pro', 'basic', true], ['pro', 'pro', true]]);
