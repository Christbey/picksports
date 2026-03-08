<?php

use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::post('/submissions', [SubmissionController::class, 'store'])->name('submissions.store');
});
