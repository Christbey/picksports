<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingFinancialAdmission;
use Illuminate\Http\Request;

class BillingPortalController extends Controller
{
    public function __invoke(Request $request)
    {
        $intent = app(BillingFinancialAdmission::class)->admit($request->user(), 'portal');

        return redirect()->route('subscription.operation', ['intent' => $intent->id]);
    }
}
