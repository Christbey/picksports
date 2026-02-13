<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class BettingValueAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Model $prediction,
        public string $sport,
        public float $expectedValue,
        public string $recommendation,
        public ?NotificationTemplate $template = null
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable->alertPreference?->shouldReceiveEmailNotifications()) {
            $channels[] = 'mail';
        }

        if ($notifiable->alertPreference?->shouldReceivePushNotifications()) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->template) {
            $data = $this->buildTemplateData($notifiable);

            $subject = $this->template->renderSubject($data);
            $body = $this->template->renderEmailBody($data);

            return (new MailMessage)
                ->subject($subject)
                ->greeting('')
                ->line($body)
                ->action('View Full Analysis', $this->getPredictionUrl());
        }

        // Fallback to hardcoded content if no template provided
        $game = $this->prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';

        $gameTime = $game->game_date->format('M j, Y g:i A');
        $edgePercent = round($this->expectedValue, 1);

        return (new MailMessage)
            ->subject("High-Value Betting Opportunity: {$awayTeam} @ {$homeTeam}")
            ->greeting("Value Alert: {$edgePercent}% Expected Value")
            ->line("We've identified a high-value betting opportunity for {$this->getSportName()}:")
            ->line("**{$awayTeam} @ {$homeTeam}**")
            ->line("Game Time: {$gameTime}")
            ->line("Recommendation: {$this->recommendation}")
            ->line("Expected Value: +{$edgePercent}%")
            ->line("Confidence: {$this->prediction->confidence_score}%")
            ->action('View Full Analysis', $this->getPredictionUrl())
            ->line('This alert was sent based on your preferences. Manage your alert settings in your account.');
    }

    public function toArray(object $notifiable): array
    {
        $game = $this->prediction->game;
        $baseData = [
            'sport' => $this->sport,
            'game_id' => $game->id,
            'expected_value' => $this->expectedValue,
            'recommendation' => $this->recommendation,
            'confidence' => $this->prediction->confidence_score,
            'game_time' => $game->game_date->toISOString(),
            'home_team' => $game->homeTeam->name ?? $game->homeTeam->school,
            'away_team' => $game->awayTeam->name ?? $game->awayTeam->school,
            'url' => $this->getPredictionUrl(),
        ];

        if ($this->template) {
            $data = $this->buildTemplateData($notifiable);
            $baseData['title'] = $this->template->renderPushTitle($data);
            $baseData['body'] = $this->template->renderPushBody($data);
        }

        return $baseData;
    }

    protected function buildTemplateData(object $notifiable): array
    {
        $game = $this->prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $edgePercent = round($this->expectedValue, 1);

        return [
            'user' => [
                'name' => $notifiable->name,
                'email' => $notifiable->email,
                'phone' => $notifiable->phone_number ?? '',
            ],
            'prediction' => [
                'sport' => $this->getSportName(),
                'game_description' => "{$awayTeam} @ {$homeTeam}",
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'pick_type' => $this->getPickType(),
                'recommended_pick' => $this->recommendation,
                'edge_percentage' => "+{$edgePercent}%",
                'confidence' => $this->prediction->confidence_score.'%',
                'odds' => $this->getOddsDisplay(),
                'game_time' => $game->game_date->format('g:i A'),
                'game_date' => $game->game_date->format('M j, Y'),
            ],
            'system' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_email' => config('mail.from.address'),
            ],
        ];
    }

    protected function getPickType(): string
    {
        $rec = strtolower($this->recommendation);

        if (Str::contains($rec, 'spread')) {
            return 'Spread';
        }

        if (Str::contains($rec, ['over', 'under'])) {
            return 'Over/Under';
        }

        if (Str::contains($rec, 'moneyline')) {
            return 'Moneyline';
        }

        return 'Pick';
    }

    protected function getOddsDisplay(): string
    {
        // Extract odds from recommendation if available
        if (preg_match('/[-+]\d+/', $this->recommendation, $matches)) {
            return $matches[0];
        }

        return 'N/A';
    }

    protected function getSportName(): string
    {
        return match (strtolower($this->sport)) {
            'nfl' => 'NFL',
            'nba' => 'NBA',
            'cbb' => 'NCAA Men\'s Basketball',
            'wcbb' => 'NCAA Women\'s Basketball',
            'mlb' => 'MLB',
            'cfb' => 'NCAA Football',
            'wnba' => 'WNBA',
            default => strtoupper($this->sport),
        };
    }

    protected function getPredictionUrl(): string
    {
        $sport = strtolower($this->sport);
        $gameId = $this->prediction->game_id;

        return url("/{$sport}/predictions/{$gameId}");
    }
}
