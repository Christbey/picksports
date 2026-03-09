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

    private const ACTIVE_WINDOW_MINUTES = 5;

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $sort = $request->string('sort')->toString();

        $users = User::query()
            ->select(['id', 'name', 'email', 'created_at', 'last_active_at'])
            ->when($status === 'active', fn ($query) => $query->where('last_active_at', '>=', now()->subMinutes(self::ACTIVE_WINDOW_MINUTES)))
            ->when($status === 'offline', fn ($query) => $query->where(function ($query): void {
                $query->whereNull('last_active_at')
                    ->orWhere('last_active_at', '<', now()->subMinutes(self::ACTIVE_WINDOW_MINUTES));
            }))
            ->when($sort === 'name_asc', fn ($query) => $query->orderBy('name'))
            ->when($sort === 'name_desc', fn ($query) => $query->orderByDesc('name'))
            ->when($sort === 'created_asc', fn ($query) => $query->oldest())
            ->when($sort === 'last_active_asc', fn ($query) => $query->orderByRaw('last_active_at IS NULL, last_active_at ASC'))
            ->when($sort === 'last_active_desc', fn ($query) => $query->orderByRaw('last_active_at IS NULL, last_active_at DESC'))
            ->when(! in_array($sort, ['name_asc', 'name_desc', 'created_asc', 'last_active_asc', 'last_active_desc'], true), fn ($query) => $query->latest())
            ->withQueryString()
            ->paginate(self::USERS_PER_PAGE, ['*'], 'users_page');

        $users->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at?->toISOString(),
            'last_active_at' => $user->last_active_at?->toISOString(),
            'is_active' => $user->last_active_at?->gte(now()->subMinutes(self::ACTIVE_WINDOW_MINUTES)) ?? false,
        ]);

        $submissions = Submission::query()
            ->latest()
            ->withQueryString()
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
                'active_users_now' => User::query()
                    ->where('last_active_at', '>=', now()->subMinutes(self::ACTIVE_WINDOW_MINUTES))
                    ->count(),
                'total_submissions' => Submission::query()->count(),
                'submissions_today' => Submission::query()
                    ->whereDate('created_at', today())
                    ->count(),
            ],
            'filters' => [
                'status' => in_array($status, ['active', 'offline'], true) ? $status : 'all',
                'sort' => in_array($sort, ['name_asc', 'name_desc', 'created_asc', 'last_active_asc', 'last_active_desc'], true)
                    ? $sort
                    : 'created_desc',
            ],
            'meta' => [
                'active_window_minutes' => self::ACTIVE_WINDOW_MINUTES,
                'server_time' => now()->toISOString(),
            ],
            'users' => $users,
            'submissions' => $submissions,
        ]);
    }
}
