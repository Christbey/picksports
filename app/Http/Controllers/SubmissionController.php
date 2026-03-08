<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionRequest;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;

class SubmissionController extends Controller
{
    /**
     * Store a new submission.
     */
    public function store(StoreSubmissionRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        Submission::create([
            'user_id' => $user?->id,
            'name' => $user?->name,
            'email' => $user?->email,
            'subject' => $validated['subject'] ?? null,
            'message' => $validated['message'],
            'page_url' => $validated['page_url'] ?? null,
            'status' => 'new',
        ]);

        return back(303);
    }
}
