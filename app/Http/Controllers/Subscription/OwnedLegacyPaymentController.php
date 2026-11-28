<?php

namespace App\Http\Controllers\Subscription;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use Laravel\Cashier\Http\Controllers\PaymentController;

/** Retires the public opaque-PI audience; new financial flows use owner-bound hosted-invoice continuation. */
final class OwnedLegacyPaymentController extends PaymentController
{
    public function __construct()
    {
        parent::__construct();
        $this->middleware('auth');
    }

    public function show($id)
    {
        if (! is_string($id) || preg_match('/\Api_[A-Za-z0-9_]{1,188}\z/', $id) !== 1) {
            abort(404);
        }
        // No provider lookup is performed on the strength of a payment-intent ID.
        $receipts = BillingMutationReceipt::query()->where('facts->payment_intent_id', $id)->orderByDesc('id')->limit(2)->get();
        if ($receipts->count() === 1) {
            $intent = BillingMutationIntent::query()->whereKey($receipts->first()->intent_id)->where('subject_user_id', request()->user()->id)->first();
            if ($intent !== null && ($receipts->first()->facts['payment_intent_id'] ?? null) === $id) {
                return redirect()->route('subscription.operation', ['intent' => $intent->id])->header('Cache-Control', 'no-store, private');
            }
        }
        abort(403, 'This legacy payment link cannot establish account ownership. Open your billing request or contact support to recover the existing payment; do not start another purchase.');
    }
}
