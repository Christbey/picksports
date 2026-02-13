<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BillingPortalController extends Controller
{
    public function __invoke(Request $request)
    {
        $billingPortal = $request->user()->billingPortalUrl(route('subscription.manage'));

        return inertia()->location($billingPortal);
    }
}
