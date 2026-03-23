<?php

namespace App\Notifications;

use App\Models\ValidationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ValidationRegressionAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{failing:int,warning:int,passing:int}  $delta
     */
    public function __construct(
        public ValidationRun $run,
        public array $delta,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $scope = $this->run->scope === 'all_sports'
            ? 'all sports'
            : strtoupper((string) str_replace('sport:', '', (string) $this->run->scope));
        $summary = is_array($this->run->ai_summary) ? $this->run->ai_summary : [];
        $headline = (string) ($summary['headline'] ?? 'Validation regression detected');
        $intro = (string) ($summary['intro'] ?? 'A validation run has regressed compared with the previous run.');
        $recommendedActions = array_values(array_filter(
            array_map('strval', (array) ($summary['recommended_actions'] ?? [])),
            fn (string $action) => trim($action) !== ''
        ));

        $message = (new MailMessage)
            ->subject("Validation regression detected for {$scope}")
            ->greeting('Validation regression detected')
            ->line($headline)
            ->line($intro)
            ->line("Scope: {$scope}")
            ->line('Failing delta: '.($this->delta['failing'] > 0 ? '+' : '').$this->delta['failing'])
            ->line('Warning delta: '.($this->delta['warning'] > 0 ? '+' : '').$this->delta['warning'])
            ->line('Passing delta: '.($this->delta['passing'] > 0 ? '+' : '').$this->delta['passing']);

        foreach (array_slice($recommendedActions, 0, 3) as $action) {
            $message->line("Recommended action: {$action}");
        }

        return $message->action('Review Health Checks', route('admin.healthchecks', [
            'view' => 'validation',
            'sport' => $this->run->scope === 'all_sports' ? null : str_replace('sport:', '', (string) $this->run->scope),
            'validation_run' => $this->run->id,
        ]));
    }
}
