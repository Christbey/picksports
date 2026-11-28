<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ML\TrainingMonitoringService;
use App\Services\MLB\MlbModelMonitoringService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MlbModelMonitoringController extends Controller
{
    public function __invoke(Request $request, MlbModelMonitoringService $monitoring): Response
    {
        return Inertia::render('Admin/MlbModelMonitoring', ['training_operations' => app(TrainingMonitoringService::class)->dashboard('mlb'), ...$monitoring->dashboard(
            $request->string('artifact')->trim()->toString() ?: null,
        )]);
    }
}
