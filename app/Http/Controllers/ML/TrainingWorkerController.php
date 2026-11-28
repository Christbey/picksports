<?php

namespace App\Http\Controllers\ML;

use App\Http\Controllers\Controller;
use App\Http\Requests\ML\TrainingTelemetryRequest;
use App\Http\Requests\ML\TrainingWorkerRequest;
use App\Services\ML\RemoteTrainingService;
use App\Services\ML\TrainingMonitoringService;
use Illuminate\Http\Request;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TrainingWorkerController extends Controller
{
    public function __construct(private readonly RemoteTrainingService $training) {}

    public function heartbeat(TrainingTelemetryRequest $request, TrainingMonitoringService $monitoring): Response
    {
        $monitoring->heartbeat($request->attributes->get('training_worker'), $request->validated('metrics'));

        return response()->json(['status' => 'received']);
    }

    public function progress(TrainingTelemetryRequest $request, string $cycle, int $attempt): Response
    {
        try {
            $this->training->reportProgress(
                $request->attributes->get('training_worker'), $cycle, $attempt, $request->validated('owner'),
                $request->validated(), [$request->bearerToken(), $request->validated('owner')],
            );
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            abort(409, 'Training progress refused.');
        }

        return response()->json(['status' => 'received']);
    }

    public function index(Request $request): Response
    {
        return response()->json(['data' => $this->training->tasks($request->attributes->get('training_worker'))]);
    }

    public function claim(Request $request, string $cycle): Response
    {
        try {
            return response()->json($this->training->claim($request->attributes->get('training_worker'), $cycle));
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            abort(409, 'Training claim refused.');
        }
    }

    public function input(TrainingWorkerRequest $request, string $cycle, int $attempt, string $kind): Response
    {
        try {
            $path = $this->training->input($request->attributes->get('training_worker'), $cycle, $attempt, $request->validated('owner'), $kind);

            return response()->download($path, $kind === 'dataset' ? 'training-data.csv' : 'feature-schema.yaml', ['Cache-Control' => 'private, no-store']);
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            abort(409, 'Training input unavailable.');
        }
    }

    public function upload(TrainingWorkerRequest $request, string $cycle, int $attempt): Response
    {
        try {
            return response()->json($this->training->upload(
                $request->attributes->get('training_worker'), $cycle, $attempt,
                $request->validated('owner'), $request->validated('path'), $request->validated('sha256'), $request->getContent(true), (int) $request->validated('length'),
            ));
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            abort(409, 'Artifact transfer refused.');
        }
    }

    public function complete(TrainingWorkerRequest $request, string $cycle, int $attempt): Response
    {
        try {
            return response()->json($this->training->complete(
                $request->attributes->get('training_worker'), $cycle, $attempt, $request->validated('owner'), $request->validated('result_hash'),
            ));
        } catch (RuntimeException|JsonException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            abort(409, 'Training completion refused.');
        }
    }

    public function fail(TrainingWorkerRequest $request, string $cycle, int $attempt): Response
    {
        $this->training->fail($request->attributes->get('training_worker'), $cycle, $attempt, $request->validated('owner'));

        return response()->json(['status' => 'failed']);
    }
}
