<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationState;
use App\Services\Billing\BillingAccountContext;
use App\Services\Billing\BillingClock;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class BillingOperationController extends Controller
{
    public function __invoke(Request $request, string $intent)
    {
        $id = filter_var($intent, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $operation = BillingMutationIntent::query()->whereKey($id)->where('subject_user_id', $request->user()->id)->firstOrFail();
        $state = BillingMutationState::query()->where('subject_user_id', $request->user()->id)->first();
        $result = $state?->result;
        $currentResult = is_array($result) && ($result['intent_id'] ?? null) === $operation->id;
        $bound = $state !== null && $state->customer_id === $request->user()->fresh()->stripe_id;
        try {
            $context = new BillingAccountContext;
            $bound = $bound && $operation->account_id === $context->accountId && $operation->livemode === $context->livemode;
        } catch (\Throwable) {
            $bound = false;
        }
        $expired = $currentResult && ($state->result_expires_at === null || $state->result_expires_at->getTimestamp() <= BillingClock::now()->getTimestamp());
        $liveResult = $bound && $currentResult && ! $expired;
        $active = $state?->active_intent_id === $operation->id;
        $status = $active ? $state->status : ($currentResult ? $state->status : 'superseded');
        $data = ['id' => $operation->id, 'kind' => $operation->kind, 'status' => $status,
            'failure_category' => $active ? $state->failure_category : null,
            'url' => $liveResult ? ($result['url'] ?? null) : null,
            'result_expired' => $expired,
            'result_unavailable' => $currentResult && ! $bound,
            'message' => match ($status) {
                'uncertain', 'attention' => 'This billing outcome requires support review. Do not start another purchase.',
                'payment_pending' => 'Payment or authentication is still pending. Access is not confirmed by this request.',
                'session_open' => 'Open the admitted checkout to continue. Access updates after provider confirmation.',
                'expired' => 'The provider confirmed this checkout expired without an attached subscription. The customer record remains; a new purchase may be requested.',
                'completed' => 'The billing operation completed. Billing status and effective access may differ.',
                'awaiting_reconciliation' => 'The provider acknowledged the request. Current billing reconciliation is pending.',
                default => 'The billing request is pending. No completed payment or access change is promised.',
            }];
        $response = $request->expectsJson() ? response()->json($data) : Inertia::render('Subscription/Operation', ['operation' => $data])->toResponse($request);

        return $response->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
