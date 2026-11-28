<?php

use App\Services\TwilioWhatsAppService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('services.twilio_whatsapp', [
        'account_sid' => 'ACsynthetic',
        'auth_token' => 'synthetic-token',
        'from' => '+15550000001',
    ]);
    Http::preventStrayRequests();
    Log::spy();
});

test('whatsapp rejection logs status without provider content', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'private-message +15550000002 synthetic-token'], 400)]);

    expect(app(TwilioWhatsAppService::class)->sendMessage('+15550000002', 'private-message'))->toBeFalse();
    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->once()->with('Twilio WhatsApp request failed.', ['status' => 400]);
});

test('whatsapp connection failure logs class without exception content or retry', function () {
    Http::fake(fn () => throw new ConnectionException('private-message +15550000002 synthetic-token'));

    expect(app(TwilioWhatsAppService::class)->sendMessage('+15550000002', 'private-message'))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->with('Twilio WhatsApp request threw an exception.', ['exception_class' => ConnectionException::class]);
});

test('whatsapp accepted request preserves destination and message without logging content', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMsynthetic'], 201)]);

    expect(app(TwilioWhatsAppService::class)->sendMessage('+15550000002', 'private-message'))->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACsynthetic/Messages.json'
        && $request['To'] === 'whatsapp:+15550000002'
        && $request['From'] === 'whatsapp:+15550000001'
        && $request['Body'] === 'private-message');
    Http::assertSentCount(1);
    Log::shouldNotHaveReceived('warning');
});

test('whatsapp missing configuration sends nothing and logs no credentials', function () {
    config()->set('services.twilio_whatsapp.auth_token', '');
    Http::fake();

    expect(app(TwilioWhatsAppService::class)->sendMessage('+15550000002', 'private-message'))->toBeFalse();
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->with('Twilio WhatsApp is not configured. Skipping alert dispatch.');
});
