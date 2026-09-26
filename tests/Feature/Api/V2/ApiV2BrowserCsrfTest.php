<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

class ApiV2BrowserTestCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

it('requires a CSRF token for authenticated stateful browser mutations', function () {
    config()->set('sanctum.stateful', ['localhost']);
    config()->set('sanctum.middleware.validate_csrf_token', ApiV2BrowserTestCsrfMiddleware::class);
    Route::middleware(['api', 'v2.auth'])->post('/api/v2/browser-csrf-test', fn () => response()->json(['ok' => true]));
    $this->actingAs(User::factory()->create())->withSession(['_token' => 'browser-token']);

    $this->withHeaders(['Origin' => 'http://localhost'])->postJson('/api/v2/browser-csrf-test')
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_token_mismatch');
    $this->withHeaders(['Origin' => 'http://localhost', 'X-CSRF-TOKEN' => 'browser-token'])
        ->postJson('/api/v2/browser-csrf-test')->assertOk()->assertJsonPath('ok', true);
});
