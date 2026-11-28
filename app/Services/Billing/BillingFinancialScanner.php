<?php

namespace App\Services\Billing;

use App\Jobs\Billing\ExecuteBillingMutation;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationState;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** Bounded/fair handoff only. Never invokes a provider or a synchronous queue. */
final class BillingFinancialScanner
{
    public function __construct(private readonly BillingMutationBoundary $database, private readonly BillingMutationJournal $journal) {}

    public function scan(int $limit = 10, bool $apply = false): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Financial scan limit must be 1-100.');
        }
        if (! config('billing.durable.enabled', false)) {
            return ['due' => [], 'enqueued' => 0];
        }
        $this->database->assert();
        $now = BillingClock::now()->format('Y-m-d H:i:s.u');
        $rows = BillingMutationState::query()->whereNotNull('active_intent_id')
            ->where(function ($q) use ($now): void {
                $q->whereIn('status', ['pending', 'pending_next', 'session_open'])
                    ->orWhere(fn ($expired) => $expired->whereIn('status', ['enqueued', 'preparing', 'possible_send'])->where('lease_expires_at', '<=', $now));
            })->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        $result = ['due' => $rows->modelKeys(), 'enqueued' => 0];
        if (! $apply) {
            return $result;
        }
        $connection = (string) config('queue.default');
        $queue = config('queue.connections.'.$connection.'.queue', 'default');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true) || ! is_string($queue)
            || (int) config('queue.worker_contracts.'.$queue.'.timeout', 0) <= 90
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 90) {
            return $result;
        }
        $repairs = BillingMutationState::query()->whereNotNull('active_intent_id')->whereIn('status', ['awaiting_reconciliation', 'payment_pending', 'awaiting_checkout_refresh'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($repairs as $id) {
            $state = BillingMutationState::query()->find($id);
            if ($state?->status === 'awaiting_checkout_refresh') {
                try {
                    app(BillingObservedCheckoutAdmission::class)->admit((int) $id);
                } catch (Throwable) { /* Keep the observed receipt and rotate bounded repair work. */
                }
                BillingMutationState::query()->whereKey($id)->where('active_intent_id', $state->active_intent_id)->where('status', 'awaiting_checkout_refresh')
                    ->update(['next_attempt_at' => BillingClock::now()->addMinute()->format('Y-m-d H:i:s.u'), 'updated_at' => BillingClock::now()->format('Y-m-d H:i:s.u')]);
            } else {
                app(BillingFinancialRefreshRepair::class)->repair((int) $id);
            }
        }
        foreach ($rows as $candidate) {
            $job = DB::transaction(function () use ($candidate): ?ExecuteBillingMutation {
                $user = User::query()->whereKey($candidate->subject_user_id)->lockForUpdate()->first();
                $state = BillingMutationState::query()->lockForUpdate()->find($candidate->id);
                if ($user === null || $state === null || $state->subject_user_id !== (int) $user->id || $state->active_intent_id === null) {
                    return null;
                }
                if (in_array($state->status, ['preparing', 'possible_send'], true)) {
                    if ($state->lease_expires_at === null || $state->lease_expires_at->getTimestamp() > BillingClock::now()->getTimestamp()) {
                        return null;
                    }
                    $intent = BillingMutationIntent::query()->findOrFail($state->active_intent_id);
                    $possible = $state->status === 'possible_send';
                    $this->journal->record($state, $intent, $possible ? 'expired_possible_send' : 'expired_prepare', ['category' => $possible ? 'financial_outcome_unknown' : 'financial_prepare_expired']);
                    $state->update(['status' => $possible ? 'uncertain' : 'pending', 'owner_token' => null, 'lease_expires_at' => null,
                        'failure_category' => $possible ? 'financial_outcome_unknown' : null]);

                    return null;
                }
                if ($state->status === 'enqueued' && $state->lease_expires_at?->getTimestamp() > BillingClock::now()->getTimestamp()) {
                    return null;
                }
                if (! in_array($state->status, ['pending', 'pending_next', 'enqueued', 'session_open'], true)) {
                    return null;
                }
                $token = (string) Str::uuid();
                $state->update(['status' => 'enqueued', 'owner_token' => $token, 'lease_expires_at' => BillingClock::now()->addSeconds(120)]);

                return new ExecuteBillingMutation($state->id, $state->active_intent_id, $token);
            }, attempts: 3);
            if ($job === null) {
                continue;
            }
            try {
                Bus::dispatch($job->onConnection($connection)->onQueue($queue));
                $result['enqueued']++;
            } catch (Throwable) { /* Ambiguous handoff remains leased; expiry can re-enqueue with a new token. */
            }
        }

        return $result;
    }
}
