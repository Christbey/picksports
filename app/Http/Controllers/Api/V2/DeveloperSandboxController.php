<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\DeveloperApiCredential;
use App\Models\DeveloperEntitlementPolicy;
use App\Support\Api\ApiV2ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeveloperSandboxController extends Controller
{
    public function __invoke(Request $request, ApiV2ErrorResponse $errorResponse): JsonResponse
    {
        /** @var DeveloperApiCredential $credential */
        $credential = $request->attributes->get('developer_api_credential');
        /** @var DeveloperEntitlementPolicy $policy */
        $policy = $request->attributes->get('developer_entitlement_policy');

        return response()->json([
            'data' => [
                'mode' => 'sandbox',
                'organization_id' => $credential->organization->public_id,
                'credential_id' => $credential->public_id,
                'product' => $policy->product->code,
                'scope' => 'sandbox:read',
                'request_id' => $errorResponse->requestId($request),
            ],
        ]);
    }
}
