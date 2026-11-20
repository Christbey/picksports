<?php

use App\Listeners\RecordMailDelivery;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\NotificationDeliveryService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;

it('records actual direct mail transport acceptance without storing recipient addresses', function () {
    Mail::raw('Accounting test', fn ($message) => $message->to('reader@example.test')->subject('Test'));

    $delivery = NotificationDelivery::query()->sole();
    expect($delivery->channel)->toBe('mail')
        ->and($delivery->status)->toBe('accepted')
        ->and($delivery->recipient_key)->toBe(hash('sha256', 'reader@example.test'))
        ->and($delivery->accepted_at)->not->toBeNull();
});

it('updates an explicit send record and does not double count repeated mail events', function () {
    $deliveries = app(NotificationDeliveryService::class);
    $delivery = $deliveries->record('admin_report', hash('sha256', 'admin@example.test'), 'mail', 'admin:test');
    $message = (new Email)->from('sender@example.test')->to('admin@example.test')->subject('Test')->text('Test');
    $message->getHeaders()->addTextHeader('X-PickSports-Delivery-Key', 'admin:test');
    $event = new MessageSent(new SentMessage(new SymfonySentMessage($message, Envelope::create($message))));

    app(RecordMailDelivery::class)->handle($event);
    app(RecordMailDelivery::class)->handle($event);

    expect(NotificationDelivery::query()->count())->toBe(1)
        ->and($delivery->fresh()->status)->toBe('accepted')
        ->and($delivery->fresh()->type)->toBe('admin_report');
});

it('leaves notification accounting to notification lifecycle events', function () {
    $message = (new Email)->from('sender@example.test')->to('reader@example.test')->subject('Test')->text('Test');
    $event = new MessageSent(new SentMessage(new SymfonySentMessage($message, Envelope::create($message))), [
        '__laravel_notification' => 'ExampleNotification',
    ]);

    app(RecordMailDelivery::class)->handle($event);

    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('accounts for authentication mail once through notification lifecycle events', function () {
    $user = User::factory()->create();

    $user->notify(new ResetPassword('test-token'));

    $delivery = NotificationDelivery::query()->sole();
    expect($delivery->type)->toBe('ResetPassword')
        ->and($delivery->status)->toBe('accepted')
        ->and($delivery->recipient_key)->toBe(hash('sha256', strtolower($user->email)));
});

it('accounts for additional recipients without reusing the primary delivery record', function () {
    app(NotificationDeliveryService::class)->record('admin_report', hash('sha256', 'primary@example.test'), 'mail', 'admin:primary');
    $message = (new Email)->from('sender@example.test')->to('primary@example.test')
        ->cc('copy@example.test')->bcc('copy@example.test')->subject('Test')->text('Test');
    $message->getHeaders()->addTextHeader('X-PickSports-Delivery-Key', 'admin:primary');
    $event = new MessageSent(new SentMessage(new SymfonySentMessage($message, Envelope::create($message))));

    app(RecordMailDelivery::class)->handle($event);

    expect(NotificationDelivery::query()->where('status', 'accepted')->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('recipient_key', hash('sha256', 'copy@example.test'))->count())->toBe(1);
});
