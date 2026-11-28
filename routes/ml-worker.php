<?php

use App\Http\Controllers\ML\TrainingWorkerController;
use App\Http\Middleware\AuthenticateTrainingWorker;
use Illuminate\Support\Facades\Route;

Route::prefix('ml-worker/v1')->name('ml-worker.')->middleware('throttle:60,1')->group(function (): void {
    Route::post('heartbeat', [TrainingWorkerController::class, 'heartbeat'])->middleware(AuthenticateTrainingWorker::class.':report')->name('heartbeat');
    Route::get('cycles', [TrainingWorkerController::class, 'index'])->middleware(AuthenticateTrainingWorker::class.':read')->name('index');
    Route::post('cycles/{cycle}/claim', [TrainingWorkerController::class, 'claim'])->middleware(AuthenticateTrainingWorker::class.':claim')->whereUuid('cycle')->name('claim');
    Route::prefix('cycles/{cycle}/attempts/{attempt}')->whereUuid('cycle')->whereNumber('attempt')->group(function (): void {
        Route::get('inputs/{kind}', [TrainingWorkerController::class, 'input'])->middleware(AuthenticateTrainingWorker::class.':read')->name('input');
        Route::post('progress', [TrainingWorkerController::class, 'progress'])->middleware(AuthenticateTrainingWorker::class.':report')->name('progress');
        Route::put('files', [TrainingWorkerController::class, 'upload'])->middleware(AuthenticateTrainingWorker::class.':upload')->name('upload');
        Route::post('complete', [TrainingWorkerController::class, 'complete'])->middleware(AuthenticateTrainingWorker::class.':complete')->name('complete');
        Route::post('fail', [TrainingWorkerController::class, 'fail'])->middleware(AuthenticateTrainingWorker::class.':complete')->name('fail');
    });
});
