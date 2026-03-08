<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserOverviewController extends Controller
{
    private const USERS_PER_PAGE = 20;

    private const SUBMISSIONS_PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'created_at'])
            ->latest()
            ->paginate(self::USERS_PER_PAGE, ['*'], 'users_page');

        $users->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at?->toISOString(),
        ]);

        $submissions = Submission::query()
            ->latest()
            ->paginate(self::SUBMISSIONS_PER_PAGE, ['*'], 'submissions_page');

        $submissions->through(fn (Submission $submission) => [
            'id' => $submission->id,
            'name' => $submission->name,
            'email' => $submission->email,
            'subject' => $submission->subject,
            'message' => $submission->message,
            'page_url' => $submission->page_url,
            'status' => $submission->status,
            'created_at' => $submission->created_at?->toISOString(),
        ]);

        return Inertia::render('Admin/Users', [
            'stats' => [
                'total_users' => User::query()->count(),
                'new_users_today' => User::query()
                    ->whereDate('created_at', today())
                    ->count(),
                'total_submissions' => Submission::query()->count(),
                'submissions_today' => Submission::query()
                    ->whereDate('created_at', today())
                    ->count(),
            ],
            'users' => $users,
            'submissions' => $submissions,
        ]);
    }
}
