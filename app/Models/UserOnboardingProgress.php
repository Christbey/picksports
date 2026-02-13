<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOnboardingProgress extends Model
{
    protected $table = 'user_onboarding_progress';

    protected $fillable = [
        'user_id',
        'current_step',
        'completed_steps',
        'progress_percentage',
        'favorite_sports',
        'betting_experience',
        'interests',
        'goals',
        'step_data',
        'started_at',
        'completed_at',
        'last_activity_at',
        'welcome_emails_sent',
        'last_welcome_email_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_steps' => 'array',
            'favorite_sports' => 'array',
            'interests' => 'array',
            'goals' => 'array',
            'step_data' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_welcome_email_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function completeStep(string $stepName): void
    {
        $completedSteps = $this->completed_steps ?? [];

        if (! in_array($stepName, $completedSteps)) {
            $completedSteps[] = $stepName;
            $this->completed_steps = $completedSteps;
        }

        $this->current_step = $this->getNextStep($stepName);
        $this->progress_percentage = $this->calculateProgress();
        $this->last_activity_at = now();

        if ($this->current_step === 'complete') {
            $this->completed_at = now();
        }

        $this->save();
    }

    public function isStepCompleted(string $stepName): bool
    {
        return in_array($stepName, $this->completed_steps ?? []);
    }

    public function calculateProgress(): int
    {
        $totalSteps = 4; // welcome, sport_selection, alert_setup, methodology_review
        $completedCount = count($this->completed_steps ?? []);

        return (int) (($completedCount / $totalSteps) * 100);
    }

    public function getNextStep(?string $currentStep = null): string
    {
        $steps = [
            'welcome' => 'sport_selection',
            'sport_selection' => 'alert_setup',
            'alert_setup' => 'methodology_review',
            'methodology_review' => 'complete',
        ];

        $stepToCheck = $currentStep ?? $this->current_step;

        return $steps[$stepToCheck] ?? 'welcome';
    }

    public function markComplete(): void
    {
        $this->current_step = 'complete';
        $this->progress_percentage = 100;
        $this->completed_at = now();
        $this->last_activity_at = now();
        $this->save();
    }

    public function isComplete(): bool
    {
        return $this->current_step === 'complete' && $this->completed_at !== null;
    }

    public function isAbandoned(int $daysInactive = 7): bool
    {
        if ($this->isComplete()) {
            return false;
        }

        return $this->last_activity_at?->diffInDays(now()) >= $daysInactive;
    }

    public function scopeIncomplete($query)
    {
        return $query->where('current_step', '!=', 'complete')
            ->orWhereNull('completed_at');
    }

    public function scopeAbandoned($query, int $daysInactive = 7)
    {
        return $query->incomplete()
            ->where('last_activity_at', '<=', now()->subDays($daysInactive));
    }

    public function scopeCompletedToday($query)
    {
        return $query->whereDate('completed_at', today());
    }

    public function scopeStartedToday($query)
    {
        return $query->whereDate('started_at', today());
    }

    public function scopeInProgress($query)
    {
        return $query->whereNotNull('started_at')
            ->whereNull('completed_at');
    }
}
