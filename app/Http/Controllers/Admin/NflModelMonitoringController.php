<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NFL\NflModelMonitoringService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NflModelMonitoringController extends Controller
{
    public function __invoke(Request $request, NflModelMonitoringService $monitoring): Response
    {
        return Inertia::render('Admin/NflModelMonitoring', $monitoring->dashboard(
            $request->string('artifact')->trim()->toString() ?: null,
        ));
    }
}
