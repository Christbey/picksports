<?php

use App\Listeners\NotificationDeliverySubscriber;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\NotificationDeliveryService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

it('does not resend after an exception whose provider outcome is unknown', function () {
    $subscriber = app(NotificationDeliverySubscriber::class);
    $user = User::factory()->create();
    $notification = new ResetPassword('synthetic-token');
    $notification->id = 'synthetic-stable-delivery';
    $event = new NotificationSending($user, $notification, 'mail');
    expect($subscriber->sending($event))->toBeNull();
    $subscriber->failed(new NotificationFailed($user, $notification, 'mail', ['exception' => new RuntimeException('Synthetic reply lost after possible acceptance')]));
    expect(NotificationDelivery::query()->sole()->status)->toBe('outcome_unknown')
        ->and($subscriber->sending($event))->toBeFalse();
    // A late positive receipt for the sole attempted send may settle uncertainty.
    $subscriber->sent(new NotificationSent($user, $notification, 'mail', null));
    expect(NotificationDelivery::query()->sole()->status)->toBe('accepted')
        ->and($subscriber->sending($event))->toBeFalse();
});

it('cannot reopen an in-flight delivery through dispatch or suppression callbacks', function (string $transition) {
    $service = app(NotificationDeliveryService::class);
    $delivery = $service->record('synthetic', 'synthetic-recipient', 'mail', 'synthetic-key');
    expect($service->claim($delivery))->toBeTrue();
    if ($transition === 'dispatch') {
        $service->markDispatched($delivery);
    } else {
        $service->markSuppressed($delivery, 'synthetic-stale-suppression');
    }
    expect($delivery->fresh()->status)->toBe('sending')
        ->and($service->claim($delivery))->toBeFalse();
})->with(['dispatch', 'suppression']);

it('does not automatically replay historical failed or suppressed deliveries', function (string $status) {
    $delivery = NotificationDelivery::query()->create([
        'type' => 'synthetic', 'recipient_key' => 'synthetic-recipient', 'channel' => 'mail',
        'deduplication_key' => 'synthetic-key', 'status' => $status,
    ]);
    $service = app(NotificationDeliveryService::class);
    $service->markDispatched($delivery);
    expect($service->claim($delivery))->toBeFalse()
        ->and($delivery->fresh()->status)->toBe($status);
})->with(['failed', 'suppressed', 'outcome_unknown']);

it('fences actual framework retry after a synthetic transport accepted then lost its reply', function () {
    $transport = new class
    {
        public int $acceptances = 0;

        public function send(object $notifiable, object $notification): void
        {
            $this->acceptances++;
            throw new RuntimeException('Synthetic reply lost after acceptance');
        }
    };
    Illuminate\Support\Facades\Notification::extend('synthetic_lost_ack', fn () => $transport);
    $notification = new PickSportsSyntheticLostAckNotification;
    $notification->id = 'synthetic-framework-stable-id';
    $user = User::factory()->create();
    expect(fn () => $user->notifyNow($notification))->toThrow(RuntimeException::class, 'Synthetic reply lost after acceptance');
    expect(NotificationDelivery::query()->sole()->status)->toBe('outcome_unknown');
    $user->notifyNow($notification);
    expect($transport->acceptances)->toBe(1)
        ->and(NotificationDelivery::query()->sole()->status)->toBe('outcome_unknown');
});

class PickSportsSyntheticLostAckNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['synthetic_lost_ack'];
    }
}

it('keeps unresolved admin mail visible and does not automatically resend it', function (string $status) {
    Mail::fake();
    $recipient = 'synthetic-admin@example.test';
    $date = now('America/Chicago')->subDay()->toDateString();
    $delivery = NotificationDelivery::query()->create([
        'type' => 'admin_report', 'recipient_key' => hash('sha256', $recipient), 'channel' => 'mail',
        'deduplication_key' => 'admin_report:'.$date.':'.hash('sha256', $recipient), 'status' => $status,
    ]);
    $this->artisan('alerts:send-admin-email-report', ['--date' => $date, '--to' => [$recipient]])->assertSuccessful();
    Mail::assertNothingOutgoing();
    expect($delivery->fresh()->status)->toBe($status);
    Artisan::call('alerts:send-admin-email-report', ['--date' => $date, '--to' => [$recipient], '--dry-run' => true]);
    $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect(collect($output['report']['delivery_backlog'])->where('status', $status)->sum('count'))->toBe(1);
})->with(['failed', 'outcome_unknown']);
