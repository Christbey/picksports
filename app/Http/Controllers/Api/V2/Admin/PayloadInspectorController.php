<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Admin\PayloadInspectorRequest;
use App\Http\Resources\Api\V2\Admin\PayloadInspectorResource;
use App\Services\Api\V2\Admin\DashboardPayloadInspector;

class PayloadInspectorController extends Controller
{
    public function __invoke(
        PayloadInspectorRequest $request,
        DashboardPayloadInspector $inspector,
    ): PayloadInspectorResource {
        return PayloadInspectorResource::make(
            $inspector->inspect($request->inspectorInputs())
        );
    }
}
